#!/bin/bash
# build_mac.sh – Bouw een volledig standalone PrintScript.app voor macOS.
#
# Wat dit script doet:
#   1. Controleer vereisten (macOS, Python 3)
#   2. Installeer Python-dependencies
#   3. Bouw PrintScript.app via PyInstaller
#   4. Maak een installatie-DMG aan
#
# PDF-conversie gaat via WeasyPrint + mammoth (pure Python).
# LibreOffice is niet meer nodig — geen download, geen bundeling.
#
# Gebruik:
#   chmod +x build_mac.sh
#   ./build_mac.sh
#
# Resultaat: dist/PrintScript.app  (~30–50 MB, volledig standalone)

set -euo pipefail

# ── Kleuren ──────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓${NC}  $*"; }
warn() { echo -e "${YELLOW}!${NC}  $*"; }
err()  { echo -e "${RED}✗${NC}  $*" >&2; exit 1; }
step() { echo -e "\n▶  $*"; }

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "   PrintScript – standalone Mac-app bouwen"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── 0. Platformcheck ─────────────────────────────────────────────────────────
[[ "$OSTYPE" == "darwin"* ]] || err "Dit script is alleen voor macOS."

# ── 1. Python ────────────────────────────────────────────────────────────────
step "Python controleren"
PYTHON=$(command -v python3 2>/dev/null) || err "Python 3 niet gevonden. Installeer via: brew install python"
PY_VERSION=$($PYTHON --version 2>&1)
ok "$PY_VERSION  →  $PYTHON"

# ── 2. Architectuur detecteren ───────────────────────────────────────────────
step "Architectuur detecteren"
MACHINE=$(uname -m)
ok "Architectuur: $MACHINE"

# ── 3. Python-packages installeren ───────────────────────────────────────────
step "Python-packages installeren"
PACKAGES="flask python-docx lxml requests mammoth weasyprint pywebview pyinstaller"
$PYTHON -m pip install $PACKAGES --quiet --break-system-packages 2>/dev/null \
  || $PYTHON -m pip install $PACKAGES --quiet
ok "Packages geïnstalleerd"

# ── 4. Vorige build opruimen ──────────────────────────────────────────────────
step "Vorige build opruimen"
rm -rf build dist
ok "Opgeruimd"

# ── 5. PyInstaller ────────────────────────────────────────────────────────────
step "App bouwen met PyInstaller (1–2 minuten)"
$PYTHON -m PyInstaller PrintScript.spec --noconfirm
ok "Build geslaagd"

# ── 6. Installatie-DMG aanmaken ───────────────────────────────────────────────
step "Installatie-DMG aanmaken"
DMG_OUT="dist/PrintScript_${MACHINE}.dmg"
DMG_STAGING=$(mktemp -d)

cp -r dist/PrintScript.app "$DMG_STAGING/"
ln -s /Applications "$DMG_STAGING/Applications"

hdiutil create \
  -volname  "PrintScript" \
  -srcfolder "$DMG_STAGING" \
  -ov \
  -format   UDZO \
  "$DMG_OUT" \
  -quiet

rm -rf "$DMG_STAGING"
DMG_SIZE=$(du -sh "$DMG_OUT" 2>/dev/null | cut -f1 || echo "?")
ok "DMG aangemaakt: $DMG_OUT  (${DMG_SIZE})"

# ── Klaar ─────────────────────────────────────────────────────────────────────
APP_SIZE=$(du -sh dist/PrintScript.app 2>/dev/null | cut -f1 || echo "?")
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}✅  Klaar!${NC}"
echo ""
echo "   App:    dist/PrintScript.app      (${APP_SIZE})"
echo "   DMG:    ${DMG_OUT}  (${DMG_SIZE})"
echo ""
echo "   Testen:      open dist/PrintScript.app"
echo "   Installeren: open ${DMG_OUT}"
echo "   (sleep PrintScript naar Applications in het venster)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
