#!/usr/bin/env bash
# Maakt printscript-php.zip: de map die je zo op een webserver zet.
set -euo pipefail

cd "$(dirname "$0")"
BUILD="build/printscript"
SLIM="${SLIM:-1}"

command -v composer >/dev/null 2>&1 || {
  echo "Composer niet gevonden. Installeer het via https://getcomposer.org"
  exit 1
}

echo "Bouwen..."
rm -rf build
mkdir -p "$BUILD"

cp -r index.php .htaccess assets src composer.json "$BUILD/"
cp README.md "$BUILD/LEESMIJ.md"

composer install --no-dev --prefer-dist --optimize-autoloader \
                 --no-interaction --quiet --working-dir="$BUILD"

if [[ "$SLIM" == "1" ]]; then
  # mPDF levert lettertypen mee voor elk schrift ter wereld. Voor een
  # Nederlands script is de DejaVu-familie genoeg; dat scheelt tientallen MB's.
  FONTS="$BUILD/vendor/mpdf/mpdf/ttfonts"
  if [[ -d "$FONTS" ]]; then
    find "$FONTS" -type f ! -name 'DejaVu*' ! -name 'Free*' -delete
  fi
fi

find "$BUILD" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true

( cd build && zip -qr ../printscript-php.zip printscript )
echo "Klaar: printscript-php.zip ($(du -h printscript-php.zip | cut -f1))"
echo "Pak hem uit en zet de inhoud in de webmap van je server."
