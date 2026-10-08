#!/bin/bash
# Messlauf des Zahlcodes gegen eine Instanz der DevBox: das Zeichnen des GiroCodes über PHPBench, dazu
# die Größe des Markups je Bildgröße. Die Werte werden an benchmarks/results.csv angehängt, mit Datum,
# Fassung (aus der Instanz, nicht aus dem Quellbaum) und Rechner. Spalte `ms`: der Modus, den PHPBench
# über die Durchläufe ermittelt; beim Markup steht dort die Zahl der Bytes, in `streuung` die nach gzip.
#
# Aufruf vom Arbeitsplatz: bash benchmarks/run.sh [instanz]
#
# Eine Seitenmessung gibt es nicht: Die Abschlussseite braucht eine frische Bestellung, und ihre Zeit
# wäre fast ganz die des Kerns. Was die Erweiterung dazutut, ist das Zeichnen; das wird hier gemessen.
# Zahlen verschiedener Rechner oder Instanzen sind nicht vergleichbar; verglichen wird die Reihe
# derselben Instanz.
set -euo pipefail

INSTANCE=${1:-live-clone}
HOST=devbox
PLUGIN=RcQrCheckOut
PHPBENCH_VERSION='^1.7'
ROOT=$(cd "$(dirname "$0")/.." && pwd)
RESULTS=$ROOT/benchmarks/results.csv

# Die Messklassen liegen außerhalb von src/ und kommen mit keinem Rollout in die Instanz.
tar -C "$ROOT" -cf - benchmarks phpbench.json | ssh "$HOST" "tar -xf - -C /workspace/plugins/$PLUGIN"

remote() { ssh "$HOST" "cd /workspace/shopware/instances/$INSTANCE && $1"; }

VERSION=$(remote "ddev mysql -N -e \"SELECT version FROM plugin WHERE name='$PLUGIN'\" 2>/dev/null")
MACHINE=$(ssh "$HOST" hostname)
DATE=$(date +%F)
[ -f "$RESULTS" ] || echo "datum;fassung;rechner;instanz;art;messung;ms;streuung" > "$RESULTS"

# PHPBench liegt im var/-Ordner der Instanz: Im gemeinsamen Plugin-Ordner sähen es alle drei
# Instanzen, und in deren vendor/ gehört kein Messwerkzeug.
echo "Zeichnen (PHPBench, $INSTANCE, Fassung $VERSION) ..."
remote "ddev exec 'test -x var/rc-bench/vendor/bin/phpbench || (mkdir -p var/rc-bench && cd var/rc-bench && echo {} > composer.json && composer require -q --no-interaction phpbench/phpbench:$PHPBENCH_VERSION)'" >/dev/null 2>&1
remote "ddev exec 'cd custom/plugins/$PLUGIN && PROJECT_ROOT=/var/www/html php /var/www/html/var/rc-bench/vendor/bin/phpbench run --config=phpbench.json --report=results --progress=none'" 2>&1 |
  awk -F'|' '/Bench +\|/ {gsub(/ /, "", $2); gsub(/ /, "", $3); gsub(/^ +| +$/, "", $4); gsub(/[ ,]/, "", $7); gsub(/ /, "", $8); gsub(/ /, "", $9); print $2 "::" $3 ($4 == "" ? "" : " (" $4 ")") ";" $7 ";" $8 " Speicher " $9}' |
  while IFS=';' read -r subject mode spread; do
    echo "  $subject: $mode ($spread)"
    echo "$DATE;$VERSION;$MACHINE;$INSTANCE;dienst;$subject;${mode%ms};$spread" >> "$RESULTS"
  done

# Die Größe des Markups prüft die Behauptung im Quelltext, über 1024 Pixel wachse nur noch das Markup.
echo "Markup je Bildgröße ..."
remote "ddev exec 'cd custom/plugins/$PLUGIN && PROJECT_ROOT=/var/www/html php benchmarks/markup-size.php'" |
  while IFS=';' read -r size bytes packed; do
    echo "  $size px: $bytes Bytes, gepackt $packed"
    echo "$DATE;$VERSION;$MACHINE;$INSTANCE;markup;SVG $size px;$bytes;Bytes, gepackt $packed" >> "$RESULTS"
  done

echo "Abgelegt in benchmarks/results.csv"
