#!/bin/bash
# PrintScript opstartscript – werkt op macOS en Linux
set -e

PORT=${PORT:-5000}

# ── Controleer Python ────────────────────────────────────────────────────────
if ! command -v python3 &>/dev/null; then
  echo "❌  Python 3 niet gevonden."
  if [[ "$OSTYPE" == "darwin"* ]]; then
    echo "    Installeer via: brew install python"
  else
    echo "    Installeer via: sudo apt-get install python3"
  fi
  exit 1
fi

# ── Installeer Python-dependencies indien nodig ──────────────────────────────
python3 -c "import flask, docx, lxml, mammoth, weasyprint" 2>/dev/null || {
  echo "📦  Python-packages installeren..."
  pip3 install -r requirements.txt --quiet
}

# ── Start de server ──────────────────────────────────────────────────────────
echo "▶  PrintScript draait op http://localhost:$PORT"
echo "   Stop met Ctrl+C"
PORT=$PORT python3 app.py
