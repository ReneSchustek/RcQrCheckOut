# RcQrCheckOut

Shopware 6 Plugin — GiroCode (Zahlungs-QR) auf der Bestellbestätigungs-Seite.

## Was das Plugin macht

Nach Abschluss einer Bestellung erscheint auf der Finish-Seite ein **GiroCode** (EPC069-12 / „BCD") — ein Zahlungs-QR-Code für Banking-Apps. Der Kunde scannt ihn mit seiner Banking-App und die **SEPA-Ueberweisung** ist mit Empfänger, IBAN, **Betrag** und **Bestellnummer** als Verwendungszweck vorausgefüllt. Ideal für Vorkasse.

Der GiroCode erscheint nur, wenn:

- das Plugin aktiv ist **und**
- die Zahlart der Bestellung in der konfigurierten Zahlarten-Liste liegt (z. B. Vorkasse) **und**
- eine gültige IBAN gepflegt ist **und**
- die Bestellwährung EUR ist (GiroCode unterstützt ausschließlich EUR).

Andernfalls wird der QR-Code fail-soft weggelassen (nie ein Seitenfehler).

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+
- Composer-Paket `endroid/qr-code` (`^4.8`) im Shopware-Root — siehe Pre-Install-Schritt unten

## Installation

**Vor der Installation — Composer-Abhängigkeit sicherstellen:** Der Shopware-Plugin-Manager
prüft `endroid/qr-code` vor der Aktivierung. Wird das Plugin per Datei-Sync/Symlink unter
`custom/plugins` betrieben (statt per `composer require` im Projekt), fehlt das Paket sonst im
Shopware-Root und die Aktivierung bricht ab. Der mitgelieferte, **idempotente** Helfer zieht es
einmalig nach (ist es bereits da, macht er nichts):

```bash
# im Shopware-Root ausführen:
custom/plugins/RcQrCheckOut/bin/ensure-deps.sh .

# unter DDEV:
ddev exec "/var/www/html/custom/plugins/RcQrCheckOut/bin/ensure-deps.sh /var/www/html"
```

Danach die Standard-Installation:

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcQrCheckOut
php bin/console cache:clear
```

## Konfiguration

Admin → Erweiterungen → RcQrCheckOut → Konfiguration:

**Allgemein**
- **GiroCode anzeigen** (Default: an)
- **QR-Code-Größe in Pixeln** (Default: 200, Range 64-1024)

**Bankverbindung (GiroCode)**
- **Zahlarten mit GiroCode** — für welche Zahlarten der GiroCode erscheint (z. B. Vorkasse). Leer = für keine.
- **Empfänger (Kontoinhaber)** — Pflicht, max. 70 Zeichen
- **IBAN** — Pflicht, wird per Mod-97 geprüft
- **BIC** — optional (bei Inlands-SEPA nicht nötig)
- **Verwendungszweck-Vorlage** — Platzhalter `{orderNumber}` und `{amount}`, Default „Bestellung {orderNumber}"

Alle Felder sind pro Sales-Channel überschreibbar.

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)
