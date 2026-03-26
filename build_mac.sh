#!/bin/bash
# build_mac.sh – Bouw een volledig standalone PrintScript.app voor macOS.
#
# Wat dit script doet:
#   1. Controleer vereisten (macOS, Python 3, internetverbinding)
#   2. Installeer Python-dependencies (flask, python-docx, pywebview, pyinstaller, …)
#   3. Download de nieuwste stabiele LibreOffice voor jouw architectuur
#   4. Extraheer LibreOffice in bundled_libreoffice/ (tijdelijk, gitgenegeerd)
#   5. Bouw PrintScript.app via PyInstaller
#   6. Ruim de tijdelijke bestanden op
#
# Gebruik:
#   chmod +x build_mac.sh
#   ./build_mac.sh
#
# Resultaat: dist/PrintScript.app  (~320 MB, volledig standalone, LibreOffice GUI-onderdelen gestript)

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

# ── 2. Architectuur ──────────────────────────────────────────────────────────
step "Architectuur detecteren"
MACHINE=$(uname -m)
if [[ "$MACHINE" == "arm64" ]]; then
  LO_ARCH="aarch64"
else
  LO_ARCH="x86_64"
fi
ok "Architectuur: $MACHINE  →  LibreOffice-variant: $LO_ARCH"

# ── 3. Python-packages installeren ───────────────────────────────────────────
step "Python-packages installeren"
PACKAGES="flask python-docx lxml requests pywebview pyinstaller"
$PYTHON -m pip install $PACKAGES --quiet --break-system-packages 2>/dev/null \
  || $PYTHON -m pip install $PACKAGES --quiet
ok "Packages geïnstalleerd"

# ── 4. Nieuwste LibreOffice-versie ophalen ───────────────────────────────────
step "Nieuwste LibreOffice-versie opzoeken"
LO_INDEX="https://download.documentfoundation.org/libreoffice/stable/"
LO_VERSION=$(curl -s "$LO_INDEX" \
  | grep -oE 'href="[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?/"' \
  | grep -oE '[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?' \
  | sort -t. -k1,1n -k2,2n -k3,3n -k4,4n \
  | tail -1)
[[ -n "$LO_VERSION" ]] || err "Kon de nieuwste LibreOffice-versie niet ophalen. Controleer je internetverbinding."
ok "Nieuwste versie: LibreOffice $LO_VERSION"

# ── 5. LibreOffice downloaden ────────────────────────────────────────────────
DMG_NAME="LibreOffice_${LO_VERSION}_MacOS_${LO_ARCH}.dmg"
DMG_URL="https://download.documentfoundation.org/libreoffice/stable/${LO_VERSION}/mac/${LO_ARCH}/${DMG_NAME}"
DMG_PATH="/tmp/${DMG_NAME}"

if [[ -f "$DMG_PATH" ]]; then
  warn "DMG al aanwezig: $DMG_PATH  (bestaand bestand wordt hergebruikt)"
else
  step "LibreOffice downloaden (~300–500 MB)"
  echo "   URL: $DMG_URL"
  curl -L --progress-bar -o "$DMG_PATH" "$DMG_URL" \
    || err "Download mislukt. Controleer je internetverbinding."
  ok "Download klaar: $DMG_PATH"
fi

# ── 6. LibreOffice extracten ─────────────────────────────────────────────────
step "LibreOffice extracten naar bundled_libreoffice/"
MOUNT_POINT="/tmp/lo_mount_$$"
BUNDLE_DIR="$(pwd)/bundled_libreoffice"

# Opruimen van vorige run
rm -rf "$BUNDLE_DIR"
mkdir -p "$MOUNT_POINT"

echo "   DMG mounten…"
hdiutil attach "$DMG_PATH" -readonly -mountpoint "$MOUNT_POINT" -quiet -nobrowse

LO_APP="$MOUNT_POINT/LibreOffice.app"
[[ -d "$LO_APP" ]] || { hdiutil detach "$MOUNT_POINT" -quiet 2>/dev/null; err "LibreOffice.app niet gevonden in DMG."; }

echo "   Bestanden kopiëren (dit duurt even)…"
mkdir -p "$BUNDLE_DIR"
cp -r "$LO_APP/Contents" "$BUNDLE_DIR/Contents"

hdiutil detach "$MOUNT_POINT" -quiet

# ── LibreOffice-bundle verkleinen ─────────────────────────────────────────────
# Verwijder alles wat alleen nodig is voor een interactieve GUI-sessie.
# Dit scheelt doorgaans 180–230 MB in de uiteindelijke .app.
#
# Wat we verwijderen:
#   images_*.zip  – icoonthema's (6–8 zips × ~15–25 MB elk)
#   gallery/      – gallerij met clipart (~50 MB)
#   template/     – documentsjablonen
#   autocorr/     – autocorrectie-woordenboeken
#   extensions/   – optionele LibreOffice-extensies
#   basic/        – Basic-IDE
#   wizards/      – wizard-scripts
#   java/classes  – Java .jar bestanden (Java niet nodig voor conversie)
echo "   Onnodige LibreOffice-onderdelen verwijderen…"
find "$BUNDLE_DIR" -name 'images_*.zip' -delete 2>/dev/null || true
for _dir in gallery template autocorr extensions basic wizards; do
  find "$BUNDLE_DIR" -type d -name "$_dir" -exec rm -rf {} + 2>/dev/null || true
done
find "$BUNDLE_DIR" -type d -name 'classes' -path '*/java/*' -exec rm -rf {} + 2>/dev/null || true

STRIPPED_SIZE=$(du -sh "$BUNDLE_DIR" 2>/dev/null | cut -f1 || echo "?")
ok "LibreOffice gestript: ${STRIPPED_SIZE}"

# Verwijder quarantine-attribuut zodat de binary uitvoerbaar is
xattr -cr "$BUNDLE_DIR" 2>/dev/null || true

# Versie-markering opslaan (gebruikt door updater.py)
echo "$LO_VERSION" > "$BUNDLE_DIR/lo_version.txt"
ok "LibreOffice $LO_VERSION klaar in bundled_libreoffice/"

# ── 7. Vorige build opruimen ─────────────────────────────────────────────────
step "Vorige build opruimen"
rm -rf build dist
ok "Opgeruimd"

# ── 8. PyInstaller ───────────────────────────────────────────────────────────
step "App bouwen met PyInstaller (1–3 minuten)"
$PYTHON -m PyInstaller PrintScript.spec --noconfirm
ok "Build geslaagd"

# ── 8b. LibreOffice in de .app ad-hoc ondertekenen ────────────────────────────
# KRITISCH — moet NA PyInstaller, niet ervoor.
#
# PyInstaller kopieert alle data-bestanden naar de .app via shutil.copy2().
# Dit behoudt bestandsinhoud en permissies, maar GEEN extended attributes en
# mogelijk GEEN embedded code-signatures.
# Resultaat: de LibreOffice-bundle in de .app heeft een gebroken of ontbrekende
# signature, ook al was bundled_libreoffice/ al correct ondertekend.
#
# Waarom dit LibreOffice kapot maakt:
#   LibreOffice is notarized (Hardened Runtime + Library Validation). Wanneer
#   soffice via dlopen() libvclplug_svp.dylib probeert te laden, controleert
#   macOS de bundle-signature. Gebroken → dlopen geblokkeerd →
#   "no suitable windowing system found, exiting".
#
# Oplossing: ad-hoc re-signing op de definitieve locatie in de .app.
# PyInstaller 5 plaatst data in Contents/MacOS/, PyInstaller 6+ in _internal/.
step "LibreOffice in .app ad-hoc ondertekenen"
LO_IN_APP=$(find "dist/PrintScript.app" \
  -path "*/LibreOffice/Contents/MacOS/soffice" -type f 2>/dev/null | head -1)
if [[ -n "$LO_IN_APP" ]]; then
  # Go up three levels: MacOS/ → Contents/ → LibreOffice/
  LO_BUNDLE_IN_APP="$(dirname "$(dirname "$(dirname "$LO_IN_APP")")")"
  codesign --force --deep --sign - "$LO_BUNDLE_IN_APP" 2>/dev/null \
    && ok "Ad-hoc ondertekening klaar: $LO_BUNDLE_IN_APP" \
    || warn "codesign mislukt — conversie kan falen op macOS"
else
  warn "LibreOffice niet gevonden in dist/PrintScript.app — ondertekening overgeslagen"
fi

# ── 9. bundled_libreoffice/ opruimen ─────────────────────────────────────────
step "Tijdelijke bestanden opruimen"
rm -rf "$BUNDLE_DIR"
ok "bundled_libreoffice/ verwijderd"

# ── 10. Installatie-DMG aanmaken ──────────────────────────────────────────────
# Maakt een DMG met een Applications-alias zodat gebruikers alleen maar de app
# naar de map hoeven te slepen — geen Terminal nodig.
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
echo "   Testen:     open dist/PrintScript.app"
echo "   Installeren: open ${DMG_OUT}"
echo "   (sleep PrintScript naar Applications in het venster)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
