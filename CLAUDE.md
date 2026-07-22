# Konventionen für dieses Modul

## Sprache: Deutsch für alles Nutzersichtbare

Anweisung von Dietmar (22.07.2026), gilt für alle Module des DG65-Verbunds.

**Deutsch** sind: Formularbeschriftungen, Hinweis- und Warntexte, Bestätigungsdialoge, Fehler- und Statusmeldungen, Log-Ausgaben, Variablen- und Profilnamen, README und Changelog. Vermeidbare Anglizismen ersetzen — insbesondere: Link → Verknüpfung, Event → Ereignis, Button → Schaltfläche, Dry-Run → Probelauf, Scan → Suche, Drag & Drop → Ziehen mit der Maus.

**Ausgenommen** (bleibt englisch, sonst brechen Verträge):

- Bezeichner im Code: Klassen-, Methoden-, Eigenschafts- und vor allem **Ident-Namen**
- feststehende Fachbegriffe: MQTT, Topic, Modbus TCP, SelectVariable, WebFront, Debug, COP
- die MQTT-Topic-Namen der Wärmepumpe (`main/...`) und die Feldnamen von `HEISHA_GetFunctions`

Neue Texte werden im Code als englischer Schlüssel geschrieben und in `HeishaMon/locale.json` übersetzt.

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
