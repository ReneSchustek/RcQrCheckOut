# Changelog

## [2.1.0] - 2026-10-06: GiroCode auch in der Bestellbestätigung per Mail

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`. Der Code
> erscheint in der Mail erst, wenn die Vorlage „Bestellbestätigung" den Baustein aus der README trägt.

### Neu

- **GiroCode in der Bestellmail.** Bei Vorkasse stellt die Erweiterung der Bestellbestätigung die Variable
  `rcGiroCode` bereit (Empfänger, IBAN, Betrag, Verwendungszweck) und bettet den Code als PNG mit
  Content-ID ein; Gmail, Outlook und Apple Mail zeigen ihn im Text. Ohne den Baustein in der Vorlage
  ändert sich an der Mail nichts, auch kein loser Anhang. Eine schon bezahlte Bestellung („Bestellung
  erneut senden") bekommt keinen Code. Der Datensatz ist derselbe wie auf der Abschlussseite.

## Unveröffentlicht

- **Messprojekt `benchmarks/`** mit PHPBench für das Zeichnen des GiroCodes und einer Messung des
  Markups; es kommt in kein Ausrollpaket. Ausgangswerte vom 06.10. an einer Kopie des Shops: 11 ms für den
  üblichen GiroCode, 35 ms für den längsten erlaubten; Markup rund 53 KB, gepackt 3 KB, bei jeder
  Bildgröße fast gleich. Der Kommentar, über 1024 Pixel wachse nur noch das Markup, ist berichtigt.
- Zwei Wächter im Testlauf (Zeit, Markup). PHPStan und PHP CS Fixer prüfen auch `benchmarks/`.

## [2.0.5] - 2026-10-05: Ein ausgeschalteter Zahlcode bleibt aus

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`.

### Behoben

- **Ein über die Konsole ausgeschalteter Zahlcode erschien weiter.** Ohne `--json` gesetzt, steht
  „false“ als Zeichenkette in der Datenbank, und PHP hielt sie für wahr. Jetzt gilt sie als aus;
  ein unlesbarer Wert ebenso.

### Intern

- Kommentare im ganzen Bestand nachgezogen; ein Wächter hält fest, dass jeder Typ einen Kopf hat.
- Testnamen auf Englisch.

## [2.0.4] - 2026-10-05: Der GiroCode erscheint wieder

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`.
> Das Paket bringt die QR-Bibliothek wie bisher im eigenen `vendor` mit.

### Behoben

- **Bei Vorkasse-Bestellungen fehlte seit 2.0.3 der GiroCode auf der Bestätigungsseite.** Die
  Erweiterung brachte die QR-Bibliothek mit, aber niemand lud sie: Shopware zählt ein mitgeliefertes
  `vendor` beim Einbau als vorhanden, bindet es zur Laufzeit aber nicht ein. Im Protokoll stand bei
  jeder dieser Bestellungen „Class Endroid\QrCode\Builder\Builder not found". Die Seite selbst lief
  weiter, nur ohne Code und Bankdaten.
- Die Erweiterung registriert jetzt beim Start die Namensräume der Bibliothek aus dem eigenen
  `vendor`, und nur diese. Bringt der Shop die Bibliothek selbst mit, gilt seine Fassung.

## [2.0.3] - 2026-08-27: Der Abstand steht an einer Stelle, nicht an zweien

### Geändert

- **Der Kasten bringt seinen oberen Abstand nicht mehr selbst mit.** Er stammte aus der Zeit, als
  die Bestätigungsseite gar keinen Rhythmus hatte, und war die einzige Stelle mit Luft. Seit das
  Theme allen Kästen denselben Abstand gibt, stünden sonst zwei übereinander, und wer den einen
  ändert, versteht den anderen nicht.

## [2.0.2] - 2026-08-27: Beschriftung und Wert stehen wieder beieinander

### Behoben

- **Im Kasten unter dem QR-Code stand „IBAN" neben dem Empfängernamen statt bei der Nummer.** Alle
  sechs Elemente lagen in einer einzigen Rasterreihe. Die Beschriftung nahm drei Spalten, der Wert sechs,
  zusammen neun von zwölf, und in die verbleibenden drei rutschte die nächste Beschriftung. Jetzt hat
  jedes Paar seine eigene Reihe. Gesehen am laufenden Shop mit einer echten Bestellung.

## [2.0.1] - 2026-08-03: Der QR-Code bleibt innerhalb dessen, was Banking-Apps annehmen

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`.

### Behoben

- **Ein langer Verwendungszweck konnte einen Code erzeugen, den Banking-Apps zurückweisen.** Der Zahlungsstandard begrenzt den gesamten Datensatz auf 331 Byte; begrenzt waren bisher nur die Einzelfelder, und zwar nach Zeichen. Ein Umlaut ist ein Zeichen, belegt aber zwei Byte. Im ungünstigsten Fall kam so fast das Anderthalbfache des Erlaubten heraus. Aufgefallen wäre es niemandem: Der Code wird erzeugt und angezeigt, erst beim Scannen passiert nichts. Der Verwendungszweck wird jetzt so weit gekürzt, dass der Datensatz passt; Empfänger, IBAN und Betrag bleiben unangetastet.
- **Eine falsch eingetippte BIC wanderte ungeprüft in den Code.** Im verwendeten Standard ist sie optional, und eine leere BIC ist gültig, eine falsche nicht. Entspricht die Eingabe nicht dem BIC-Format, bleibt das Feld jetzt leer, statt den Code unbrauchbar zu machen.

## [2.0.0] - 2026-07-21: Neuausrichtung, GiroCode statt Order-Link (Breaking)

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`. Danach unter Konfiguration die Bankverbindung (IBAN/Empfänger) und die Zahlarten hinterlegen. Kein Schema-Break, keine neuen Abhängigkeiten.

### Geändert (Breaking, neuer Zweck)

- **Der QR-Code auf der Bestellbestätigung ist jetzt ein GiroCode (Zahlungs-QR), kein Bestell-Link mehr.** Bisher kodierte der Code die Order-Detail-URL („Bestellung mobil ansehen"). Echten Mehrwert hatte das nicht, denn die Bestellung ist ohnehin per Link und Bestätigungs-Mail erreichbar. Am Ende des Kaufs zeigt das Plugin jetzt die **Bankverbindung als GiroCode** (EPC069-12 / „BCD"). Der Kunde scannt ihn mit seiner Banking-App, und die **SEPA-Überweisung** ist mit Empfänger, IBAN, **Betrag** und **Bestellnummer** (Verwendungszweck) vorausgefüllt. Darin liegt der eigentliche Zweck des Plugins, vor allem für Vorkasse.
- **Neue Konfiguration:** Auswahl der Zahlarten (nur für diese erscheint der GiroCode), Empfänger, IBAN (Mod-97-geprüft), BIC (optional), Vorlage für den Verwendungszweck. Alles pro Sales-Channel.
- **Gating und Fail-Soft:** Der GiroCode erscheint nur bei passender Zahlart, gepflegter IBAN und einer Bestellung in **EUR**. Sonst wird er weggelassen, ein Seitenfehler entsteht nie.
- **Entfernt:** die Order-Link-Funktion (`OrderDetailUrlBuilder`) samt Deep-Link-Route.

## [1.0.3] - 2026-07-20: QR-Code verlinkt jetzt die aufrufbare Bestellansicht

> **Deployment:** `php bin/console plugin:update RcQrCheckOut && php bin/console cache:clear`. Keine neuen Abhängigkeiten, kein Schema-Break.

### Behoben

- **Der QR-Code führt jetzt auf eine tatsächlich aufrufbare Bestellansicht.** Bisher kodierte er die Route `frontend.account.edit-order.page`, also die login-pflichtige Seite „Zahlung ändern". Wer auf einem zweiten Gerät scannte (frische Session ohne Login), landete im Login. Gäste aus dem Gast-Checkout haben kein Passwort und kamen gar nicht hinein, womit der QR-Code im Hauptfall seinen Zweck verfehlte. Umgestellt ist er auf die Deep-Link-Ansichtsroute `frontend.account.order.single.page` (`/account/order/{deepLinkCode}`). Das ist derselbe Pfad wie beim Link „Bestellung ansehen" in der Bestätigungs-Mail: Gäste bestätigen per E-Mail, registrierte Kunden über den Login. Fehlt einer Bestellung der `deepLinkCode`, wird der QR-Code fail-soft weggelassen. (Ein Datenschutz-Problem entsteht nicht: Die alte Route erzwang den Login, die neue nutzt wie der Core den geheimen Deep-Link-Code.)

## [1.0.2] - 2026-07-20

> **Deployment:** Vor `plugin:install` einmalig `bin/ensure-deps.sh` im Shopware-Root ausführen (siehe README), dann `php bin/console cache:clear`.

### Behoben

- **Der QR-Code erscheint jetzt tatsächlich auf der Bestellbestätigung.** Das Finish-Template überschrieb den Block `page_checkout_finish_information`, den es in Shopware 6.7 nicht gibt. Einen solchen `sw_extends`-Block ignoriert Twig stillschweigend, und so wurde der QR-Code nie ausgegeben. Jetzt zielt das Template auf den realen Block `page_checkout_finish_details` (Bereich der Bestellbestätigung). Ein Pinning-Test nagelt Ziel-Template und Blockname gegen den Core fest.

### Hinzugefügt

- **Pre-Install-Helfer `bin/ensure-deps.sh`:** Er zieht die Composer-Abhängigkeit `endroid/qr-code` (`^4.8`) idempotent in den Shopware-Root, bevor das Plugin aktiviert wird. Das braucht es beim Betrieb über Datei-Sync oder Symlink, wo das Paket sonst im Root-vendor fehlt und die Aktivierung mit „Required package endroid/qr-code is missing" abbricht. Die README nennt den Pre-Install-Schritt jetzt sichtbar.

## [1.0.1] - 2026-06-27

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Ein QR-Fehler reißt die Bestellbestätigung nicht mehr ab.** Schlägt die QR-Generierung fehl, wird der Code fail-soft weggelassen und nur geloggt. Vorher hätte eine Exception auf der bereits bezahlten Finish-Seite einen 500 ausgelöst.
- Das Konfigurationsfeld „QR-Code-Größe" zeigt im Admin jetzt `min`/`max` (64–1024).
- Ungenutztes `url`-Feld aus der Page-Extension entfernt.

## [1.0.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console plugin:install --activate RcQrCheckOut && php bin/console cache:clear`

### Hinzugefügt
- Erstes Release. Auf der Bestellbestätigungs-Seite (`/checkout/finish`) erscheint ein QR-Code, der zur Order-Detail-Seite (`/account/order/edit/<orderId>`) verlinkt. Der Kunde scannt ihn mit dem Smartphone und ruft die Bestellung mobil auf.
- `QrCodeService` nutzt `endroid/qr-code` v4 (SVG-Output, Medium-Error-Correction)
- `OrderDetailUrlBuilder` erzeugt absolute URLs über den Shopware-Router
- `QrCodeSubscriber` auf `CheckoutFinishPageLoadedEvent` hängt ein `ArrayStruct` mit `enabled`/`svg`/`url` an die Page-Extension
- Plugin-Config: `qrCodeEnabled` (bool, Default true), `qrCodeSize` (int, Default 200, geclampt 64-1024)
- Twig-Decoration der Finish-Page rendert das SVG inline mit `|raw` (kontrollierter Inhalt aus endroid)
- Snippets DE+EN
- 10 Unit-Tests (5 QrCodeService + 1 OrderDetailUrlBuilder + 4 QrCodeSubscriber)

### Sicherheit
- Der SVG-Inhalt kommt ausschließlich aus `endroid/qr-code` (kein User-Input direkt im Render-Pfad)
- Die URL kommt aus dem Shopware-Router (kein injizierbarer User-Pfad)
- Size-Werte aus der Plugin-Config werden geclampt
