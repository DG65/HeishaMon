# Changelog

## 1.8.0 — 2026-08-13

- `HEISHA_GetFunctions()` um 4 weitere Felder erweitert (contractVersion 1.3 → 1.4): `z1PumpID`, `z1MixingValveID`, `z2PumpID`, `z2MixingValveID` — externe Heizkreispumpe/-Mischventil an der optionalen 2. Steuerplatine (physikalisch getrennt von der bereits vorhandenen internen Pumpe `pumpFlowID`/`pumpSpeedID`/`pumpDutyID`). Mit WPHub/Dashboard/EMS abgestimmt. Mischventil liefert eine Stellrichtung (Zu/Auf/Aus), keine absolute Position. Rein additiv

## 1.7.1 — 2026-08-12

- Doku (Code-Kommentar in `GetFunctions()` und README) klargestellt: die Heizkreislauf-Felder sind Teil des gemeinsamen, herstellerneutralen `heatpump`-Vertragstyps (von EMS final abgestimmt), nicht HeishaMon-spezifisch. Rein dokumentarisch, keine Verhaltens- oder Feldnamensänderung

## 1.7.0 — 2026-08-12

- `HEISHA_GetFunctions()` um 14 Heizkreislauf-Felder erweitert (contractVersion 1.2 → 1.3): `pumpFlowID`, `pumpSpeedID`, `pumpDutyID`, `threeWayValveStateID`, `twoWayValveStateID`, `mainInletTempID`, `mainOutletTempID`, `z1WaterTempID`, `z2WaterTempID`, `dhwTempID`, `bufferTempID`, `compressorFreqID`, `dischargeTempID`, `defrostingStateID` — abgestimmt mit WPHub/NRGDashboard für eine Anlagenschema-Visualisierung. Rein additiv, kein Formular-Feld betroffen

## 1.6.1 — 2026-08-12

- Erklärtext der Verknüpfungsstruktur korrigiert: der vorige Hinweis "nicht im WebFront sichtbar" (v1.5.5) war falsch — die Verknüpfungsstruktur ist genau dafür gedacht, im WebFront eine übersichtliche gruppierte Navigation statt einer langen flachen Variablenliste zu ergeben. Text stellt das jetzt richtig dar. Rein textlich, keine Verhaltensänderung

## 1.6.0 — 2026-08-12

- Neu: 1-Wire-Temperatursensoren (z. B. DS18B20) am HeishaMon werden automatisch erkannt und angelegt, sobald ein Messwert eintrifft — eigenes Formularpanel "1-Wire-Sensoren (optional)" zur Benennung und Aktivierung. Bisher mussten Nutzer diese Sensoren manuell als eigene MQTT-Client-Variable einbinden (Fund aus einer Nutzerrückmeldung im Symcon-Forum). Erscheinen auch in der optionalen Verknüpfungsstruktur (eigene Gruppe "1-Wire-Sensoren")

## 1.5.5 — 2026-08-12

- Erklärtext der Verknüpfungsstruktur präzisiert — Fund aus einer echten Nutzerrückmeldung im Symcon-Forum: ohne gewählte Zielkategorie legt das Modul stillschweigend nichts an (kein Fehlerhinweis), und das Ergebnis erscheint nur im Objektbaum der Verwaltungskonsole, nicht im WebFront. Beides steht jetzt explizit im Panel-Text; Feldbeschriftung der Zielkategorie ergänzt um "(erforderlich, damit die Verknüpfungsstruktur angelegt wird)". Rein textlich, keine Verhaltensänderung

## 1.5.4 — 2026-07-28

- Panel-Titel "Link structure" und "External energy meter" um "(optional)" ergänzt — Fund nach expliziter Prüfung gegen Dietmars EMS-Usability-Befund (Panel wirkte wie Pflichtfeld, war aber optional/Fallback). Beide Panels sind bei uns per Default aus/leer und das Modul funktioniert ohne sie vollständig; das war aus dem Titel allein nicht ersichtlich. Rein optisch, keine inhaltliche Änderung

## 1.5.3 — 2026-07-27

- PopupButton-Caption final auf `"?"` gesetzt (statt `"i"`) — Dietmars Entscheidung, `"i"` wirkt bei 70px Breite optisch verloren. Rein optisch, keine inhaltliche Änderung

## 1.5.2 — 2026-07-27

- PopupButton-Konvention präzisiert (Erkenntnis von InverterHub: unter ~70px hat `width` im WebFront-Skin keinen sichtbaren Effekt, Icon-Größe/Hintergrund nicht änderbar): Caption der beiden Info-Buttons von ℹ️-Emoji auf reinen Buchstaben `i` mit `width: 70px` umgestellt. Rein optisch, keine inhaltliche Änderung

## 1.5.1 — 2026-07-27

- Zwei ℹ️-Infoschaltflächen ergänzt (PopupButton, Klick statt Hover - Symcon kennt keine nativen Mouseover-Tooltips): beim MQTT-Basistopic (muss exakt zum HeishaMon passen) und bei der Mindestleistung für die COP-Berechnung (Grund/Wirkung des Schwellwerts). Reine Formular-Politur, kein Code-/Vertragsimpact

## 1.5.0 — 2026-07-27

- `HEISHA_GetFunctions()` liefert additiv `reachable` (bool): meldet, ob die Wärmepumpe gerade erreichbar ist. `PowerID` wird bei Nichterreichbarkeit nicht zurückgesetzt, sondern friert beim letzten bekannten Wert ein — Konsumenten können damit einen veralteten Wert erkennen, statt ihn ungeprüft zu übernehmen. Vertragsversion damit `1.2`. Proaktiv gefunden bei der Prüfung gegen das Verbund-Zielbild (Zuverlässigkeit ohne KI-Krücke)

## 1.4.2 — 2026-07-27

- README: neuer Abschnitt „§14a-Stellhebel" — dokumentiert, welche bestehenden Set-Befehle (Heizstab sperren/freigeben, Quiet Mode, Sollwert-/Kurvenverschiebung) als Lasthebel für eine §14a-Steuerbox-Anbindung dienen, plus die zwei Schutzbedingungen (keine Eingriffe während Abtauung, Sterilisation nur verschieben). Reine Dokumentation, keine Code-Änderung, kein neuer Vertrag

## 1.4.1 — 2026-07-24

- Layout-Politur: Doku-Panel steht jetzt an erster Stelle (vor den Funktionsfeldern), passend zur einheitlichen NRG-Stack-Formular-Optik und zur Referenzimplementierung InverterHub

## 1.4.0 — 2026-07-24 (Formular-Optik)

- Konfigurationsmaske auf die einheitliche NRG-Stack-Formular-Optik umgestellt: „📖 Dokumentation & Hilfe“ (eingeklappt, mit Versionsnummer), „🆕 Neu in Version …“ (aufgeklappt, pro Version bestätigbar) und ein einmalig ausblendbarer Forum-Hinweis. Referenzimplementierung: InverterHub

## 1.3.0 — 2026-07-24

- `HEISHA_GetFunctions()` liefert additiv `unit` ('W'): physikalische Einheit von `PowerID`, rein informativ für Konsumenten ohne eigenes Variablenprofil auf der Presentation-basierten Leistungsvariable. Vertragsversion damit `1.1`

## 1.2.1 — 2026-07-23

- Lizenzwechsel von MIT auf **PolyForm Noncommercial License 1.0.0** (NRG-Stack-weit): private/nicht-kommerzielle Nutzung frei, gewerbliche Nutzung lizenzpflichtig. Gilt ab diesem Stand; ältere, unter MIT veröffentlichte Versionen bleiben MIT. Code und Verträge unverändert

## 1.2.0 — 2026-07-23

- `HEISHA_GetFunctions()` liefert additiv `contractVersion` (Vertragsversion `Major.Minor`, Start `1.0`) — Teil der Versionierungskonvention des NRG-Stack. Ändert die bestehenden Felder nicht
- README: Verweis auf das Suite-Manifest (welche Modulstände zusammenpassen)

## 1.1.2 — 2026-07-22

- Sprachregel des Modul-Verbunds umgesetzt: „Link/Linkstruktur" heißt jetzt durchgängig „Verknüpfung/Verknüpfungsstruktur", „Drag & Drop" wurde ersetzt. Betrifft nur Anzeigetexte — Idents, Eigenschaften und der `HEISHA_GetFunctions`-Vertrag bleiben unverändert
- Weitere Anzeigetexte eingedeutscht: Button → Schaltfläche, Slider → Schieberegler, Checkbox → Spalte/Auswahl, Update → Aktualisierung, Logging → Aufzeichnung im Archiv
- Konventionen in CLAUDE.md festgehalten (Sprachregel, Idents sind API, Zweig-Modell, Stolperfallen)

## 1.1.1 — 2026-06-18

- `HEISHA_GetFunctions()` liefert zusätzlich `Measured` (bool): unterscheidet echte Messung von der HeishaMon-Schätzung. Notwendig, weil Leistungs- und Energievariable unabhängig konfigurierbar sind und die Genauigkeit sich daher nicht aus `EnergyID` ableiten lässt

## 1.1.0 — 2026-06-18

- Neue Variable **Elektrische Leistung (gesamt)** (W): gemessener Wert des externen Stromzählers, sonst Summe der HeishaMon-Schätzwerte
- Neue Funktion `HEISHA_GetFunctions()`: meldet Art, Bezeichnung sowie Leistungs- und Energiezähler-Variable, damit andere Module (z. B. Energiefluss-Visualisierungen) die Wärmepumpe ohne manuelle Zuweisung einbinden können

## 1.0.1 — 2026-06-18 — Review-Anpassungen

- Vendor auf „HeishaMon“ gesetzt
- „Reihenfolge und Auswahl zurücksetzen“ aktualisiert nur noch die offene Konfiguration (UpdateFormField) statt die Eigenschaft direkt zu schreiben; persistiert wird erst beim Übernehmen durch den Nutzer

## 1.0 — 2026-06-18 — Erstveröffentlichung im Module Store

### Funktionen

- Anbindung eines HeishaMon an IP-Symcon über MQTT Server oder MQTT Client
- Alle HeishaMon-Datenpunkte (`main/TOP0` … `main/TOP143` sowie Optional-PCB-Topics) mit passenden Darstellungen (Temperaturen, Leistungen, Betriebsarten, Schalter)
- Automatisches Anlegen der Statusvariablen beim ersten Empfang — es erscheinen nur Datenpunkte, die die eigene Anlage liefert
- Schreibbare Werte (Solltemperaturen, Betriebsart, Flüstermodus, Powerful-Modus u. v. m.) direkt schaltbar über `<Basistopic>/commands/SetXxx`
- Datenpunkt-Auswahl in der Konfiguration: Spalten Aktiv/Name/Gruppe/Topic/Empfangen; abgewählte Datenpunkte werden ausgeblendet (Objekt-ID und Archivdaten bleiben erhalten)
- Sortierung der Datenpunkte per Drag & Drop; Variablen-Positionen und Linkstruktur folgen der Reihenfolge, sinnvolle Standard-Sortierung nach Gruppen
- Optionale gruppierte Linkstruktur (Betrieb, Heizen, Kühlen, Warmwasser, Leistung & COP, Gerätewerte, Anlagenkonfiguration, Optional-PCB) an frei wählbarer Zielkategorie
- COP-Berechnung: HeishaMon-Schätzung, gemessener COP über externen Stromzähler (z. B. Shelly 3EM) sowie Tages-Arbeitszahl mit Wärmemengen-Integration und exakter Stromenergie aus dem Zählerstand
- Verfügbarkeitsanzeige über das LWT-Topic
- Skript-Funktionen `HEISHA_SendSetCommand` und `HEISHA_SetCurves` (Heiz-/Kühlkurven als JSON)
- Vollständige deutsche Übersetzung
- Button „Variablennamen aktualisieren“: übernimmt verbesserte Übersetzungen für Variablen mit Standardnamen, selbst vergebene Namen bleiben erhalten
