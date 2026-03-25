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

# ── Controleer LibreOffice ───────────────────────────────────────────────────
LO_FOUND=false
for candidate in \
  "$(command -v libreoffice 2>/dev/null)" \
  "$(command -v soffice 2>/dev/null)" \
  "/Applications/LibreOffice.app/Contents/MacOS/soffice" \
  "/opt/homebrew/bin/libreoffice" \
  "/usr/local/bin/libreoffice"; do
  if [[ -n "$candidate" && -f "$candidate" ]]; then
    LO_FOUND=true
    break
  fi
done

if [[ "$LO_FOUND" == false ]]; then
  echo "❌  LibreOffice niet gevonden."
  if [[ "$OSTYPE" == "darwin"* ]]; then
    echo "    Installeer via: brew install --cask libreoffice"
  else
    echo "    Installeer via: sudo apt-get install libreoffice-writer"
  fi
  exit 1
fi

# ── Installeer Python-dependencies indien nodig ──────────────────────────────
python3 -c "import flask, docx, lxml" 2>/dev/null || {
  echo "📦  Python-packages installeren..."
  pip3 install -r requirements.txt --quiet
}

# ── Start de server ──────────────────────────────────────────────────────────
echo "▶  PrintScript draait op http://localhost:$PORT"
echo "   Stop met Ctrl+C"
PORT=$PORT python3 app.py
