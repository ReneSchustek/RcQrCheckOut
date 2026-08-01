#!/bin/sh
# Stellt sicher dass die Composer-Dependency von RcQrCheckOut auf dem
# Shopware-Root verfügbar ist, bevor plugin:install ausgeführt wird.
#
# Idempotent: prüft zuerst ob das Paket schon da ist und macht dann no-op.
#
# Aufruf:
#   ./bin/ensure-deps.sh               # erwartet pwd = Shopware-Root
#   ./bin/ensure-deps.sh /pfad/zu/root # expliziter Pfad
#
# Auf DDEV:
#   ddev exec "/var/www/html/custom/plugins/RcQrCheckOut/bin/ensure-deps.sh /var/www/html"

set -eu

REQUIRED_PACKAGE="endroid/qr-code"
REQUIRED_CONSTRAINT="^4.8"

shopware_root="${1:-$(pwd)}"

if [ ! -f "$shopware_root/composer.json" ]; then
    echo "ERR: $shopware_root/composer.json nicht gefunden — kein Shopware-Root." >&2
    exit 1
fi

if ! grep -q '"shopware/core"' "$shopware_root/composer.json"; then
    echo "ERR: $shopware_root/composer.json enthält keine shopware/core-Dependency." >&2
    exit 1
fi

cd "$shopware_root"

if composer show "$REQUIRED_PACKAGE" >/dev/null 2>&1; then
    echo "OK: $REQUIRED_PACKAGE bereits installiert — no-op."
    exit 0
fi

echo "Installiere $REQUIRED_PACKAGE:$REQUIRED_CONSTRAINT auf Shopware-Root $shopware_root ..."
composer require "$REQUIRED_PACKAGE:$REQUIRED_CONSTRAINT" --no-interaction

echo "OK: $REQUIRED_PACKAGE:$REQUIRED_CONSTRAINT installiert."
