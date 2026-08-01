# Changelog

## [2.0.0] - 2026-07-21 — Neuausrichtung: GiroCode statt Order-Link (Breaking)

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`. Danach unter Konfiguration die Bankverbindung (IBAN/Empfänger) und die Zahlarten hinterlegen. Kein Schema-Break, keine neuen Abhängigkeiten.

### Geändert (Breaking — neuer Zweck)

- **Der QR-Code auf der Bestellbestätigung ist jetzt ein GiroCode (Zahlungs-QR), kein Bestell-Link mehr.** Bisher kodierte der Code die Order-Detail-URL („Bestellung mobil ansehen") — das hatte keinen echten Mehrwert, da die Bestellung ohnehin per Link und Bestätigungs-Mail erreichbar ist. Jetzt präsentiert das Plugin am Kauf-Ende die **Bankverbindung als GiroCode** (EPC069-12 / „BCD"): Der Kunde scannt ihn mit seiner Banking-App und die **SEPA-Ueberweisung** ist mit Empfänger, IBAN, **Betrag** und **Bestellnummer** (Verwendungszweck) vorausgefüllt — der eigentliche Zweck des Plugins, v. a. für Vorkasse.
- **Neue Konfiguration:** Zahlarten-Auswahl (der GiroCode erscheint nur für diese), Empfänger, IBAN (Mod-97-geprüft), BIC (optional), Verwendungszweck-Vorlage. Alles pro Sales-Channel.
- **Gating & Fail-Soft:** Der GiroCode erscheint nur bei passender Zahlart, gepflegter IBAN und **EUR**-Bestellung; sonst wird er weggelassen (nie ein Seitenfehler).
- **Entfernt:** die Order-Link-Funktion (`OrderDetailUrlBuilder`) samt Deep-Link-Route.

## [1.0.3] - 2026-07-20 — QR-Code verlinkt jetzt die aufrufbare Bestellansicht

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`. Keine neuen Abhängigkeiten, kein Schema-Break.

### Behoben

- **Der QR-Code führt jetzt auf eine tatsächlich aufrufbare Bestellansicht:** Bisher kodierte er die Route `frontend.account.edit-order.page` — die login-pflichtige „Zahlung ändern"-Seite. Beim Scannen auf einem zweiten Gerät (frische Session ohne Login) landete der Kunde im Login; Gäste (Gast-Checkout) haben kein Passwort und kamen gar nicht hinein — der Kernzweck des QR-Codes war damit für den Hauptfall unbrauchbar. Umgestellt auf die Deep-Link-Ansichtsroute `frontend.account.order.single.page` (`/account/order/{deepLinkCode}`) — derselbe Pfad wie der „Bestellung ansehen"-Link in der Bestellbestätigungs-Mail: Gäste bestätigen per E-Mail, registrierte Kunden via Login. Fehlt der `deepLinkCode` einer Bestellung, wird der QR-Code fail-soft weggelassen. (Kein Datenschutz-Problem: die alte Route erzwang Login, die neue nutzt den geheimen Deep-Link-Code wie der Core.)

## [1.0.2] - 2026-07-20

> **Deployment:** Vor `plugin:install` einmalig `bin/ensure-deps.sh` im Shopware-Root ausführen (siehe README), dann `php bin/console cache:clear`.

### Behoben

- **QR-Code erscheint jetzt tatsächlich auf der Bestellbestätigung:** Das Finish-Template überschrieb den Block `page_checkout_finish_information`, den es in Shopware 6.7 nicht gibt — Twig ignoriert einen solchen `sw_extends`-Block stillschweigend, der QR-Code wurde also nie ausgegeben. Umgestellt auf den realen Block `page_checkout_finish_details` (Bestell-Bestätigungsbereich). Ein Pinning-Test nagelt Ziel-Template und Blockname gegen den Core fest.

### Hinzugefügt

- **Pre-Install-Helfer `bin/ensure-deps.sh`:** Zieht die Composer-Abhängigkeit `endroid/qr-code` (`^4.8`) idempotent in den Shopware-Root, bevor das Plugin aktiviert wird. Nötig beim Datei-Sync-/Symlink-Betrieb, wo das Paket sonst im Root-vendor fehlt und die Aktivierung mit „Required package endroid/qr-code is missing" abbricht. README um den sichtbaren Pre-Install-Schritt ergänzt.

## [1.0.1] - 2026-06-27

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **QR-Fehler reißt die Bestellbestätigung nicht mehr ab:** Schlägt die QR-Generierung fehl, wird der Code fail-soft weggelassen und nur geloggt — vorher hätte eine Exception auf der bereits bezahlten Finish-Seite einen 500 ausgelöst.
- Konfigurationsfeld „QR-Code-Größe" zeigt jetzt `min`/`max` (64–1024) im Admin.
- Ungenutztes `url`-Feld aus der Page-Extension entfernt.

## [1.0.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:install --activate RcQrCheckOut && php bin/console cache:clear`

### Hinzugefügt
- Erst-Release. Rendert auf der Bestellbestätigungs-Seite (`/checkout/finish`) einen QR-Code, der zur Order-Detail-Seite (`/account/order/edit/<orderId>`) verlinkt. Kunde scant mit Smartphone, um die Bestellung mobil aufzurufen.
- `QrCodeService` nutzt `endroid/qr-code` v4 (SVG-Output, Medium-Error-Correction)
- `OrderDetailUrlBuilder` generiert absolute URLs über Shopware-Router
- `QrCodeSubscriber` auf `CheckoutFinishPageLoadedEvent` hängt `ArrayStruct` mit `enabled`/`svg`/`url` an die Page-Extension
- Plugin-Config: `qrCodeEnabled` (bool, Default true), `qrCodeSize` (int, Default 200, geclampt 64-1024)
- Twig-Decoration der Finish-Page rendert SVG inline mit `|raw` (kontrollierter Inhalt aus endroid)
- Snippets DE+EN
- 10 Unit-Tests (5 QrCodeService + 1 OrderDetailUrlBuilder + 4 QrCodeSubscriber)

### Sicherheit
- SVG-Inhalt kommt ausschließlich aus `endroid/qr-code` (kein User-Input direkt in Render-Pfad)
- URL kommt aus Shopware-Router (kein injektabler User-Pfad)
- Size-Werte aus Plugin-Config werden geclampt
