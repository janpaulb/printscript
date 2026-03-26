"""
PrintScript – native macOS app entry point.

Startup sequence
────────────────
1. Apply any staged LibreOffice update (downloaded in a previous session).
2. Start Flask on a free port in a daemon thread.
3. Launch a background thread that checks for a newer LibreOffice version
   and downloads it silently. Status updates are pushed to the UI via a
   thread-safe queue that the /update-status endpoint drains.
4. Open a native WKWebView window (pywebview) pointing at localhost.
"""

import os
import queue
import socket
import sys
import threading
import time


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _resource_path(relative: str) -> str:
    if getattr(sys, 'frozen', False):
        base = sys._MEIPASS  # type: ignore[attr-defined]
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, relative)


def _find_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def _wait_for_port(port: int, timeout: float = 15.0) -> bool:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=0.5):
                return True
        except OSError:
            time.sleep(0.05)
    return False


def _remove_lo_quarantine() -> None:
    """
    Ensure the bundled LibreOffice can load its VCL plugin dylibs at runtime.

    Two problems can prevent dlopen() from loading libvclplug_svp.dylib:

    1. Quarantine (com.apple.quarantine)
       When the user installs the app from a downloaded DMG, macOS attaches
       quarantine to all files inside the bundle.  Quarantined dylibs cannot
       be loaded via dlopen().  We remove quarantine on every launch (fast,
       < 1 s) using xattr -cr.

    2. Broken code signature / Library Validation
       LibreOffice is notarized (Hardened Runtime + Library Validation).
       PyInstaller copies data files via shutil.copy2() which does NOT preserve
       extended attributes or embedded code signatures, so the LibreOffice
       bundle inside the .app may have an invalid/absent signature.
       Library Validation then blocks every dlopen() call.
       build_mac.sh already re-signs AFTER PyInstaller, but if the signature
       is still broken (first install, version update, etc.) we detect and fix
       it here.

    Performance:
       codesign --verify is fast (< 1 s).  codesign --force --deep is slow
       (~60 s for a stripped 300 MB LibreOffice bundle) but only runs ONCE —
       when the signature is actually broken.  Every subsequent launch the
       verify step passes and we skip the slow re-sign entirely.
    """
    import subprocess as _sp

    lo_dir = _resource_path('LibreOffice')
    if not os.path.isdir(lo_dir):
        return  # Not a bundled build (dev run without build_mac.sh)

    # 1. Remove quarantine — always, fast (< 1 s)
    _sp.run(['xattr', '-cr', lo_dir], check=False, capture_output=True)

    # 2. Check signature — skip the slow re-sign if already valid
    soffice = os.path.join(lo_dir, 'Contents', 'MacOS', 'soffice')
    if os.path.isfile(soffice):
        check = _sp.run(
            ['codesign', '-v', '--no-strict', soffice],
            capture_output=True,
        )
        if check.returncode == 0:
            return  # Signature valid — nothing more to do

    # 3. Signature broken or absent — re-sign ad-hoc (runs once, ~60 s)
    _sp.run(
        ['codesign', '--force', '--deep', '--sign', '-', lo_dir],
        check=False, capture_output=True,
    )


def _error_html(message: str) -> str:
    safe = (message
            .replace('&', '&amp;')
            .replace('<', '&lt;')
            .replace('>', '&gt;'))
    return f"""<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <style>
    *{{box-sizing:border-box;margin:0;padding:0}}
    body{{font-family:-apple-system,sans-serif;background:#1a1d27;color:#e8eaf6;
         display:flex;align-items:center;justify-content:center;
         min-height:100vh;padding:32px;text-align:center}}
    .box{{max-width:460px;display:flex;flex-direction:column;gap:16px}}
    .icon{{font-size:3rem}}
    h2{{font-size:1.2rem;font-weight:600}}
    pre{{background:#0f1117;border-radius:8px;padding:16px;text-align:left;
         font-size:.82rem;white-space:pre-wrap;color:#f4a261;line-height:1.6;
         border:1px solid #2e3248}}
    p{{color:#8b90b0;font-size:.9rem;line-height:1.5}}
  </style>
</head>
<body>
  <div class="box">
    <div class="icon">&#9888;&#65039;</div>
    <h2>PrintScript kan niet starten</h2>
    <pre>{safe}</pre>
    <p>Installeer de ontbrekende software en start PrintScript opnieuw.</p>
  </div>
</body>
</html>"""


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

# Global queue: updater thread pushes dicts; /update-status endpoint drains it
update_queue: queue.Queue = queue.Queue()


def main() -> None:
    import webview  # noqa: PLC0415

    # ── 0. Remove quarantine from bundled LibreOffice ────────────────────────
    # When the user downloads and installs our DMG, macOS may attach quarantine
    # extended attributes to all files inside the bundle — including the soffice
    # binary and its dylibs. Even if removed at build time (build_mac.sh), the
    # attributes can be re-applied by the OS during DMG extraction or app copy.
    # A quarantined dylib cannot be loaded by dlopen(), which would cause
    # "no suitable windowing system found" on the very first conversion.
    # We also re-sign ad-hoc here if the signature was somehow lost.
    _remove_lo_quarantine()

    # ── 1. Apply any pending LibreOffice update ──────────────────────────────
    try:
        from updater import apply_staged_update
        apply_staged_update()
    except Exception:
        pass  # Never block startup for an update failure

    # ── 2. Verify LibreOffice is available ───────────────────────────────────
    try:
        from processor import _find_libreoffice
        _find_libreoffice()
    except RuntimeError as exc:
        webview.create_window(
            'PrintScript – Fout',
            html=_error_html(str(exc)),
            width=540,
            height=380,
            resizable=False,
        )
        webview.start()
        sys.exit(1)

    # ── 3. Start Flask ───────────────────────────────────────────────────────
    os.environ['PRINTSCRIPT_BASE_DIR'] = _resource_path('.')
    port = _find_free_port()

    from app import app as flask_app  # noqa: PLC0415
    flask_app.config['UPDATE_QUEUE'] = update_queue

    def _run_flask() -> None:
        flask_app.run(
            host='127.0.0.1',
            port=port,
            use_reloader=False,
            threaded=True,
        )

    threading.Thread(target=_run_flask, daemon=True).start()

    if not _wait_for_port(port):
        webview.create_window(
            'PrintScript – Fout',
            html=_error_html('Kon de interne server niet starten.'),
            width=540,
            height=280,
            resizable=False,
        )
        webview.start()
        sys.exit(1)

    # ── 4. Start background LibreOffice update check ─────────────────────────
    try:
        from updater import start_background_check
        start_background_check(status_callback=update_queue.put)
    except Exception:
        pass

    # ── 5. Open the native window ─────────────────────────────────────────────
    webview.create_window(
        'PrintScript',
        url=f'http://127.0.0.1:{port}',
        width=720,
        height=820,
        resizable=True,
        min_size=(620, 680),
    )
    webview.start()


if __name__ == '__main__':
    main()
