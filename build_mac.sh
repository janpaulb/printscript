#!/bin/bash
# build_mac.sh – Bouw PrintScript.app voor macOS
#
# Vereisten:
#   brew install python --cask libreoffice
#
# Gebruik:
#   chmod +x build_mac.sh
#   ./build_mac.sh

set -e

echo "▶  PrintScript Mac-app bouwen"
echo "──────────────────────────────"

# ── Controleer of we op macOS zitten ────────────────────────────────────────
if [[ "$OSTYPE" != "darwin"* ]]; then
  echo "❌  Dit script is alleen voor macOS."
  exit 1
fi

# ── Controleer Python 3 ──────────────────────────────────────────────────────
if ! command -v python3 &>/dev/null; then
  echo "❌  Python 3 niet gevonden. Installeer via: brew install python"
  exit 1
fi
PYTHON=$(command -v python3)
echo "✓  Python: $($PYTHON --version)"

# ── Controleer LibreOffice ───────────────────────────────────────────────────
LO_PATH=""
for candidate in \
    "$(command -v libreoffice 2>/dev/null)" \
    "/Applications/LibreOffice.app/Contents/MacOS/soffice" \
    "/opt/homebrew/bin/libreoffice" \
    "/usr/local/bin/libreoffice"; do
  if [[ -n "$candidate" && -f "$candidate" ]]; then
    LO_PATH="$candidate"
    break
  fi
done

if [[ -z "$LO_PATH" ]]; then
  echo "❌  LibreOffice niet gevonden."
  echo "    Installeer via: brew install --cask libreoffice"
  exit 1
fi
echo "✓  LibreOffice: $LO_PATH"

# ── Installeer Python-dependencies ──────────────────────────────────────────
echo ""
echo "📦  Python-packages installeren..."
$PYTHON -m pip install \
  flask \
  python-docx \
  lxml \
  pywebview \
  pyinstaller \
  --quiet --break-system-packages 2>/dev/null \
  || $PYTHON -m pip install flask python-docx lxml pywebview pyinstaller --quiet

echo "✓  Packages geïnstalleerd"

# ── Verwijder vorige build ───────────────────────────────────────────────────
echo ""
echo "🧹  Vorige build opruimen..."
rm -rf build dist

# ── Build ────────────────────────────────────────────────────────────────────
echo ""
echo "🔨  App bouwen (dit kan 1–3 minuten duren)..."
$PYTHON -m PyInstaller PrintScript.spec --noconfirm

# ── Klaar ────────────────────────────────────────────────────────────────────
echo ""
echo "──────────────────────────────"
echo "✅  Klaar!"
echo ""
echo "   De app staat in:  dist/PrintScript.app"
echo ""
echo "   Installeren:"
echo "   cp -r dist/PrintScript.app /Applications/"
echo ""
echo "   Of dubbelklik op dist/PrintScript.app om hem te testen."
