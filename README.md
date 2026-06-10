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

## Befehle per Skript

Alle HeishaMon-Befehle (siehe [MQTT-Topics](https://github.com/heishamon/HeishaMon/blob/master/MQTT-Topics.md)) lassen sich auch per Skript senden:

```php
// Beliebiger Set-Befehl
DGHEISHA_SendSetCommand(12345, 'SetQuietMode', '2');

// Heiz-/Kühlkurven setzen (SET16, JSON laut HeishaMon-Doku)
DGHEISHA_SetCurves(12345, '{"zone1":{"heat":{"target":{"high":35,"low":25},"outside":{"high":15,"low":-15}}}}');
```

## Hinweise

- Zustands-Topics können laut HeishaMon-Doku in Ausnahmefällen den Wert `-1` (unbekannt) liefern; das Modul behandelt dies bei Schaltzuständen als „Aus".
- Unbekannte Topics (z. B. `stats`, `1wire`, `s0`) werden ignoriert; mit der Option **Debug: Unbekannte Topics** lassen sie sich im Debug-Fenster anzeigen.
