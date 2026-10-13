#!/usr/bin/env bash
# Baut das Installationspaket laut .claude/docs/release-layout.md.
#
#   scripts/build-release.sh X.Y.Z[-rcN]
#
# Ergebnis in dist-release/:
#   quitt-X.Y.Z.zip          Installationspaket (Inhalt = Installationsordner)
#   quitt-X.Y.Z.zip.sha256   Pruefsumme (Format sha256sum)
#   setup.php                Einrichtungsassistent (eigenes Release-Asset)
#
# Umgebungsvariablen:
#   QUITT_FRONTEND_DIST=<ordner>  fertiges Frontend verwenden statt zu bauen (Tests/CI)
#   COMPOSER="<befehl>"           Composer-Aufruf (Standard: backend/tools/composer.phar, sonst composer)
#
# Das Frontend wird in einen eigenen Ordner gebaut (vite --outDir), frontend/dist bleibt unberuehrt.
set -euo pipefail

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "Aufruf: $0 X.Y.Z[-rcN]" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/dist-release"
WORK="$OUT/.build-$VERSION"
PKG="$WORK/pkg"
TPL="$ROOT/scripts/release"
ZIP="$OUT/quitt-$VERSION.zip"

if [[ -z "${COMPOSER:-}" ]]; then
  if [[ -f "$ROOT/backend/tools/composer.phar" ]]; then
    COMPOSER="php $ROOT/backend/tools/composer.phar"
  else
    COMPOSER="composer"
  fi
fi

echo "== Quitt $VERSION bauen"
rm -rf "$WORK"
mkdir -p "$PKG"

# --- 1. Frontend -> Wurzel des Pakets ---------------------------------------
if [[ -n "${QUITT_FRONTEND_DIST:-}" ]]; then
  echo "-- Frontend: vorhandener Build aus $QUITT_FRONTEND_DIST"
  cp -R "$QUITT_FRONTEND_DIST"/. "$PKG"/
else
  echo "-- Frontend bauen"
  (
    cd "$ROOT/frontend"
    [[ -d node_modules ]] || npm ci --no-audit --no-fund
    npx vite build --outDir "$WORK/frontend" --emptyOutDir
  )
  cp -R "$WORK/frontend"/. "$PKG"/
fi
[[ -f "$PKG/index.html" ]] || { echo "Frontend-Build ohne index.html" >&2; exit 1; }

# --- 2. Backend -> app/ ------------------------------------------------------
echo "-- Backend kopieren"
mkdir -p "$PKG/app"
for d in src modules migrations lang resources bin; do
  cp -R "$ROOT/backend/$d" "$PKG/app/$d"
done
rm -f "$PKG/app/bin/dev-router.php"            # nur Entwicklung
cp "$ROOT/backend/composer.json" "$PKG/app/"
[[ -f "$ROOT/backend/composer.lock" ]] && cp "$ROOT/backend/composer.lock" "$PKG/app/"
printf '%s\n' "$VERSION" > "$PKG/app/VERSION"
cp "$TPL/app.htaccess" "$PKG/app/.htaccess"

echo "-- composer install --no-dev"
$COMPOSER install --working-dir="$PKG/app" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$PKG/app/composer.lock"

# --- 3. API-Einstieg -> api/ -------------------------------------------------
mkdir -p "$PKG/api"
cp "$TPL/api-index.php" "$PKG/api/index.php"
cp "$ROOT/backend/public/api/.htaccess" "$PKG/api/.htaccess"
cp "$ROOT/backend/public/api/openapi.json" "$PKG/api/openapi.json"
cp -R "$ROOT/backend/public/api/docs" "$PKG/api/docs"

# --- 4. Wurzel: .htaccess, version.json, Diagnose-Betrachter -----------------
cp "$TPL/root.htaccess" "$PKG/.htaccess"
php "$TPL/package.php" version-json "$PKG/app" "$VERSION" > "$PKG/version.json"
cp "$ROOT/tools-diagnose/diagnose.html" "$PKG/diagnose.html"

# Aufraeumen: keine Entwicklungsdateien, kein Datenordner
find "$PKG" -name '.DS_Store' -delete
rm -rf "$PKG/data" "$PKG/app/data" "$PKG/app/tests" "$PKG/app/tools"

# --- 5. Pruefen ---------------------------------------------------------------
for f in index.html sw.js manifest.webmanifest offline.html api/index.php api/.htaccess api/openapi.json \
         api/docs/index.html app/.htaccess app/vendor/autoload.php app/VERSION app/lang/de.php .htaccess version.json diagnose.html; do
  [[ -e "$PKG/$f" ]] || { echo "Fehlt im Paket: $f" >&2; exit 1; }
done
for f in "$PKG"/api/index.php $(find "$PKG/app/src" "$PKG/app/modules" "$PKG/app/migrations" -name '*.php'); do
  php -l "$f" > /dev/null
done
if [[ -d "$PKG/app/vendor/phpunit" ]]; then echo "Dev-Abhaengigkeiten im Paket" >&2; exit 1; fi

# --- 6. Zippen + Pruefsumme + setup.php --------------------------------------
echo "-- Paket schnueren"
php "$TPL/package.php" zip "$PKG" "$ZIP"
( cd "$OUT" && php "$TPL/package.php" sha256 "$ZIP" > "$ZIP.sha256" )
cp "$ROOT/setup/setup.php" "$OUT/setup.php"
rm -rf "$WORK"

echo "== Fertig:"
ls -l "$OUT"/quitt-"$VERSION".zip "$OUT"/quitt-"$VERSION".zip.sha256 "$OUT"/setup.php
cat "$ZIP.sha256"
