#!/usr/bin/env bash
# End-to-End-Test fuer setup.php (REQ-060) mit dem echten Installationspaket.
#
#   scripts/e2e-setup.sh [--docker]
#
# Ablauf (PHP-Entwicklungsserver, Unterordner /kasse):
#   1. Paket bauen (scripts/build-release.sh; vorhandenes Paket per E2E_PACKAGE=<zip> wiederverwenden)
#   2. Leerer Ordner + setup.php + quitt-X.zip(.sha256) -> Lokal-Modus, kein Download
#   3. Setup per curl durchspielen (Check, Art, Programm, KI-Verbindungstest, Einrichten, Code, Abschluss)
#   4. API: /health, /system/info, Login mit PIN; app/ und data/ gesperrt; setup.php weg und gesperrt
#   5. Zweite Installation (/kasse2) per "Sicherung einspielen" mit der Sicherung aus der ersten
# --docker zusaetzlich:
#   6. Apache (php:8.3-apache + zip + mod_rewrite): Systemcheck mit echten .htaccess-Proben und Einrichtung
#   7. PHP 7.4: setup.php meldet verstaendlich "zu alt" statt eines Syntaxfehlers
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/quitt-e2e.XXXXXX")"
DOCKER=0
[[ "${1:-}" == "--docker" ]] && DOCKER=1
PORT=$((18000 + RANDOM % 1000))
SERVER_PID=""
DOCK_PID=""
PIN=2468

cleanup() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  [[ -n "$DOCK_PID" ]] && kill "$DOCK_PID" 2>/dev/null || true
  [[ -n "${APACHE_ID:-}" ]] && docker rm -f "$APACHE_ID" >/dev/null 2>&1 || true
  [[ "${E2E_KEEP:-0}" == "1" ]] && echo "Arbeitsordner: $WORK" || rm -rf "$WORK"
}
trap cleanup EXIT

fail() { echo "FEHLER: $*" >&2; exit 1; }
ok() { echo "  ok  $*"; }
expect() { # expect <text> <datei> <beschreibung>
  grep -q -- "$1" "$2" || { echo "--- Antwort ($2):" >&2; sed -e 's/<[^>]*>/ /g' "$2" | tr -s ' ' | head -c 3000 >&2; echo >&2; fail "$3: '$1' fehlt"; }
}

# --- 1. Paket -----------------------------------------------------------------
if [[ -n "${E2E_PACKAGE:-}" ]]; then
  PKG="$E2E_PACKAGE"
else
  VERSION="${E2E_VERSION:-0.0.1-e2e}"
  "$ROOT/scripts/build-release.sh" "$VERSION" > "$WORK/build.log" 2>&1 || { tail -30 "$WORK/build.log"; fail "Build"; }
  PKG="$ROOT/dist-release/quitt-$VERSION.zip"
fi
VERSION="$(basename "$PKG" .zip)"; VERSION="${VERSION#quitt-}"
[[ -f "$PKG" && -f "$PKG.sha256" ]] || fail "Paket fehlt: $PKG"
echo "== E2E mit quitt-$VERSION"

# --- Hilfen fuer einen Setup-Durchlauf ----------------------------------------
# setup_run <basis-url> <ordner> <modus new|restore> [sicherung.zip]
setup_run() {
  local url="$1" dir="$2" mode="$3" backup="${4:-}" jar="$2.cookies" out="$2.html" csrf
  local S="$url/setup.php"
  curl -sS -o "$out" "$S"; expect "Systemcheck" "$out" "Check-Seite"; expect "Einrichtung starten" "$out" "Check ohne Fehler"
  expect "PHP-Version" "$out" "Check-Liste"
  ok "Systemcheck ohne rote Punkte"
  curl -sS "$S?diagnose=1" | grep -q '"format": "quitt-diagnostics/1"' || fail "Diagnosepaket aus setup.php"
  curl -sS -o /dev/null -c "$jar" -b "$jar" -d action=start "$S"
  curl -sS -o "$out" -c "$jar" -b "$jar" "$S"; expect "Wie möchtest du starten" "$out" "Art-Auswahl"
  csrf="$(grep -o 'name="csrf" value="[0-9a-f]*"' "$out" | head -1 | sed 's/.*value="//;s/"//')"
  [[ -n "$csrf" ]] || fail "kein CSRF-Token"
  # fremder Browser (ohne Cookie) wird abgewiesen
  local code; code="$(curl -sS -o /dev/null -w '%{http_code}' "$S")"; [[ "$code" == "409" ]] || fail "zweiter Browser nicht abgewiesen ($code)"
  ok "zweiter Browser abgewiesen (409)"
  # falsches CSRF-Token aendert nichts
  curl -sS -o /dev/null -c "$jar" -b "$jar" -d "action=mode&mode=$mode&csrf=falsch" "$S"
  curl -sS -o "$out" -c "$jar" -b "$jar" "$S"; expect "Wie möchtest du starten" "$out" "CSRF-Schutz"
  curl -sS -o /dev/null -c "$jar" -b "$jar" -d "action=mode&mode=$mode&csrf=$csrf" "$S"
  curl -sS -o "$out" -c "$jar" -b "$jar" "$S"; expect "${EXPECT_SOURCE:-Es wird nichts heruntergeladen}" "$out" "Paketquelle"
  ok "Paketquelle erkannt (${EXPECT_SOURCE:-Lokal-Modus})"
  curl -sS -o "$out" -c "$jar" -b "$jar" -d "action=package&csrf=$csrf" "$S"
  [[ -f "$dir/app/vendor/autoload.php" && -f "$dir/index.html" && -f "$dir/api/index.php" ]] || { cat "$out" | head -c 2000; fail "Paket nicht entpackt"; }
  ok "Paket geprueft und entpackt"
  curl -sS -o "$out" -c "$jar" -b "$jar" "$S"
  if [[ "$mode" == "new" ]]; then
    expect "Richte deine Kasse ein" "$out" "Einrichtungsformular"
    # Verbindungstest ueber den App-Dienst (Ollama an einer toten Adresse)
    curl -sS -o "$out" -c "$jar" -b "$jar" --data-urlencode "group_name=E2E Kasse" -d "recognition_mode=ai&ai_provider=ollama&ai_base_url=http://127.0.0.1:9&action=test_ai&csrf=$csrf" "$S"
    expect "nicht erreichbar" "$out" "KI-Verbindungstest"
    ok "KI-Verbindungstest liefert verstaendliche Meldung"
    # PIN-Wiederholung falsch
    curl -sS -o "$out" -c "$jar" -b "$jar" --data-urlencode "group_name=E2E Kasse" -d "pin=$PIN&pin2=1111&recognition_mode=local&action=install&csrf=$csrf" "$S"
    expect "stimmen nicht überein" "$out" "PIN-Pruefung"
    curl -sS -o /dev/null -c "$jar" -b "$jar" --data-urlencode "group_name=E2E Kasse" -d "pin=$PIN&pin2=$PIN&recognition_mode=local&action=install&csrf=$csrf" "$S"
    curl -sS -o "$out" -c "$jar" -b "$jar" "$S"; expect "Dein Wiederherstellungscode" "$out" "Code-Seite"
    RECOVERY="$(grep -o '<div class="code"[^>]*>[^<]*' "$out" | sed 's/.*>//')"
    [[ ${#RECOVERY} -ge 8 ]] || fail "Wiederherstellungscode fehlt"
    ok "Wiederherstellungscode angezeigt ($RECOVERY)"
  else
    expect "Sicherung einspielen" "$out" "Upload-Formular"
    curl -sS -o /dev/null -c "$jar" -b "$jar" -F action=restore -F "csrf=$csrf" -F "file=@$backup;type=application/zip" "$S"
    curl -sS -o "$out" -c "$jar" -b "$jar" "$S"; expect "Sicherung eingespielt" "$out" "Restore-Ergebnis"
    expect "<strong>1</strong> Buchungen" "$out" "Restore-Anzahl"
    ok "Sicherung eingespielt"
  fi
  # Waehrend der Code-Anzeige: andere Aktionen verweigert
  curl -sS -o "$out" -c "$jar" -b "$jar" -d "action=install&csrf=$csrf" "$S"
  curl -sS -o "$out" -c "$jar" -b "$jar" -d "action=finish&csrf=$csrf" "$S"
  expect "Quitt ist eingerichtet" "$out" "Abschluss"
  expect "Zum Home-Bildschirm" "$out" "Homescreen-Hinweis"
}

api() { # api <methode> <url> [json] [token]
  local args=(-sS -X "$1" "$2" -H 'Content-Type: application/json')
  [[ -n "${3:-}" ]] && args+=(-d "$3")
  [[ -n "${4:-}" ]] && args+=(-H "Authorization: Bearer $4")
  curl "${args[@]}"
}
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_bool($d)?($d?"true":"false"):(is_array($d)?json_encode($d):$d);' "$1"; }

check_install() { # check_install <basis-url> <ordner> <basis-pfad> [lock] [datenordner]  (lock = Docker-Modus: Datei bleibt, gesperrt)
  local url="$1" dir="$2" base="$3" lockmode="${4:-}" data="${5:-$2/data}" h token
  if [[ "$lockmode" == "lock" ]]; then
    [[ -e "$dir/setup.php" ]] || fail "Docker-Modus: setup.php sollte bleiben"
  else
    [[ ! -e "$dir/setup.php" ]] || fail "setup.php nicht geloescht"
  fi
  [[ -f "$data/.setup-lock" ]] || fail "Sperrdatei fehlt"
  ok "setup.php $([[ "$lockmode" == "lock" ]] && echo "bleibt gesperrt (Docker)" || echo "geloescht"), Sperrdatei angelegt"
  h="$(api GET "$url/api/v1/health")"; [[ "$(echo "$h" | json ok)" == "true" && "$(echo "$h" | json version)" == "$VERSION" ]] || fail "/health: $h"
  ok "/health ok, Version $VERSION"
  h="$(api GET "$url/api/v1/system/info")"
  [[ "$(echo "$h" | json installed)" == "true" && "$(echo "$h" | json base_path)" == "$base/" ]] || fail "/system/info: $h"
  ok "/system/info installiert, base_path $base/"
  TOKEN="$(api POST "$url/api/v1/auth/login" "{\"pin\":\"$PIN\"}" | json token)"
  [[ ${#TOKEN} -ge 20 ]] || fail "Login fehlgeschlagen"
  ok "Login mit PIN"
  [[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/")" == "200" ]] || fail "App-Startseite"
  for p in app/VERSION data/quitt.sqlite data/schutz-test.txt; do
    [[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/$p")" == "403" ]] || fail "$p ist abrufbar"
  done
  ok "app/ und data/ gesperrt"
  cp "$ROOT/setup/setup.php" "$dir/setup.php"
  [[ "$(curl -sS -o "$dir.refuse.html" -w '%{http_code}' "$url/setup.php")" == "403" ]] || fail "setup.php nach Installation nicht gesperrt"
  [[ "$(curl -sS -o /dev/null -w '%{http_code}' -d action=start "$url/setup.php")" == "403" ]] || fail "POST nach Installation nicht gesperrt"
  expect "bereits eingerichtet" "$dir.refuse.html" "Sperrseite"
  rm -f "$dir/setup.php"
  ok "erneut hochgeladene setup.php verweigert jeden Aufruf"
}

# --- 2.-4. Neuinstallation im Unterordner /kasse ------------------------------
DOC="$WORK/www"; mkdir -p "$DOC/kasse" "$DOC/kasse2"
for d in kasse kasse2; do cp "$ROOT/setup/setup.php" "$PKG" "$PKG.sha256" "$DOC/$d/"; done
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$DOC" "$ROOT/scripts/release/dev-router.php" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.1; done

echo "-- Neuinstallation (php -S, /kasse)"
# Hoster-Platzhalter im Zielordner: Check warnt, "Aufraeumen" benennt um (nichts geloescht)
echo '<h1>Platzhalter</h1>' > "$DOC/kasse/index.htm"
curl -sS -o "$WORK/clean.html" "http://127.0.0.1:$PORT/kasse/setup.php"
expect "Ordner frei" "$WORK/clean.html" "Check Ordner frei"; expect "index.htm" "$WORK/clean.html" "Platzhalter gemeldet"; expect "Aufräumen" "$WORK/clean.html" "Aufraeumen-Knopf"
expect "Einrichtung starten" "$WORK/clean.html" "Warnung blockiert nicht"
ok "Platzhalter index.htm gemeldet (gelb, Aufraeumen angeboten)"
curl -sS -o "$WORK/clean.html" -d action=cleanup "http://127.0.0.1:$PORT/kasse/setup.php"
[[ -f "$DOC/kasse/index.htm.vor-quitt" && ! -e "$DOC/kasse/index.htm" ]] || fail "Aufraeumen: index.htm nicht umbenannt"
expect "Keine störenden Dateien" "$WORK/clean.html" "Check nach Aufraeumen"
ok "Aufraeumen: index.htm -> index.htm.vor-quitt, Check wieder gruen"
setup_run "http://127.0.0.1:$PORT/kasse" "$DOC/kasse" new
check_install "http://127.0.0.1:$PORT/kasse" "$DOC/kasse" /kasse
grep -q '"channel":"setup"' "$DOC"/kasse/data/logs/quitt-*.log || fail "Setup-Protokoll fehlt"
! grep -q "$RECOVERY" "$DOC"/kasse/data/logs/quitt-*.log || fail "Wiederherstellungscode im Protokoll"
! grep -q "\"$PIN\"" "$DOC"/kasse/data/logs/quitt-*.log || fail "PIN im Protokoll"
ok "Setup protokolliert, ohne PIN/Code"
[[ ! -e "$DOC/kasse/quitt-$VERSION.zip" ]] || fail "Paket nach Abschluss nicht entfernt"

# Buchung anlegen und Sicherung holen
api POST "http://127.0.0.1:$PORT/kasse/api/v1/sync" '{"entries":[{"id":"00000001-0000-4000-8000-000000000001","type":"out","date":"2026-10-01","amount_cents":1234,"purpose":null,"reason":"E2E","category":"Test","item":"Kuchen","receipt_id":null,"created_at":"2026-10-01T10:00:00.000Z","updated_at":"2026-10-01T10:00:00.000Z","deleted_at":null}]}' "$TOKEN" > /dev/null
DL="$(api POST "http://127.0.0.1:$PORT/kasse/api/v1/downloads" '{"target":"backup","params":{}}' "$TOKEN" | json url)"
curl -sS -o "$WORK/sicherung.zip" "http://127.0.0.1:$PORT/kasse/$DL"
unzip -l "$WORK/sicherung.zip" | grep -q database.sqlite || fail "Sicherung ungueltig"
ok "Sicherung mit 1 Buchung heruntergeladen"

# --- 5. Wiederherstellung in /kasse2 ------------------------------------------
echo "-- Sicherung einspielen (php -S, /kasse2)"
setup_run "http://127.0.0.1:$PORT/kasse2" "$DOC/kasse2" restore "$WORK/sicherung.zip"
check_install "http://127.0.0.1:$PORT/kasse2" "$DOC/kasse2" /kasse2
[[ "$(api GET "http://127.0.0.1:$PORT/kasse2/api/v1/sync?since=0" "" "$TOKEN" | grep -c Kuchen)" == "1" ]] || fail "Buchung nach Wiederherstellung fehlt"
ok "Buchung nach Wiederherstellung vorhanden"

# --- Docker-Modus: Programm bereits entpackt, Daten ausserhalb, Sperrdatei statt Loeschen ---
echo "-- Docker-Modus (entpackt, QUITT_DATA_DIR, php -S)"
DPORT=$((PORT + 1000))
mkdir -p "$WORK/dock/kasse" "$WORK/dock-data"
unzip -q "$PKG" -d "$WORK/dock/kasse"; cp "$ROOT/setup/setup.php" "$WORK/dock/kasse/"
QUITT_DOCKER=1 QUITT_DATA_DIR="$WORK/dock-data" PHP_CLI_SERVER_WORKERS=4 \
  php -S "127.0.0.1:$DPORT" -t "$WORK/dock" "$ROOT/scripts/release/dev-router.php" > "$WORK/server-dock.log" 2>&1 &
DOCK_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$DPORT/" && break; sleep 0.1; done
EXPECT_SOURCE="liegt bereits in diesem Ordner" setup_run "http://127.0.0.1:$DPORT/kasse" "$WORK/dock/kasse" new
check_install "http://127.0.0.1:$DPORT/kasse" "$WORK/dock/kasse" /kasse lock "$WORK/dock-data"
[[ -f "$WORK/dock-data/quitt.sqlite" && ! -e "$WORK/dock/kasse/data" ]] || fail "Docker-Modus: Daten nicht im externen Ordner"
[[ "$(api POST "http://127.0.0.1:$DPORT/kasse/api/v1/update/apply" "" "$TOKEN" | json error.code)" == "docker_managed" ]] || fail "Docker-Modus: Update nicht 409"
ok "Docker-Modus: Daten extern, Sperrdatei, /update/apply -> docker_managed"
kill "$DOCK_PID"; DOCK_PID=""

# --- Update in der App (REQ-061) gegen die echte Installation /kasse -----------
echo "-- In-App-Update (UpdateService der installierten Fassung, GitHub-Attrappe)"
K="http://127.0.0.1:$PORT/kasse"
TOKEN1="$(api POST "$K/api/v1/auth/login" "{\"pin\":\"$PIN\"}" | json token)"
NEXT="9.9.9"
r="$(php "$ROOT/scripts/release/e2e-update.php" "$DOC/kasse" "$PKG" "$NEXT")" || fail "Update: $r"
[[ "$(api GET "$K/api/v1/health" | json version)" == "$NEXT" ]] || fail "Update: Version nicht $NEXT"
[[ "$(api GET "$K/api/v1/sync?since=0" "" "$TOKEN1" | grep -c Kuchen)" == "1" ]] || fail "Update: Buchung fehlt / Anmeldung verloren"
ls "$DOC"/kasse/data/backups/ | grep -q before_update || fail "Update: keine Sicherung vorher"
[[ -z "$(ls -d "$DOC"/kasse/.quitt-update-* 2>/dev/null)" ]] || fail "Update: Temp-Ordner bleibt liegen"
ok "Update $VERSION -> $NEXT: Buchung und Anmeldung erhalten, Sicherung angelegt"
r="$(php "$ROOT/scripts/release/e2e-update.php" "$DOC/kasse" "$PKG" 9.9.10 fail)" && fail "Update mit Fehler meldet Erfolg"
echo "$r" | grep -q 'update.err_migration' || fail "Update-Fehler: $r"
[[ "$(api GET "$K/api/v1/health" | json version)" == "$NEXT" ]] || fail "Rollback: Version nicht $NEXT"
[[ "$(api GET "$K/api/v1/sync?since=0" "" "$TOKEN1" | grep -c Kuchen)" == "1" ]] || fail "Rollback: Buchung fehlt"
[[ ! -e "$DOC/kasse/app/migrations/0901_e2e_fail.php" ]] || fail "Rollback: neue Dateien liegen noch"
ok "Update mit fehlschlagender Migration: alter Stand ($NEXT) laeuft weiter"

kill "$SERVER_PID"; SERVER_PID=""

if [[ "$DOCKER" == "1" ]]; then
  # --- 6. Apache mit echten .htaccess-Regeln ----------------------------------
  echo "-- Apache (Docker, /kasse)"
  IMG=quitt-e2e-apache:8.3
  if ! docker image inspect "$IMG" > /dev/null 2>&1; then
    printf '%s\n' 'FROM php:8.3-apache' \
      'RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev && docker-php-ext-install zip && rm -rf /var/lib/apt/lists/*' \
      'RUN a2enmod rewrite headers && sed -ri "s/AllowOverride None/AllowOverride All/" /etc/apache2/apache2.conf' \
      | docker build -q -t "$IMG" - > /dev/null
  fi
  ADOC="$WORK/apache"; mkdir -p "$ADOC/kasse"
  cp "$ROOT/setup/setup.php" "$PKG" "$PKG.sha256" "$ADOC/kasse/"
  chmod -R 777 "$ADOC"
  APORT=$((19000 + RANDOM % 1000))
  APACHE_ID="$(docker run -d -p "127.0.0.1:$APORT:80" -v "$ADOC:/var/www/html" "$IMG")"
  for _ in $(seq 1 100); do curl -s -o /dev/null "http://127.0.0.1:$APORT/" && break; sleep 0.2; done
  # Ohne HTTP-Rueckruf von innen nach 127.0.0.1:$APORT: Host-Header zeigt auf den Container-Port 80
  curl -sS -o "$WORK/a.html" -H "Host: localhost" "http://127.0.0.1:$APORT/kasse/setup.php"
  sed -e 's/<[^>]*>/ /g' "$WORK/a.html" | tr -s ' ' | grep -o '\.htaccess und mod_rewrite[^.]*\.[^.]*' | head -1 || true
  expect "wirksam" "$WORK/a.html" "Apache: .htaccess/mod_rewrite"
  expect "Gesperrte Ordner sind von außen nicht abrufbar" "$WORK/a.html" "Apache: Sperr-Probe"
  ok "Apache: .htaccess, mod_rewrite und Sperre per Probe erkannt"
  [[ -z "$(ls -d "$ADOC"/kasse/quitt-probe-* 2>/dev/null)" ]] || fail "Probe-Ordner nicht aufgeraeumt"
  setup_run "http://localhost:$APORT/kasse" "$ADOC/kasse" new
  check_install "http://localhost:$APORT/kasse" "$ADOC/kasse" /kasse lock   # im Container gilt /.dockerenv -> Sperrmodus
  [[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$APORT/kasse/.htaccess")" == "403" ]] || fail ".htaccess abrufbar"
  h="$(curl -sSI "http://localhost:$APORT/kasse/sw.js")"; echo "$h" | grep -qi 'cache-control: no-cache' || fail "sw.js ohne no-cache"
  ok "Apache: Sicherheits-Header / no-cache aktiv"
  docker rm -f "$APACHE_ID" > /dev/null; APACHE_ID=""

  # Negativ: Standard-Image (ohne zip, ohne mod_rewrite) mit "AllowOverride None" -> rote Punkte, kein Start
  NDOC="$WORK/apache-neg"; mkdir -p "$NDOC"; cp "$ROOT/setup/setup.php" "$NDOC/"; chmod -R 777 "$NDOC"
  APACHE_ID="$(docker run -d -p "127.0.0.1:$APORT:80" -v "$NDOC:/var/www/html" php:8.3-apache \
    sh -c 'sed -i "s/AllowOverride All/AllowOverride None/" /etc/apache2/conf-enabled/docker-php.conf && exec apache2-foreground')"
  for _ in $(seq 1 100); do curl -s -o /dev/null "http://127.0.0.1:$APORT/" && break; sleep 0.2; done
  curl -sS -o "$WORK/n.html" -H "Host: localhost" "http://127.0.0.1:$APORT/setup.php"
  expect "ignoriert Zugriffssperren" "$WORK/n.html" "Negativ: Sperr-Probe"
  expect "mod_rewrite ist nicht aktiv" "$WORK/n.html" "Negativ: Rewrite-Probe"
  expect "Die PHP-Erweiterung „zip“ fehlt" "$WORK/n.html" "Negativ: zip"
  ! grep -q "Einrichtung starten" "$WORK/n.html" || fail "Negativ: Start trotz Fehlern"
  [[ "$(curl -sS -o /dev/null -w '%{http_code}' -d action=start "http://127.0.0.1:$APORT/setup.php")" == "303" && ! -e "$NDOC/data" ]] || fail "Negativ: Start per POST trotz Fehlern"
  ok "Apache ohne AllowOverride/zip: rote Punkte mit Abhilfe, Start gesperrt"
  docker rm -f "$APACHE_ID" > /dev/null; APACHE_ID=""

  # --- 7. PHP 7.4 ---------------------------------------------------------------
  echo "-- PHP 7.4"
  mkdir -p "$WORK/old"; cp "$ROOT/setup/setup.php" "$WORK/old/"
  docker run --rm -v "$WORK/old:/s" php:7.4-cli sh -c \
    'php -S 127.0.0.1:8000 -t /s >/dev/null 2>&1 & sleep 1; php -r "echo file_get_contents(\"http://127.0.0.1:8000/setup.php\");"' > "$WORK/old.html"
  expect "ist zu alt" "$WORK/old.html" "PHP 7.4"
  ! grep -q "Einrichtung starten" "$WORK/old.html" || fail "PHP 7.4: Start nicht gesperrt"
  ok "PHP 7.4: verstaendliche Meldung, Start gesperrt"
fi

echo "== E2E erfolgreich"
