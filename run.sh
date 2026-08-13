#!/usr/bin/env bash
# PrintScript — lokaal starten (macOS en Linux).
set -euo pipefail

cd "$(dirname "$0")"
PORT="${PORT:-5000}"
VENV=".venv"

command -v python3 >/dev/null 2>&1 || {
  echo "Python 3 niet gevonden."
  [[ "$OSTYPE" == darwin* ]] && echo "  Installeer met: brew install python" \
                             || echo "  Installeer met: sudo apt-get install python3"
  exit 1
}

if [[ ! -d "$VENV" ]]; then
  echo "Virtuele omgeving aanmaken…"
  python3 -m venv "$VENV"
fi

# shellcheck disable=SC1091
source "$VENV/bin/activate"

if ! python -c "import flask, lxml, weasyprint" >/dev/null 2>&1; then
  echo "Packages installeren…"
  pip install --quiet --upgrade pip
  pip install --quiet -r requirements.txt
fi

if ! python -c "import weasyprint; weasyprint.HTML(string='<p>x</p>').write_pdf()" \
     >/dev/null 2>&1; then
  cat <<'EOF'
WeasyPrint kan geen PDF maken — de Pango-systeembibliotheken ontbreken.

  macOS:   brew install pango libffi
  Debian:  sudo apt-get install libpango-1.0-0 libpangoft2-1.0-0

Of gebruik Docker, dan hoef je niets te installeren:  docker compose up
EOF
  exit 1
fi

echo "PrintScript draait op http://localhost:$PORT  (stoppen met Ctrl+C)"
PORT="$PORT" python app.py
