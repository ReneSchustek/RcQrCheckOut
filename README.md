# RcQrCheckOut

Shopware 6 Plugin — GiroCode (Zahlungs-QR) auf der Bestellbestätigungs-Seite.

## Was das Plugin macht

Nach Abschluss einer Bestellung erscheint auf der Finish-Seite ein **GiroCode** (EPC069-12 / „BCD") — ein Zahlungs-QR-Code für Banking-Apps. Der Kunde scannt ihn mit seiner Banking-App und die **SEPA-Überweisung** ist mit Empfänger, IBAN, **Betrag** und **Bestellnummer** als Verwendungszweck vorausgefüllt. Ideal für Vorkasse.

Der GiroCode erscheint nur, wenn:

- das Plugin aktiv ist **und**
- die Zahlart der Bestellung in der konfigurierten Zahlarten-Liste liegt (z. B. Vorkasse) **und**
- eine gültige IBAN gepflegt ist **und**
- die Bestellwährung EUR ist (GiroCode unterstützt ausschließlich EUR).

Andernfalls wird der QR-Code fail-soft weggelassen (nie ein Seitenfehler).

### GiroCode in der Bestellbestätigung per Mail

Unter denselben Bedingungen bekommt die Mail „Bestellbestätigung" (`order_confirmation_mail`) den Code,
solange die Zahlung offen ist. Die Erweiterung überschreibt die Vorlage nicht; sie stellt die Variable
`rcGiroCode` bereit und bettet das Bild ein, wenn die Vorlage es verwendet. In die HTML-Fassung der
Vorlage gehört dafür dieser Baustein, etwa unter die Zahlungsart:

```twig
{% if rcGiroCode is defined %}
    <p><strong>Per Überweisung bezahlen</strong><br>
       Scannen Sie den GiroCode mit Ihrer Banking-App.</p>
    <img src="{{ rcGiroCode.cid }}" width="200" height="200" alt="GiroCode für die Überweisung">
    <p>Empfänger: {{ rcGiroCode.recipient }}<br>
       IBAN: {{ rcGiroCode.iban }}<br>
       Betrag: {{ rcGiroCode.amount|currency }}<br>
       Verwendungszweck: {{ rcGiroCode.purpose }}</p>
{% endif %}
```

`rcGiroCode.cid` ist `cid:girocode`, das eingebettete PNG (mindestens 200 Pixel, breite Ruhezone). Ein
`data:`-Bild zeigen viele Mailprogramme nicht an. In der Textfassung tragen dieselben Angaben ohne Bild.

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

## Geschwindigkeit messen

`benchmarks/` misst mit PHPBench das Zeichnen des GiroCodes, die einzige Stelle, die spürbar Zeit
kostet: Es läuft bei jedem Aufruf der Abschlussseite einer Vorkasse-Bestellung. Dazu die Größe des
Markups je Bildgröße, roh und gepackt. Aufruf gegen eine Testinstanz mit Shopware (ddev):

```bash
bash benchmarks/run.sh <instanz>
```

Die Werte landen in `benchmarks/results.csv`, mit Datum, Fassung und Rechner. Ausgangswerte vom
06.10.2026 an einer Kopie des Shops: 11 ms für den üblichen GiroCode, 35 ms für den längsten erlaubten, bei
64 wie bei 1024 Pixeln; das Markup rund 53 KB, gepackt 3 KB. Zwei großzügige Wächter in
`QrCodeServiceTest` laufen mit den normalen Tests. Das Messprojekt kommt in kein Ausrollpaket.

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)
