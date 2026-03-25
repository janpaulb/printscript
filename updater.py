"""
LibreOffice auto-updater for the PrintScript macOS app.

Storage layout
──────────────
~/Library/Application Support/PrintScript/
  LibreOffice/          ← active user-installed version (Contents/ of LO.app)
    Contents/
      MacOS/soffice
      ...
    lo_version.txt      ← plain-text version string, e.g. "24.8.4"
  LibreOffice_staged/   ← downloaded update, not yet active
    ...
  update_state.json     ← last-check timestamp, available version, etc.

Priority order for _find_libreoffice() (in processor.py):
  1. User library version  (~/Library/…/PrintScript/LibreOffice/)
  2. Bundled version       (sys._MEIPASS/LibreOffice/)
  3. System-installed      (PATH / /Applications/LibreOffice.app)
"""

import json
import os
import platform
import re
import shutil
import subprocess
import sys
import tempfile
import threading
from datetime import datetime, timedelta
from pathlib import Path

import requests


# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------

SUPPORT_DIR   = Path.home() / 'Library' / 'Application Support' / 'PrintScript'
LO_DIR        = SUPPORT_DIR / 'LibreOffice'          # active (updated) copy
STAGED_DIR    = SUPPORT_DIR / 'LibreOffice_staged'   # pending update
STATE_FILE    = SUPPORT_DIR / 'update_state.json'

UPDATE_INTERVAL = timedelta(days=7)

_VERSION_INDEX = 'https://download.documentfoundation.org/libreoffice/stable/'
_DMG_TEMPLATE  = (
    'https://download.documentfoundation.org/libreoffice/stable/'
    '{version}/mac/{arch}/LibreOffice_{version}_MacOS_{arch}.dmg'
)


# ---------------------------------------------------------------------------
# Architecture
# ---------------------------------------------------------------------------

def _arch() -> str:
    return 'aarch64' if platform.machine() == 'arm64' else 'x86_64'


# ---------------------------------------------------------------------------
# Version helpers
# ---------------------------------------------------------------------------

def _version_tuple(v: str) -> tuple:
    try:
        return tuple(int(x) for x in v.strip().split('.'))
    except ValueError:
        return (0,)


def _version_gt(a: str, b: str) -> bool:
    return _version_tuple(a) > _version_tuple(b)


def _max_version(versions: list[str]) -> str:
    return max(versions, key=_version_tuple)


# ---------------------------------------------------------------------------
# Version file locations
# ---------------------------------------------------------------------------

def _bundled_lo_root() -> Path | None:
    """Path to the LibreOffice Contents/ folder inside the PyInstaller bundle."""
    if getattr(sys, 'frozen', False):
        p = Path(sys._MEIPASS) / 'LibreOffice'  # type: ignore[attr-defined]
        if (p / 'Contents' / 'MacOS' / 'soffice').exists():
            return p
    return None


def get_bundled_version() -> str:
    root = _bundled_lo_root()
    if root:
        vf = root / 'lo_version.txt'
        if vf.exists():
            return vf.read_text().strip()
    return '0.0.0'


def get_active_version() -> str:
    vf = LO_DIR / 'lo_version.txt'
    if vf.exists():
        return vf.read_text().strip()
    return get_bundled_version()


def get_active_soffice() -> str | None:
    """
    Return the soffice binary path to use, in priority order.
    Returns None if none is found (caller falls back to PATH lookup).
    """
    # 1. User library (updated)
    user = LO_DIR / 'Contents' / 'MacOS' / 'soffice'
    if user.exists():
        return str(user)
    # 2. Bundled inside the .app
    root = _bundled_lo_root()
    if root:
        bundled = root / 'Contents' / 'MacOS' / 'soffice'
        if bundled.exists():
            return str(bundled)
    return None


# ---------------------------------------------------------------------------
# State persistence
# ---------------------------------------------------------------------------

def _load_state() -> dict:
    if STATE_FILE.exists():
        try:
            return json.loads(STATE_FILE.read_text())
        except Exception:
            pass
    return {}


def _save_state(state: dict) -> None:
    SUPPORT_DIR.mkdir(parents=True, exist_ok=True)
    STATE_FILE.write_text(json.dumps(state, default=str, indent=2))


# ---------------------------------------------------------------------------
# Version discovery
# ---------------------------------------------------------------------------

def _fetch_latest_version() -> str | None:
    """Fetch the latest stable LibreOffice version from the TDF download mirror."""
    try:
        r = requests.get(_VERSION_INDEX, timeout=15)
        r.raise_for_status()
        versions = re.findall(r'href="(\d+\.\d+\.\d+(?:\.\d+)?)/?"', r.text)
        return _max_version(versions) if versions else None
    except Exception:
        return None


# ---------------------------------------------------------------------------
# Apply a staged (already downloaded) update
# ---------------------------------------------------------------------------

def apply_staged_update() -> bool:
    """
    Move a staged update into the active location.
    Called once at app startup, before LibreOffice is used.
    Returns True if an update was applied.
    """
    state = _load_state()
    if not state.get('update_ready') or not STAGED_DIR.exists():
        return False

    try:
        if LO_DIR.exists():
            shutil.rmtree(LO_DIR)
        shutil.move(str(STAGED_DIR), str(LO_DIR))
        state['update_ready']    = False
        state['current_version'] = state.pop('staged_version', '')
        _save_state(state)
        return True
    except Exception:
        return False


# ---------------------------------------------------------------------------
# Background update check + download
# ---------------------------------------------------------------------------

def _notify(callback, payload: dict) -> None:
    if callback:
        try:
            callback(payload)
        except Exception:
            pass


def _download_and_stage(url: str, version: str, progress_cb) -> None:
    """Download the DMG, mount it, copy LibreOffice.app/Contents to STAGED_DIR."""
    import uuid as _uuid

    # Use SUPPORT_DIR for the temp DMG — /tmp on macOS is a RAM disk
    # that may be too small for a ~400 MB DMG.
    SUPPORT_DIR.mkdir(parents=True, exist_ok=True)
    dmg      = SUPPORT_DIR / f'LibreOffice_{version}.dmg'
    # Unique mountpoint avoids collisions with previous crashed sessions
    mnt_path = SUPPORT_DIR / f'lo_mount_{_uuid.uuid4().hex}'

    try:
        mnt_path.mkdir(parents=True, exist_ok=True)

        # ── Download ────────────────────────────────────────────────────────
        r = requests.get(url, stream=True, timeout=600)
        r.raise_for_status()
        total    = int(r.headers.get('content-length', 0))
        done     = 0
        last_pct = -1
        with open(dmg, 'wb') as fh:
            for chunk in r.iter_content(65536):
                fh.write(chunk)
                done += len(chunk)
                if total:
                    pct = round(done / total * 100)
                    if pct != last_pct:          # throttle: at most one msg per %
                        last_pct = pct
                        _notify(progress_cb, {
                            'status':  'downloading',
                            'percent': pct,
                            'version': version,
                        })

        # ── Mount DMG ───────────────────────────────────────────────────────
        _notify(progress_cb, {'status': 'extracting', 'version': version})

        subprocess.run(
            ['hdiutil', 'attach', str(dmg), '-readonly',
             '-mountpoint', str(mnt_path), '-quiet', '-nobrowse'],
            check=True,
        )
        try:
            lo_app = mnt_path / 'LibreOffice.app'
            if not lo_app.is_dir():
                raise RuntimeError('LibreOffice.app niet gevonden in DMG')

            # ── Copy to staged dir ─────────────────────────────────────────
            if STAGED_DIR.exists():
                shutil.rmtree(STAGED_DIR)
            STAGED_DIR.mkdir(parents=True)

            subprocess.run(
                ['cp', '-r', str(lo_app / 'Contents'), str(STAGED_DIR / 'Contents')],
                check=True,
            )

            # Remove macOS quarantine so the binary can be executed
            subprocess.run(['xattr', '-cr', str(STAGED_DIR)], check=False)

            # Write version marker
            (STAGED_DIR / 'lo_version.txt').write_text(version)

        finally:
            subprocess.run(['hdiutil', 'detach', str(mnt_path), '-quiet'], check=False)

    finally:
        # Clean up temp files regardless of success or failure
        try:
            dmg.unlink(missing_ok=True)
        except Exception:
            pass
        try:
            if mnt_path.exists():
                mnt_path.rmdir()
        except Exception:
            pass


def check_and_download_update(status_callback=None) -> None:
    """
    Entry point for the background update thread.
    Checks whether enough time has passed since the last check, fetches the
    latest version, and downloads + stages it if a newer version is available.
    """
    state = _load_state()

    # Respect the update interval — don't check on every launch
    last = state.get('last_check')
    if last:
        try:
            if datetime.now() - datetime.fromisoformat(last) < UPDATE_INTERVAL:
                # Still within interval; but notify UI of any pending ready update
                if state.get('update_ready'):
                    _notify(status_callback, {
                        'status':  'ready',
                        'version': state.get('staged_version', ''),
                    })
                return
        except Exception:
            pass

    _notify(status_callback, {'status': 'checking'})
    latest = _fetch_latest_version()
    state['last_check'] = datetime.now().isoformat()

    if not latest:
        _save_state(state)
        return

    current = get_active_version()
    state['latest_version']  = latest
    state['current_version'] = current

    if not _version_gt(latest, current):
        state['update_available'] = False
        _save_state(state)
        _notify(status_callback, {'status': 'up_to_date', 'version': current})
        return

    state['update_available'] = True
    _save_state(state)
    _notify(status_callback, {'status': 'downloading', 'percent': 0, 'version': latest})

    try:
        url = _DMG_TEMPLATE.format(version=latest, arch=_arch())
        _download_and_stage(url, latest, status_callback)
        state['update_ready']   = True
        state['staged_version'] = latest
        _save_state(state)
        _notify(status_callback, {'status': 'ready', 'version': latest})
    except Exception as exc:
        state['download_error'] = str(exc)
        _save_state(state)
        _notify(status_callback, {'status': 'error', 'error': str(exc)})


def start_background_check(status_callback=None) -> threading.Thread:
    """Spawn a daemon thread that runs check_and_download_update."""
    t = threading.Thread(
        target=check_and_download_update,
        args=(status_callback,),
        daemon=True,
        name='lo-update-checker',
    )
    t.start()
    return t
