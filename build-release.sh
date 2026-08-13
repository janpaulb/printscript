#!/usr/bin/env bash
# Maakt printscript-php.zip: precies de map die je op een webserver zet.
#
# Composer is hier niet voor nodig — vendor/ staat in de repo, want niet elke
# hosting heeft een shell.
set -euo pipefail

cd "$(dirname "$0")"
BUILD="build/printscript"

[[ -f vendor/autoload.php ]] || {
  echo "vendor/ ontbreekt. Draai eerst:  composer install --no-dev"
  exit 1
}

echo "Bouwen..."
rm -rf build printscript-php.zip
mkdir -p "$BUILD"

cp -r index.php .htaccess assets src vendor composer.json "$BUILD/"
cp README.md "$BUILD/LEESMIJ.md"

command -v zip >/dev/null 2>&1 || { echo "zip niet gevonden."; exit 1; }
( cd build && zip -qr ../printscript-php.zip printscript )

echo "Klaar: printscript-php.zip ($(du -h printscript-php.zip | cut -f1))"
echo "Pak hem uit en zet de inhoud in de webmap van je server."
