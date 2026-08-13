#!/usr/bin/env bash
# PrintScript — lokaal starten (macOS en Linux).
#
# Op macOS installeert Homebrew Pango in /opt/homebrew (Apple Silicon) of
# /usr/local (Intel), maar Python kijkt daar uit zichzelf niet.  Dit script
# wijst Python de weg, en als dat niet kan omdat de Python van Apple gebruikt
# wordt (macOS wist die instelling om veiligheidsredenen), bouwt het de
# virtuele omgeving opnieuw op met de Python van Homebrew.
set -euo pipefail

cd "$(dirname "$0")"
PORT_WAS_CHOSEN=0
[[ -n "${PORT:-}" ]] && PORT_WAS_CHOSEN=1
PORT="${PORT:-5000}"
VENV=".venv"
BREW_PREFIX=""

on_macos() { [[ "${OSTYPE:-}" == darwin* ]]; }

# ── Homebrew-bibliotheken vindbaar maken ─────────────────────────────────────

setup_library_path() {
  on_macos || return 0
  command -v brew >/dev/null 2>&1 || return 0

  BREW_PREFIX="$(brew --prefix)"
  export DYLD_FALLBACK_LIBRARY_PATH="$BREW_PREFIX/lib:${DYLD_FALLBACK_LIBRARY_PATH:-/usr/local/lib:/usr/lib}"
  export PKG_CONFIG_PATH="$BREW_PREFIX/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
}

# De Python van Apple (/usr/bin/python3) negeert DYLD_FALLBACK_LIBRARY_PATH:
# macOS wist die variabele bij het starten van systeemprogramma's.  Zo'n Python
# kan Homebrew's Pango dus nooit vinden.
python_is_apples() {
  local base
  base="$("$1" -c 'import sys; print(sys.base_prefix)' 2>/dev/null || echo '')"
  [[ "$base" == /System/* || "$base" == /Library/Developer/* || "$base" == /usr ]]
}

# Beste Python om de omgeving mee te bouwen: die van Homebrew als hij er is.
choose_python() {
  if on_macos && [[ -n "$BREW_PREFIX" ]]; then
    local candidate
    for candidate in "$BREW_PREFIX"/bin/python3.1[3210] "$BREW_PREFIX"/bin/python3; do
      [[ -x "$candidate" ]] && { echo "$candidate"; return; }
    done
  fi
  echo "python3"
}

# ── Omgeving opbouwen ────────────────────────────────────────────────────────

create_venv() {
  # Let op: geen leestekens buiten ASCII direct achter ${...} in dit script.
  # De bash 3.2 van macOS rekent zulke bytes tot de variabelenaam, waardoor
  # `set -u` afbreekt op een naam die niet bestaat.
  local interpreter="${1:-python3}"
  echo "Virtuele omgeving aanmaken met ${interpreter}..."
  rm -rf "$VENV"
  "$interpreter" -m venv "$VENV"
}

install_packages() {
  echo "Packages installeren..."
  "$VENV/bin/pip" install --quiet --upgrade pip
  "$VENV/bin/pip" install --quiet -r requirements.txt
}

weasyprint_works() {
  "$VENV/bin/python" - >/dev/null 2>&1 <<'PY'
import weasyprint
weasyprint.HTML(string='<p>PrintScript</p>').write_pdf()
PY
}

# ── Poort kiezen ─────────────────────────────────────────────────────────────
#
# Op macOS luistert AirPlay-ontvanger sinds Monterey op poort 5000.  Wie dat
# niet weet, ziet alleen "Address already in use" en is verder nergens.

port_is_free() {
  "$VENV/bin/python" - "$1" <<'PY' >/dev/null 2>&1
import socket, sys
probe = socket.socket()
probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
try:
    probe.bind(('0.0.0.0', int(sys.argv[1])))
except OSError:
    sys.exit(1)
finally:
    probe.close()
PY
}

port_holder() {
  command -v lsof >/dev/null 2>&1 || return 0
  lsof -nP -iTCP:"$1" -sTCP:LISTEN 2>/dev/null | awk 'NR == 2 { print $1 }'
}

resolve_port() {
  port_is_free "$PORT" && return 0

  local holder candidate
  holder="$(port_holder "$PORT")"

  # Zelf een poort gekozen? Dan niet stiekem een andere pakken.
  if [[ "$PORT_WAS_CHOSEN" == 1 ]]; then
    echo "Poort ${PORT} is bezet${holder:+ door ${holder}}."
    echo "Kies een andere:  PORT=5001 ./run.sh"
    exit 1
  fi

  for candidate in 5001 5002 5050 8000 8080 8081; do
    if port_is_free "$candidate"; then
      echo "Poort ${PORT} is bezet${holder:+ door ${holder}}; PrintScript neemt poort ${candidate}."
      case "$holder" in
        ControlCe*|AirPlay*|rapportd)
          echo "  Dat is de AirPlay-ontvanger van macOS. Uitzetten kan via"
          echo "  Systeeminstellingen > Algemeen > AirDrop en Handoff."
          ;;
      esac
      PORT="$candidate"
      return 0
    fi
  done

  echo "Poort ${PORT} is bezet en de alternatieven ook. Kies er zelf een:"
  echo "  PORT=1234 ./run.sh"
  exit 1
}

# ── Diagnose als het toch misgaat ────────────────────────────────────────────

explain_failure() {
  echo
  echo "WeasyPrint kan geen PDF maken. De echte foutmelding:"
  echo "----------------------------------------------------"
  { "$VENV/bin/python" - <<'PY'
import weasyprint
weasyprint.HTML(string='<p>x</p>').write_pdf()
PY
  } 2>&1 | tail -n 12 | sed 's/^/  /' || true
  echo "----------------------------------------------------"
  echo

  if ! on_macos; then
    echo "Installeer de Pango-bibliotheken:"
    echo "  sudo apt-get install libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b"
    return
  fi

  if ! command -v brew >/dev/null 2>&1; then
    echo "Homebrew is niet geïnstalleerd. Installeer het via https://brew.sh"
    echo "en daarna:  brew install pango libffi"
    return
  fi

  local python_arch library library_arch
  python_arch="$("$VENV/bin/python" -c 'import platform; print(platform.machine())' \
                 2>/dev/null || echo onbekend)"
  library="$(ls "$BREW_PREFIX"/lib/libpango-1.0*.dylib 2>/dev/null | head -n 1 || true)"

  if [[ -z "$library" ]]; then
    echo "Pango staat niet in $BREW_PREFIX/lib. Installeer het met:"
    echo "  brew install pango libffi"
    return
  fi

  library_arch="$(file -b "$library" 2>/dev/null || echo onbekend)"
  if [[ "$python_arch" == arm64 && "$library_arch" != *arm64* ]] ||
     [[ "$python_arch" == x86_64 && "$library_arch" != *x86_64* ]]; then
    echo "Architectuurverschil: Python draait als $python_arch, maar"
    echo "$library is $library_arch."
    echo
    echo "Herinstalleer Pango voor dezelfde architectuur:"
    echo "  arch -$python_arch brew reinstall pango libffi"
    return
  fi

  echo "Pango ($library_arch) en Python ($python_arch) passen bij elkaar, dus"
  echo "de bibliotheek wordt om een andere reden niet geladen. Probeer:"
  echo "  brew reinstall pango libffi glib"
  echo "  rm -rf $VENV && ./run.sh"
}

# ── Start ────────────────────────────────────────────────────────────────────

setup_library_path

command -v python3 >/dev/null 2>&1 || {
  echo "Python 3 niet gevonden."
  on_macos && echo "  Installeer met: brew install python" \
           || echo "  Installeer met: sudo apt-get install python3"
  exit 1
}

[[ -d "$VENV" ]] || create_venv "$(choose_python)"

"$VENV/bin/python" -c 'import flask, lxml, weasyprint' >/dev/null 2>&1 || install_packages

# Werkt WeasyPrint niet en gebruikt de omgeving de Python van Apple, dan is dat
# de oorzaak — opnieuw opbouwen met Homebrew's Python lost het op.
if ! weasyprint_works && on_macos && python_is_apples "$VENV/bin/python"; then
  HOMEBREW_PYTHON="$(choose_python)"
  if [[ "$HOMEBREW_PYTHON" != python3 ]]; then
    echo "De Python van Apple kan Homebrew-bibliotheken niet laden;"
    echo "de omgeving wordt opnieuw opgebouwd met $HOMEBREW_PYTHON."
    create_venv "$HOMEBREW_PYTHON"
    install_packages
  else
    echo "Installeer de Python van Homebrew — die kan Pango wél laden:"
    echo "  brew install python"
    echo "  rm -rf $VENV && ./run.sh"
    exit 1
  fi
fi

if ! weasyprint_works; then
  explain_failure
  echo
  echo "Liever niets installeren? Met Docker Desktop (https://docker.com/products/docker-desktop)"
  echo "draait alles zonder Python of Pango:  docker compose up"
  exit 1
fi

resolve_port

echo "PrintScript draait op http://localhost:${PORT}  (stoppen met Ctrl+C)"
PORT="$PORT" exec "$VENV/bin/python" app.py
