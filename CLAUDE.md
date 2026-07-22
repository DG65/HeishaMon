# Konventionen für dieses Modul

## Sprache: Deutsch für alles Nutzersichtbare

Anweisung von Dietmar (22.07.2026), gilt für alle Module des DG65-Verbunds.

**Deutsch** sind: Formularbeschriftungen, Hinweis- und Warntexte, Bestätigungsdialoge, Fehler- und Statusmeldungen, Log-Ausgaben, Variablen- und Profilnamen, README und Changelog. Vermeidbare Anglizismen ersetzen — insbesondere: Link → Verknüpfung, Event → Ereignis, Button → Schaltfläche, Dry-Run → Probelauf, Scan → Suche, Drag & Drop → Ziehen mit der Maus.

**Ausgenommen** (bleibt englisch, sonst brechen Verträge oder das Verständnis leidet):

- Bezeichner im Code: Klassen-, Methoden-, Eigenschafts- und vor allem **Ident-Namen**
- Formularelementtypen (`"type": "Button"`, `SelectVariable`, `SelectCategory`) — das ist Code, kein Anzeigetext
- die MQTT-Topic-Namen der Wärmepumpe (`main/...`) und die Feldnamen von `HEISHA_GetFunctions`
- feststehende Fachbegriffe — verbindliche Liste für dieses Modul:
  **COP**, **SG-Ready**, **MQTT**, **Topic**, **Modbus TCP**, **WebFront**, **Debug**, **PID**, **IPM**, **Delta-T**
- Produktbezeichnungen von Panasonic bzw. HeishaMon, die auf dem Gerät und in dessen Oberfläche so heißen:
  **Powerful-Modus**, **Smart-Warmwasser**, **Duty** (als Klammer-Erläuterung bei „Pumpenansteuerung (Duty)")
- etabliertes Lehngut: **Online/Offline** (im Duden)

Neue Texte werden im Code als englischer Schlüssel geschrieben und in `HeishaMon/locale.json` übersetzt.

**Vorgehen bei Umstellungen:** Ganze Sätze neu formulieren, nicht einzelne Wörter tauschen. Suchen-und-Ersetzen erzeugt zuverlässig zwei Fehlerklassen — gebrochene Genus-Kongruenz (aus „einen Portcheck" wird mit dem femininen „Port-Prüfung" ein Fehler) und vertauschte Objektbezüge (englisch „scan" heißt je nach Satz „absuchen" *oder* „finden": man durchsucht nicht die Zähler, die Suche findet sie nicht).

## Idents sind API

Variablen-Idents werden **nie** umbenannt — sie sind die Schnittstelle für Skripte, Archiv und andere Module. Änderungen nur additiv. Dasselbe gilt für die Rückgabestruktur von `HEISHA_GetFunctions` (Erweiterung nur durch neue Felder).

## Zweige

- `beta` — Entwicklung und schnelle Auslieferung an Tester (Installation per GitHub-URL)
- `main` — geprüfter Stand, den Nutzer über den IP-Symcon Module Store beziehen

Entwickelt wird auf `beta`. Die Übernahme nach `main` entscheidet Dietmar.

## Tests

Vor jedem Push `php test_module.php` im übergeordneten Arbeitsverzeichnis ausführen (gemockter IPS-Kern, deckt Empfang, Steuerung, COP-Berechnung, Datenpunkt-Auswahl, Verknüpfungsstruktur und den `GetFunctions`-Vertrag ab).

## IP-Symcon-Stolperfallen

- Schaltbare Variablen brauchen eine eingabefähige Darstellung (Schalter, Auswahlliste, Schieberegler mit MIN/MAX oder `VARIABLE_PRESENTATION_VALUE_INPUT`).
- Darstellungen nur bei tatsächlicher Abweichung schreiben — sonst Update-Sturm in der Konsole.
- In `onClick`-Skripten von Schaltflächen gibt es kein `$_IPS['TARGET']`, die Instanz-ID heißt `$id`.
- Schaltflächen dürfen keine Eigenschaften per `IPS_SetProperty` + `IPS_ApplyChanges` persistieren, sondern nur die offene Maske per `UpdateFormField` ändern.
- Nicht editierbare Listenspalten benötigen `"save": true`, sonst gehen ihre Werte beim Übernehmen verloren.
