# Panasonic-HeishaMon

IP-Symcon Modul zur Anbindung eines [HeishaMon](https://github.com/heishamon/HeishaMon) an eine Panasonic Aquarea Wärmepumpe über MQTT.

## Funktionsumfang

- Empfängt alle HeishaMon-Datenpunkte (`main/TOP0` … `main/TOP143` sowie die Optional-PCB-Topics) über den IP-Symcon MQTT Server oder MQTT Client.
- Legt Statusvariablen **automatisch** an, sobald der HeishaMon das jeweilige Topic sendet — es erscheinen also nur die Datenpunkte, die die eigene Wärmepumpe tatsächlich liefert.
- Passende Darstellungen: Temperaturen mit °C, Leistungen mit W, Betriebsarten als Auswahlliste, Zustände als Schalter.
- Schreibbare Werte (z. B. Warmwasser-Solltemperatur, Betriebsart, Flüstermodus, Powerful-Modus) sind direkt schaltbar und werden über `<Basistopic>/commands/SetXxx` an den HeishaMon gesendet.
- Verfügbarkeitsanzeige über das LWT-Topic (Variable „Erreichbar").
- Vollständige deutsche Übersetzung über `locale.json`.

## Voraussetzungen

- IP-Symcon ab Version 9.0
- HeishaMon mit aktivierter MQTT-Anbindung
- Eingerichteter MQTT Server (oder MQTT Client) in IP-Symcon, mit dem der HeishaMon verbunden ist

## Installation

Über die Modulverwaltung (Kern Instanzen → Modules) die URL dieses Repositories hinzufügen:

```
https://github.com/DG65/HeishaMon-IPS
```

## Einrichtung

1. Instanz **HeishaMon-IPS** anlegen.
2. Als übergeordnete Instanz den MQTT Server bzw. MQTT Client auswählen, mit dem der HeishaMon verbunden ist.
3. **MQTT Basistopic** eintragen (Standard: `panasonic_heat_pump`, muss dem im HeishaMon konfigurierten Basistopic entsprechen).
4. Übernehmen — die Variablen werden automatisch angelegt, sobald der HeishaMon Daten sendet (spätestens nach dem nächsten Update-Intervall des HeishaMon).

## Datenpunkte auswählen

In der Instanz-Konfiguration listet die Tabelle **Datenpunkte** alle bekannten HeishaMon-Topics. Die Spalte **Empfangen** zeigt, welche Topics die eigene Anlage tatsächlich sendet (empfangene stehen oben). Über die Checkbox **Aktiv** lassen sich einzelne Datenpunkte abwählen — deren Variablen werden **ausgeblendet**. Objekt-ID, Wert-Aktualisierung und Archivdaten bleiben dabei erhalten; beim erneuten Aktivieren wird die Variable einfach wieder eingeblendet. Nur Datenpunkte, deren Variable noch gar nicht existiert, werden bei deaktivierter Checkbox auch nicht angelegt.

## Linkstruktur (gruppierte Ansicht)

Statusvariablen müssen in IP-Symcon flach unter der Instanz liegen. Für eine gruppierte Ansicht (z. B. in der Visualisierung) kann das Modul optional eine **Linkstruktur** pflegen: Im Panel **Linkstruktur** die Option **Linkstruktur erzeugen** aktivieren und einen **Zielort** wählen. Das Modul legt dort einen Kategoriebaum an:

```
<Zielort>
└── <Instanzname>
    ├── Betrieb
    ├── Heizen
    ├── Kühlen
    ├── Warmwasser
    ├── Leistung & COP
    ├── Gerätewerte
    ├── Anlagenkonfiguration
    └── Optionale Platine
```

Darin liegen Links auf alle **aktiven** Datenpunkte (inklusive Schaltbarkeit — ein Link auf die Warmwasser-Solltemperatur bleibt z. B. ein Slider). Wird ein Datenpunkt in der Liste abgewählt, verschwindet sein Link automatisch; neu empfangene Datenpunkte werden sofort einsortiert. Leere Gruppen werden entfernt.

## COP / Arbeitszahl

Das Modul berechnet den COP auf zwei Wegen:

- **COP (HeishaMon-Schätzung)** — automatisch aus den HeishaMon-eigenen Werten (thermische Leistung / elektrische Aufnahme über alle Betriebsarten). Keine Konfiguration nötig, aber grob, da Panasonic die Aufnahme nur in ~200-W-Stufen schätzt.
- **COP (gemessen)** — über einen externen Stromzähler (z. B. Shelly 3EM auf der Wärmepumpen-Phase). Dazu im Konfigurationspanel **COP / Arbeitszahl** die Leistungs-Variable (W) auswählen; der COP wird bei jeder Wertänderung neu berechnet. Unterhalb der **Mindestleistung** (Standard 100 W, gegen Standby-Rauschen) wird 0 ausgegeben.

Wird zusätzlich die **Energiezähler-Variable (kWh)** ausgewählt, berechnet das Modul Tageswerte:

- **Stromverbrauch heute** — exakt aus dem Zählerstand (Basis wird um Mitternacht neu gesetzt, ein Zähler-Reset wird abgefangen)
- **Wärmemenge heute** — Integration der thermischen Leistung über einen 60-Sekunden-Timer (Zwischenstände überleben einen IPS-Neustart)
- **Arbeitszahl heute** — Verhältnis der beiden; mit Archiv-Logging entsteht daraus die Langzeit-Historie

Hinweis: Läuft der Heizstab, steckt seine Wärme in der gemessenen thermischen Leistung. Da nur die Wärmepumpen-Phase im Nenner steht, fällt der COP in diesen Phasen optisch zu gut aus — für die reine Verdichter-Bewertung ist das aber genau richtig.

## Befehle per Skript

Alle HeishaMon-Befehle (siehe [MQTT-Topics](https://github.com/heishamon/HeishaMon/blob/master/MQTT-Topics.md)) lassen sich auch per Skript senden:

```php
// Beliebiger Set-Befehl
HEISHA_SendSetCommand(12345, 'SetQuietMode', '2');

// Heiz-/Kühlkurven setzen (SET16, JSON laut HeishaMon-Doku)
HEISHA_SetCurves(12345, '{"zone1":{"heat":{"target":{"high":35,"low":25},"outside":{"high":15,"low":-15}}}}');
```

## Hinweise

- Zustands-Topics können laut HeishaMon-Doku in Ausnahmefällen den Wert `-1` (unbekannt) liefern; das Modul behandelt dies bei Schaltzuständen als „Aus".
- Unbekannte Topics (z. B. `stats`, `1wire`, `s0`) werden ignoriert; mit der Option **Debug: Unbekannte Topics** lassen sie sich im Debug-Fenster anzeigen.
