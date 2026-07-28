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

## Emojis

Entscheidung Dietmar (23.07.2026), verbundweit — ersetzt jede frühere „keine Emojis"-Vorgabe.

Emojis sind **erwünscht**, wo sie Nutzen stiften:

1. als **Panel-Icon** — ein Zeichen am Anfang einer ExpansionPanel-Überschrift (📖🔌📊), als Ersatz für das fehlende `icon`-Feld;
2. als **Status-/Aufmerksamkeitssymbol** (✅❌⚠️💡ℹ️) dort, wo etwas beim Lesen Aufmerksamkeit erfordert oder herausgestellt werden soll (Status, Warnungen, wichtige Hinweise).

Faktenlage: Kein Symcon-Store-Review hat Emojis je beanstandet; die frühere Regel war präventiv und ist aufgehoben. **Beobachtungsklausel:** Sollte ein Stable-Review Emojis bemängeln, entscheidet der Verbund neu (Rückfall: gemeinsam emoji-frei).

## Einheitliche Formular-Optik

Konvention Dietmar (24.07.2026), verbundweit, Referenzimplementierung InverterHub, Details in `EMS/SUITE.md`.

Reihenfolge von oben: (1) **🆕 Neu in Version X.Y** — aufgeklappt, pro Version bestätigbar (Attribut `SeenNews` speichert die bestätigte Version), keine Versionsnummer im Panel-Inhalt. (2) **📖 Dokumentation & Hilfe** — ganz oben vor den Funktionsfeldern, eingeklappt, Überschrift trägt die Modulversion. (3) Fachpanels; neue/wichtige Felder mit `🆕`-Präfix im Label. (4) Symcon-Forum-Hinweis nach den Haupteinstellungen, einmalig ausblendbar.

**Pflege ist Pflicht bei jedem Fix/Update, nicht nur bei großen Releases:** Bei jeder Änderung prüfen, ob sie ins Neu-in-Version-Panel gehört — die Antwort darf „nein" sein (z. B. reine interne Umbauten, Testergänzungen), aber die Prüfung selbst darf nicht entfallen.

**Layout-Qualität:** logische Gruppierung (Doku vor Funktionsfeldern, Zusammengehöriges in einem Panel), Step-by-Step ohne Scroll-Zickzack (keine Sprünge zwischen Kernfeldern und Nebenpanels), Feldkanten auf einer Linie statt kreuz und quer.

Aktueller Forum-Link zeigt auf die allgemeine PHP-Module-Kategorie (`community.symcon.de/c/erweiterungen/php-module-entwicklung/21`), da kein bestätigter HeishaMon-eigener Thread existiert — bei Bedarf durch den konkreten Thread ersetzen.

**Feld-Tooltips:** Symcon kennt keine nativen Mouseover-Tooltips (form.json/Listenspalten haben kein `tooltip`-Attribut). Für erklärungsbedürftige Einzelfelder ein `PopupButton` direkt daneben in einem `RowLayout` — kurze, immer sichtbare Erklärungen bleiben als `Label`. Caption `"?"` (finale Wahl Dietmars — `"i"` wirkt bei 70px Breite optisch verloren) mit `"width": "70px"` für eine quadratisch wirkende Fläche — unter ~70px hat `width` im WebFront-Skin keinen sichtbaren Effekt, Icon-Größe/Hintergrund sind grundsätzlich nicht änderbar (live getestet von InverterHub). Bereits umgesetzt bei `MQTTTopic` und `COPMinPower`.

## Idents sind API

Variablen-Idents werden **nie** umbenannt — sie sind die Schnittstelle für Skripte, Archiv und andere Module. Änderungen nur additiv. Dasselbe gilt für die Rückgabestruktur von `HEISHA_GetFunctions` (Erweiterung nur durch neue Felder).

## Zweige

- `beta` — Entwicklung und schnelle Auslieferung an Tester (Installation per GitHub-URL)
- `main` — geprüfter Stand, den Nutzer über den IP-Symcon Module Store beziehen
- `ems-integration` — verbundweiter Zweig für die laufende EMS-Integrationsphase, abgezweigt von `beta`

**Solange die EMS-Integrationsphase läuft (seit 25.07.2026, verbundweite Anweisung):** ausnahmslos alles auf `ems-integration` pushen, keine Ausnahme mehr für „sichere" Fixes direkt auf `beta`. Erst nach Bewährung und Freigabe wandert der Stand von `ems-integration` zurück nach `beta`. Diese Regel endet erst durch eine ausdrückliche neue Ansage — nicht von selbst nach einer gewissen Zeit annehmen, dass sie ausgelaufen ist.

Die Übernahme nach `main` entscheidet Dietmar von sich aus (nicht nachfragen, siehe Feedback-Notiz „beta→main-Freigabe" im Gedächtnis).

## Tests

Vor jedem Push `php test_module.php` im übergeordneten Arbeitsverzeichnis ausführen (gemockter IPS-Kern, deckt Empfang, Steuerung, COP-Berechnung, Datenpunkt-Auswahl, Verknüpfungsstruktur und den `GetFunctions`-Vertrag ab).

## IP-Symcon-Stolperfallen

- Schaltbare Variablen brauchen eine eingabefähige Darstellung (Schalter, Auswahlliste, Schieberegler mit MIN/MAX oder `VARIABLE_PRESENTATION_VALUE_INPUT`).
- Darstellungen nur bei tatsächlicher Abweichung schreiben — sonst Update-Sturm in der Konsole.
- In `onClick`-Skripten von Schaltflächen gibt es kein `$_IPS['TARGET']`, die Instanz-ID heißt `$id`.
- Schaltflächen dürfen keine Eigenschaften per `IPS_SetProperty` + `IPS_ApplyChanges` persistieren, sondern nur die offene Maske per `UpdateFormField` ändern.
- Nicht editierbare Listenspalten benötigen `"save": true`, sonst gehen ihre Werte beim Übernehmen verloren.
