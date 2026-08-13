<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HeishaMonTopics.php';

/**
 * HeishaMon
 *
 * Bindet einen HeishaMon (https://github.com/heishamon/HeishaMon) an IP-Symcon an.
 * Das Modul wird unter einem MQTT Server / MQTT Client (Splitter) angelegt und
 * erzeugt fuer jedes empfangene Topic automatisch eine passende Statusvariable.
 * Schreibbare Werte werden ueber <Basistopic>/commands/SetXxx an den HeishaMon gesendet.
 *
 * Wichtig: Der Klassenname muss exakt dem "name" in der module.json entsprechen
 * und ein gueltiger PHP-Klassenname sein (keine Bindestriche).
 */
class HeishaMon extends IPSModule
{
    //Einheitliche Formular-Optik (NRG-Stack-Konvention, siehe SUITE.md): Neu-in-Version-Panel
    //je Release hochzaehlen und die Highlights seit dem letzten Store-Stand eintragen.
    private const NEWS_VERSION = '1.6.0';
    private const NEWS_ITEMS = [
        'New: 1-Wire temperature sensors (e.g. DS18B20) connected to the HeishaMon are now detected automatically - no more manual MQTT variable setup needed.'
    ];
    //Verweist derzeit auf die allgemeine Modul-Kategorie im Symcon-Forum, nicht auf einen
    //bestaetigten HeishaMon-eigenen Thread - bei Bedarf durch den konkreten Thread ersetzen.
    private const FORUM_URL = 'https://community.symcon.de/c/erweiterungen/php-module-entwicklung/21';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('MQTTTopic', 'panasonic_heat_pump');
        $this->RegisterPropertyBoolean('DebugUnknownTopics', false);

        //Formular-Hinweise: "Neu in Version" (pro Version bestaetigt) und Forum-Hinweis (einmalig)
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintDismissed', false);

        //Auswahl der gewuenschten Datenpunkte (leer = alle aktiv)
        $this->RegisterPropertyString('VariableList', '[]');
        $this->RegisterAttributeString('SeenTopics', '[]');

        //Optionale, gruppierte Linkstruktur (nach Vorbild des Tessie-Moduls)
        $this->RegisterPropertyBoolean('CreateLinks', false);
        $this->RegisterPropertyInteger('LinksLocation', 0);

        //1-Wire-Temperatursensoren (z.B. DS18B20) am HeishaMon: dynamisches Topic pro
        //Sensoradresse, daher eigene Erkennungs-/Benennungsliste statt der festen TopicMap
        $this->RegisterPropertyString('OneWireSensors', '[]');
        $this->RegisterAttributeString('SeenOneWire', '[]');

        //COP / Arbeitszahl: externe Messung ueber Stromzaehler (z.B. Shelly 3EM, Phase der Waermepumpe)
        $this->RegisterPropertyInteger('PowerVariable', 0);
        $this->RegisterPropertyInteger('EnergyVariable', 0);
        $this->RegisterPropertyFloat('COPMinPower', 100);

        //Persistente Zwischenstaende der Tagesberechnung (ueberleben einen IPS-Neustart)
        $this->RegisterAttributeString('CurrentDay', '');
        $this->RegisterAttributeFloat('EnergyCounterBase', -1);
        $this->RegisterAttributeFloat('HeatWhToday', 0);
        $this->RegisterAttributeInteger('LastIntegration', 0);

        $this->RegisterTimer('COPUpdate', 0, 'HEISHA_UpdateCOPCalculation($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('Reachable', $this->Translate('Reachable'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                [
                    'Value'       => true,
                    'Caption'     => 'Online',
                    'IconActive'  => false,
                    'Icon'        => '',
                    'ColorActive' => true,
                    'ColorValue'  => 65280
                ],
                [
                    'Value'       => false,
                    'Caption'     => 'Offline',
                    'IconActive'  => false,
                    'Icon'        => '',
                    'ColorActive' => true,
                    'ColorValue'  => 16711680
                ]
            ])
        ], 0);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        //Zeilen in der gespeicherten (per Drag & Drop sortierten) Reihenfolge, Versionsnummer im Doku-Panel
        $this->patchFormElement($form['elements'], 'VariableList', function (&$element) {
            $element['values'] = $this->buildVariableListRows($this->getOrderedTopics(), $this->getSelectionMap());
        });
        $this->patchFormElement($form['elements'], 'OneWireList', function (&$element) {
            $element['values'] = $this->buildOneWireListRows();
        });
        $this->patchFormElement($form['elements'], 'DocsPanel', function (&$element) {
            $element['caption'] = $this->Translate('📖  Documentation & help') . ' (' . $this->moduleVersion() . ')';
        });

        //Forum-Hinweis am Ende, solange nicht bestaetigt
        if (!$this->ReadAttributeBoolean('ForumHintDismissed')) {
            $form['elements'][] = $this->buildForumHint();
        }

        //"Neu in Version" ganz oben, solange die aktuelle Version nicht bestaetigt wurde
        $newsPanel = $this->buildNewsPanel();
        if ($newsPanel !== null) {
            array_unshift($form['elements'], $newsPanel);
        }

        return json_encode($form);
    }

    /**
     * Sucht rekursiv (auch innerhalb von Panels/RowLayouts) das Formularelement mit dem
     * uebergebenen Namen und wendet den Callback per Referenz darauf an.
     */
    private function patchFormElement(array &$elements, string $name, callable $callback)
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $callback($element);
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->patchFormElement($element['items'], $name, $callback);
            }
        }
        unset($element);
    }

    private function moduleVersion(): string
    {
        $library = json_decode(file_get_contents(__DIR__ . '/../library.json'), true);
        return 'v' . ($library['version'] ?? '?');
    }

    /**
     * "Neu in Version X.Y"-Panel: aufgeklappt, bis der Nutzer "Verstanden" klickt.
     * Danach (auch nach einem IPS-Neustart) bleibt es fuer diese Version verborgen.
     */
    private function buildNewsPanel(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => $this->Translate($line)];
        }
        $items[] = [
            'type'    => 'Button',
            'caption' => $this->Translate('Understood - do not show again'),
            'onClick' => 'HEISHA_AckNews($id);'
        ];
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'NewsPanel',
            'caption'  => $this->Translate('🆕 New in version') . ' ' . self::NEWS_VERSION,
            'expanded' => true,
            'items'    => $items
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    /**
     * Einmaliger Hinweis auf das Symcon-Forum, solange nicht ausgeblendet.
     */
    private function buildForumHint(): array
    {
        return [
            'type' => 'RowLayout',
            'name' => 'ForumHint',
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('Feedback and suggestions are welcome in the Symcon forum:')],
                ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_URL],
                [
                    'type'    => 'Button',
                    'caption' => $this->Translate('Do not show again'),
                    'onClick' => 'HEISHA_DismissForumHint($id);'
                ]
            ]
        ];
    }

    public function DismissForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintDismissed', true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    /**
     * Baut die Zeilen der Datenpunkt-Liste in der uebergebenen Reihenfolge.
     * $selection liefert den Aktiv-Zustand; nicht gelistete Topics gelten als aktiv.
     */
    private function buildVariableListRows(array $orderedTopics, array $selection): array
    {
        $seenTopics = json_decode($this->ReadAttributeString('SeenTopics'), true) ?: [];
        $topics = HeishaMonTopics::topics();
        $rows = [];
        foreach ($orderedTopics as $topic) {
            $rows[] = [
                'Selected' => $selection[$topic] ?? true,
                'Caption'  => $this->Translate($topics[$topic]['cap']),
                'Group'    => $this->Translate(HeishaMonTopics::groupForTopic($topic)),
                'Topic'    => $topic,
                'Received' => in_array($topic, $seenTopics) ? $this->Translate('Yes') : ''
            ];
        }
        return $rows;
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        if ($baseTopic == '') {
            //Nichts empfangen, solange kein Topic konfiguriert ist
            $this->SetReceiveDataFilter('(?!)');
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        //Slashes muessen escaped werden, da der Topic im JSON-Datenpaket escaped ankommt
        $filterTopic = str_replace('/', '\\\\/', $baseTopic);
        $this->SetReceiveDataFilter('.*' . $filterTopic . '.*');
        $this->SetStatus(IS_ACTIVE);

        //Variablen der COP-Berechnung anlegen bzw. entfernen, je nach Konfiguration
        $powerID = $this->ReadPropertyInteger('PowerVariable');
        $energyID = $this->ReadPropertyInteger('EnergyVariable');
        $copPresentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS'       => 2
        ];
        $kwhPresentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' kWh',
            'DIGITS'       => 2
        ];
        //Elektrische Gesamtleistung: gemessen, wenn ein Zaehler konfiguriert ist, sonst HeishaMon-Schaetzung.
        //Wird immer gefuehrt und ist der Anknuepfungspunkt fuer andere Module (siehe GetFunctions).
        $this->maintainCalculationVariable('Power_Total', $this->Translate('Electrical power (total)'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' W',
            'DIGITS'       => 0
        ], 199, true);
        $this->maintainCalculationVariable('COP_Measured', $this->Translate('COP (measured)'), $copPresentation, 201, $powerID > 0);
        $this->maintainCalculationVariable('Heat_Energy_Today', $this->Translate('Heat energy today'), $kwhPresentation, 202, $energyID > 0);
        $this->maintainCalculationVariable('Power_Energy_Today', $this->Translate('Energy consumption today'), $kwhPresentation, 203, $energyID > 0);
        $this->maintainCalculationVariable('COP_Today', $this->Translate('Performance factor today'), $copPresentation, 204, $energyID > 0);
        $this->updateTotalPower();

        $this->SetTimerInterval('COPUpdate', $energyID > 0 ? 60000 : 0);

        $this->SendDebug('VariableList', $this->ReadPropertyString('VariableList'), 0);

        //Praesentationen bestehender Variablen auffrischen (z.B. neue Enum-Optionen nach Modul-Update);
        //geschrieben wird nur bei tatsaechlicher Aenderung, sonst loest jedes Uebernehmen einen
        //Update-Sturm fuer alle Variablen aus, der die Konsole zum Absturz bringen kann
        $topics = HeishaMonTopics::topics();
        foreach ($topics as $topic => $definition) {
            $this->maintainTopicVariable(HeishaMonTopics::identFromTopic($topic), $topic, $definition, true);
        }

        //Abgewaehlte Datenpunkte ausblenden statt loeschen (Objekt-ID und Archivdaten bleiben
        //erhalten) und Positionen gemaess der Listen-Reihenfolge nachfuehren
        $selection = $this->getSelectionMap();
        $positions = $this->getPositionMap();
        foreach ($topics as $topic => $definition) {
            $variableID = @$this->GetIDForIdent(HeishaMonTopics::identFromTopic($topic));
            if ($variableID === false) {
                continue;
            }
            $object = IPS_GetObject($variableID);
            $hidden = !($selection[$topic] ?? true);
            if ($object['ObjectIsHidden'] != $hidden) {
                IPS_SetHidden($variableID, $hidden);
            }
            if ($object['ObjectPosition'] != $positions[$topic]) {
                IPS_SetPosition($variableID, $positions[$topic]);
            }
        }

        //1-Wire-Sensoren: Namen/Darstellung bestehender Variablen auffrischen, abgewaehlte
        //ausblenden statt loeschen, Positionen gemaess der Listen-Reihenfolge nachfuehren
        $oneWireSelection = [];
        foreach ($this->getOneWireConfigMap() as $address => $row) {
            $oneWireSelection[$address] = boolval($row['Selected'] ?? true);
        }
        $oneWirePositions = $this->getOneWirePositionMap();
        foreach ($this->getOrderedOneWireAddresses() as $address) {
            $this->maintainOneWireVariable($address, true);
            $variableID = @$this->GetIDForIdent('OneWire_' . $address);
            if ($variableID === false) {
                continue;
            }
            $object = IPS_GetObject($variableID);
            $hidden = !($oneWireSelection[$address] ?? true);
            if ($object['ObjectIsHidden'] != $hidden) {
                IPS_SetHidden($variableID, $hidden);
            }
            if ($object['ObjectPosition'] != $oneWirePositions[$address]) {
                IPS_SetPosition($variableID, $oneWirePositions[$address]);
            }
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->registerExternalMessages();
            $this->maintainLinkTree();
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->registerExternalMessages();
                $this->maintainLinkTree();
                break;
            case VM_UPDATE:
                if ($SenderID == $this->ReadPropertyInteger('PowerVariable')) {
                    $this->updateMeasuredCOP(floatval($Data[0]));
                    $this->updateTotalPower();
                } elseif ($SenderID == $this->ReadPropertyInteger('EnergyVariable')) {
                    $this->updateDailyValues();
                }
                break;
        }
    }

    /**
     * Timer-Funktion: integriert die thermische Leistung zur Tageswaermemenge
     * und aktualisiert die Tages-Arbeitszahl.
     */
    public function UpdateCOPCalculation()
    {
        $now = time();
        $today = date('Y-m-d', $now);

        //Tageswechsel: Zaehlerbasis neu setzen, Waermemenge zuruecksetzen
        if ($this->ReadAttributeString('CurrentDay') != $today) {
            $this->WriteAttributeString('CurrentDay', $today);
            $this->WriteAttributeFloat('HeatWhToday', 0);
            $this->WriteAttributeInteger('LastIntegration', $now);
            $energyID = $this->ReadPropertyInteger('EnergyVariable');
            if ($energyID > 0 && IPS_VariableExists($energyID)) {
                $this->WriteAttributeFloat('EnergyCounterBase', floatval(GetValue($energyID)));
            }
        }

        //Thermische Leistung ueber die Zeit integrieren (Trapez waere uebertrieben, Rechteck reicht)
        $last = $this->ReadAttributeInteger('LastIntegration');
        $dt = $now - $last;
        if ($last > 0 && $dt > 0 && $dt <= 600) {
            $heatWh = $this->ReadAttributeFloat('HeatWhToday') + $this->getThermalPower() * $dt / 3600;
            $this->WriteAttributeFloat('HeatWhToday', $heatWh);
        }
        $this->WriteAttributeInteger('LastIntegration', $now);

        $this->updateDailyValues();
    }

    /**
     * Auswahl aus der Konfigurationsliste: Topic => aktiv. Nicht gelistete Topics gelten als aktiv.
     */
    private function getSelectionMap(): array
    {
        $map = [];
        $rows = json_decode($this->ReadPropertyString('VariableList'), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Topic'])) {
                    $map[$row['Topic']] = boolval($row['Selected'] ?? true);
                }
            }
        }
        return $map;
    }

    private function isTopicSelected(string $topic): bool
    {
        $map = $this->getSelectionMap();
        return $map[$topic] ?? true;
    }

    /**
     * Alle Topics in Anzeige-Reihenfolge: zuerst die gespeicherte (per Drag & Drop
     * sortierte) Liste, danach noch unbekannte Topics in TopicMap-Reihenfolge.
     */
    private function getOrderedTopics(): array
    {
        $all = HeishaMonTopics::defaultOrder();
        $saved = [];
        $rows = json_decode($this->ReadPropertyString('VariableList'), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Topic']) && in_array($row['Topic'], $all, true)) {
                    $saved[] = $row['Topic'];
                }
            }
        }
        foreach ($all as $topic) {
            if (!in_array($topic, $saved, true)) {
                $saved[] = $topic;
            }
        }
        return $saved;
    }

    /**
     * Topic => Variablen-Position, abgeleitet aus der Listen-Reihenfolge.
     */
    private function getPositionMap(): array
    {
        $map = [];
        $position = 10;
        foreach ($this->getOrderedTopics() as $topic) {
            $map[$topic] = $position;
            $position += 10;
        }
        return $map;
    }

    /**
     * Setzt Reihenfolge und Auswahl der Datenpunkte in der offenen Konfiguration
     * auf den Standard zurueck (Standard-Reihenfolge, alle aktiv). Persistiert wird
     * erst, wenn der Nutzer selbst "Aenderungen uebernehmen" klickt.
     */
    public function ResetVariableList()
    {
        $rows = $this->buildVariableListRows(HeishaMonTopics::defaultOrder(), []);
        $this->UpdateFormField('VariableList', 'values', json_encode($rows));
    }

    /**
     * Frueher verwendete Standard-Uebersetzungen => Caption-Key.
     * Wird von UpdateVariableNames genutzt, um nur unveraenderte Standardnamen umzubenennen.
     */
    private const RENAMED_CAPTIONS = [
        'Rohrtemperatur außen'                        => 'Outside pipe temperature',
        'Rohrtemperatur innen'                        => 'Inside pipe temperature',
        'Pumpenleistung'                              => 'Pump duty',
        'Maximale Pumpenleistung'                     => 'Maximum pump duty',
        'Heizen Delta'                                => 'Heat delta',
        'Kühlen Delta'                                => 'Cool delta',
        'Warmwasser Heizdelta'                        => 'DHW heating delta',
        'Zone 1 Heizanforderung'                      => 'Zone 1 heat request temperature',
        'Zone 1 Kühlanforderung'                      => 'Zone 1 cool request temperature',
        'Zone 2 Heizanforderung'                      => 'Zone 2 heat request temperature',
        'Zone 2 Kühlanforderung'                      => 'Zone 2 cool request temperature',
        'Raum Urlaubsverschiebung'                    => 'Room holiday shift temperature',
        'Warmwasser Urlaubsverschiebung'              => 'DHW holiday shift temperature',
        'Solar Ein-Delta'                             => 'Solar on delta',
        'Solar Aus-Delta'                             => 'Solar off delta',
        'Einschaltzyklen'                             => 'Heatpump starts',
        'Heizstab Warmwasser erlaubt'                 => 'DHW heater allowed',
        'Heizstab Heizung erlaubt'                    => 'Room heater allowed',
        'Bivalent erweitert: Heizungs-Steuerung'      => 'Bivalent advanced heat control',
        'Bivalent erweitert: Warmwasser-Steuerung'    => 'Bivalent advanced DHW control',
        'Bivalent erweitert: Warmwasser-Verzögerung'  => 'Bivalent advanced DHW delay',
        'Bivalent erweitert: Startverzögerung'        => 'Bivalent advanced start delay',
        'Bivalent erweitert: Starttemperatur'         => 'Bivalent advanced start temperature',
        'Bivalent erweitert: Stoppverzögerung'        => 'Bivalent advanced stop delay',
        'Bivalent erweitert: Stopptemperatur'         => 'Bivalent advanced stop temperature'
    ];

    /**
     * Benennt Variablen mit altem Standardnamen auf die aktuelle Uebersetzung um.
     * Selbst vergebene Namen werden nicht angefasst.
     */
    public function UpdateVariableNames()
    {
        $count = 0;
        foreach (HeishaMonTopics::topics() as $topic => $definition) {
            $variableID = @$this->GetIDForIdent(HeishaMonTopics::identFromTopic($topic));
            if ($variableID === false) {
                continue;
            }
            $target = $this->Translate($definition['cap']);
            $current = IPS_GetName($variableID);
            if ($current == $target) {
                continue;
            }
            //nur Standardnamen umbenennen: englischer Caption-Key oder bekannte alte Uebersetzung
            $isOldDefault = ($current == $definition['cap'])
                || (isset(self::RENAMED_CAPTIONS[$current]) && self::RENAMED_CAPTIONS[$current] == $definition['cap']);
            if ($isOldDefault) {
                IPS_SetName($variableID, $target);
                $count++;
            }
        }
        //Linknamen in der Linkstruktur nachziehen
        $this->maintainLinkTree();
        echo sprintf($this->Translate('%d variable names updated'), $count);
    }

    /**
     * Merkt sich, welche Topics die Anlage tatsaechlich sendet (Spalte "Empfangen" in der Konfiguration).
     */
    private function rememberSeenTopic(string $topic)
    {
        $seen = json_decode($this->ReadAttributeString('SeenTopics'), true) ?: [];
        if (!in_array($topic, $seen)) {
            $seen[] = $topic;
            $this->WriteAttributeString('SeenTopics', json_encode($seen));
        }
    }

    /**
     * Pflegt die optionale Linkstruktur: <Zielort>/<Instanzname>/<Gruppe>/<Link auf Variable>.
     * Nur aktivierte, vorhandene Datenpunkte werden verlinkt; Links abgewaehlter
     * Datenpunkte werden entfernt, leere Gruppen geloescht.
     */
    private function maintainLinkTree()
    {
        if (!$this->ReadPropertyBoolean('CreateLinks')) {
            return;
        }
        $parentID = $this->ReadPropertyInteger('LinksLocation');
        if ($parentID <= 0 || !IPS_ObjectExists($parentID)) {
            return;
        }

        //Wurzelkategorie pro Instanz
        $rootIdent = 'HEISHA_LINKROOT_' . $this->InstanceID;
        $rootID = @IPS_GetObjectIDByIdent($rootIdent, $parentID);
        if ($rootID === false) {
            $rootID = IPS_CreateCategory();
            IPS_SetParent($rootID, $parentID);
            IPS_SetIdent($rootID, $rootIdent);
            IPS_SetName($rootID, IPS_GetName($this->InstanceID));
        }

        //Gewuenschte Links je Gruppe zusammenstellen, in der Reihenfolge der Datenpunkt-Liste
        $selection = $this->getSelectionMap();
        $desired = [];
        foreach ($this->getOrderedTopics() as $topic) {
            if (!($selection[$topic] ?? true)) {
                continue;
            }
            $variableID = @$this->GetIDForIdent(HeishaMonTopics::identFromTopic($topic));
            if ($variableID === false) {
                continue;
            }
            $desired[HeishaMonTopics::groupForTopic($topic)]['HEISHA_LNK_' . HeishaMonTopics::identFromTopic($topic)] = $variableID;
        }
        //Modul-eigene Variablen ausserhalb der TopicMap
        $extraIdents = [
            'Reachable'          => 'Operation',
            'Power_Total'        => 'Power & COP',
            'COP_Internal'       => 'Power & COP',
            'COP_Measured'       => 'Power & COP',
            'Heat_Energy_Today'  => 'Power & COP',
            'Power_Energy_Today' => 'Power & COP',
            'COP_Today'          => 'Power & COP'
        ];
        foreach ($extraIdents as $ident => $group) {
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID !== false) {
                $desired[$group]['HEISHA_LNK_' . $ident] = $variableID;
            }
        }
        //1-Wire-Sensoren, ebenfalls nur aktivierte
        foreach ($this->getOrderedOneWireAddresses() as $address) {
            if (!$this->isOneWireSelected($address)) {
                continue;
            }
            $variableID = @$this->GetIDForIdent('OneWire_' . $address);
            if ($variableID !== false) {
                $desired['1-Wire sensors']['HEISHA_LNK_OneWire_' . $address] = $variableID;
            }
        }

        foreach (HeishaMonTopics::groupOrder() as $index => $group) {
            $groupIdent = 'HEISHA_GRP_' . preg_replace('/[^A-Za-z0-9]/', '', $group);
            $categoryID = @IPS_GetObjectIDByIdent($groupIdent, $rootID);
            $links = $desired[$group] ?? [];

            if ($categoryID === false) {
                if (count($links) == 0) {
                    continue;
                }
                $categoryID = IPS_CreateCategory();
                IPS_SetParent($categoryID, $rootID);
                IPS_SetIdent($categoryID, $groupIdent);
                IPS_SetName($categoryID, $this->Translate($group));
                IPS_SetPosition($categoryID, $index);
            }

            //verwaltete Links entfernen, deren Datenpunkt abgewaehlt oder verschwunden ist
            foreach (IPS_GetChildrenIDs($categoryID) as $childID) {
                $child = IPS_GetObject($childID);
                if ($child['ObjectType'] == OBJECTTYPE_LINK && strpos($child['ObjectIdent'], 'HEISHA_LNK_') === 0 && !isset($links[$child['ObjectIdent']])) {
                    IPS_DeleteLink($childID);
                }
            }

            $position = 0;
            foreach ($links as $linkIdent => $variableID) {
                $linkID = @IPS_GetObjectIDByIdent($linkIdent, $categoryID);
                if ($linkID === false) {
                    $linkID = IPS_CreateLink();
                    IPS_SetParent($linkID, $categoryID);
                    IPS_SetIdent($linkID, $linkIdent);
                }
                //nur bei Abweichung schreiben, um keine unnoetigen Objekt-Updates auszuloesen
                if (IPS_GetLink($linkID)['TargetID'] != $variableID) {
                    IPS_SetLinkTargetID($linkID, $variableID);
                }
                if (IPS_GetName($linkID) != IPS_GetName($variableID)) {
                    IPS_SetName($linkID, IPS_GetName($variableID));
                }
                if (IPS_GetObject($linkID)['ObjectPosition'] != $position) {
                    IPS_SetPosition($linkID, $position);
                }
                $position++;
            }

            //leere Gruppe aufraeumen
            if (count($links) == 0 && count(IPS_GetChildrenIDs($categoryID)) == 0) {
                IPS_DeleteCategory($categoryID);
            }
        }
    }

    private function registerExternalMessages()
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            if (in_array(VM_UPDATE, $messages)) {
                $this->UnregisterMessage($senderID, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        foreach (['PowerVariable', 'EnergyVariable'] as $property) {
            $variableID = $this->ReadPropertyInteger($property);
            if ($variableID > 0 && IPS_VariableExists($variableID)) {
                $this->RegisterMessage($variableID, VM_UPDATE);
                $this->RegisterReference($variableID);
            }
        }
    }

    /**
     * Summe der thermischen Leistung (Heizen + Kuehlen + Warmwasser) in Watt.
     */
    private function getThermalPower(): float
    {
        $sum = 0.0;
        foreach (['Heat_Power_Production', 'Cool_Power_Production', 'DHW_Power_Production'] as $ident) {
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID !== false) {
                $sum += floatval(GetValue($variableID));
            }
        }
        return $sum;
    }

    private function getElectricalPower(): float
    {
        $sum = 0.0;
        foreach (['Heat_Power_Consumption', 'Cool_Power_Consumption', 'DHW_Power_Consumption'] as $ident) {
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID !== false) {
                $sum += floatval(GetValue($variableID));
            }
        }
        return $sum;
    }

    /**
     * COP aus den HeishaMon-eigenen Schaetzwerten, bei jedem Empfang der Leistungs-Topics.
     */
    private function updateInternalCOP()
    {
        $ident = 'COP_Internal';
        if (@$this->GetIDForIdent($ident) === false) {
            $this->MaintainVariable($ident, $this->Translate('COP (HeishaMon estimate)'), VARIABLETYPE_FLOAT, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'DIGITS'       => 2
            ], 200, true);
        }
        $consumption = $this->getElectricalPower();
        $this->SetValue($ident, $consumption > 0 ? round($this->getThermalPower() / $consumption, 2) : 0.0);
    }

    /**
     * COP aus der gemessenen elektrischen Leistung des Stromzaehlers.
     */
    private function updateMeasuredCOP(float $electricalPower)
    {
        if (@$this->GetIDForIdent('COP_Measured') === false) {
            return;
        }
        $minPower = $this->ReadPropertyFloat('COPMinPower');
        $cop = 0.0;
        if ($electricalPower >= $minPower) {
            //Obergrenze gegen Ausreisser beim Anlaufen/Takten
            $cop = round(min($this->getThermalPower() / $electricalPower, 15), 2);
        }
        $this->SetValue('COP_Measured', $cop);
    }

    /**
     * Tageswerte: Stromverbrauch aus dem Energiezaehler, Waermemenge aus der Integration,
     * daraus die Tages-Arbeitszahl.
     */
    private function updateDailyValues()
    {
        $energyID = $this->ReadPropertyInteger('EnergyVariable');
        if ($energyID <= 0 || !IPS_VariableExists($energyID) || @$this->GetIDForIdent('COP_Today') === false) {
            return;
        }

        $counter = floatval(GetValue($energyID));
        $base = $this->ReadAttributeFloat('EnergyCounterBase');
        //Erststart oder Zaehler wurde zurueckgesetzt/getauscht
        if ($base < 0 || $counter < $base) {
            $base = $counter;
            $this->WriteAttributeFloat('EnergyCounterBase', $base);
        }

        $electricalKwh = $counter - $base;
        $heatKwh = $this->ReadAttributeFloat('HeatWhToday') / 1000;

        $this->SetValue('Power_Energy_Today', round($electricalKwh, 3));
        $this->SetValue('Heat_Energy_Today', round($heatKwh, 3));
        //Erst ab einer Mindestenergie rechnen, sonst dominiert das Rauschen
        $this->SetValue('COP_Today', $electricalKwh >= 0.05 ? round($heatKwh / $electricalKwh, 2) : 0.0);
    }

    public function ReceiveData($JSONString)
    {
        $buffer = json_decode($JSONString, true);
        if (!is_array($buffer) || !array_key_exists('Topic', $buffer)) {
            return '';
        }

        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        $topic = $buffer['Topic'];
        $payload = strval($buffer['Payload']);

        if (strpos($topic, $baseTopic . '/') !== 0) {
            return '';
        }
        $subTopic = substr($topic, strlen($baseTopic) + 1);

        //Verfuegbarkeit (Last Will Topic)
        if ($subTopic == 'LWT') {
            $this->SetValue('Reachable', $payload == 'Online');
            return '';
        }

        //1-Wire-Sensoren (z.B. DS18B20) senden auf einem dynamischen, adressabhaengigen
        //Topic statt der festen TopicMap - eigene Erkennung und Variablenpflege.
        if (preg_match('/^1wire\/([0-9a-fA-F]+)$/', $subTopic, $matches)) {
            $this->receiveOneWire(strtoupper($matches[1]), $payload);
            return '';
        }

        $topics = HeishaMonTopics::topics();
        if (!array_key_exists($subTopic, $topics)) {
            if ($this->ReadPropertyBoolean('DebugUnknownTopics')) {
                $this->SendDebug('Unknown Topic', $subTopic . ' = ' . $payload, 0);
            }
            return '';
        }

        $this->rememberSeenTopic($subTopic);

        $definition = $topics[$subTopic];
        $ident = HeishaMonTopics::identFromTopic($subTopic);

        //Bestehende (auch ausgeblendete) Variablen werden weiter aktualisiert,
        //neue entstehen nur fuer aktivierte Datenpunkte
        if (@$this->GetIDForIdent($ident) === false) {
            if (!$this->isTopicSelected($subTopic)) {
                return '';
            }
            $this->maintainTopicVariable($ident, $subTopic, $definition);
            //neue Variable sofort in die Linkstruktur aufnehmen
            $this->maintainLinkTree();
        }

        switch ($definition['kind']) {
            case 'bool':
                $this->SetValue($ident, intval($payload) == 1);
                break;
            case 'int':
            case 'enum':
                $this->SetValue($ident, intval($payload));
                break;
            case 'float':
                $this->SetValue($ident, floatval($payload));
                break;
            default:
                $this->SetValue($ident, $payload);
                break;
        }

        //COP aus den HeishaMon-Schaetzwerten nachfuehren
        if (in_array($subTopic, [
            'main/Heat_Power_Production', 'main/Heat_Power_Consumption',
            'main/Cool_Power_Production', 'main/Cool_Power_Consumption',
            'main/DHW_Power_Production', 'main/DHW_Power_Consumption'
        ])) {
            $this->updateInternalCOP();
            $this->updateTotalPower();
        }
        return '';
    }

    /**
     * Verarbeitet den Messwert eines 1-Wire-Sensors (z.B. DS18B20). Neue Adressen werden
     * automatisch in der Sensorliste vermerkt (Formular zeigt sie beim naechsten Oeffnen
     * zur Benennung an); die Variable entsteht erst, wenn der Sensor aktiv geschaltet ist.
     */
    private function receiveOneWire(string $address, string $payload)
    {
        $this->rememberSeenOneWire($address);

        $ident = 'OneWire_' . $address;
        if (@$this->GetIDForIdent($ident) === false) {
            if (!$this->isOneWireSelected($address)) {
                return;
            }
            $this->maintainOneWireVariable($address);
            $this->maintainLinkTree();
        }
        $this->SetValue($ident, floatval($payload));
    }

    private function rememberSeenOneWire(string $address)
    {
        $seen = json_decode($this->ReadAttributeString('SeenOneWire'), true) ?: [];
        if (!in_array($address, $seen)) {
            $seen[] = $address;
            $this->WriteAttributeString('SeenOneWire', json_encode($seen));
        }
    }

    /**
     * Adresse => Konfigurationszeile (Name/Auswahl), aus der vom Nutzer gepflegten Liste.
     */
    private function getOneWireConfigMap(): array
    {
        $map = [];
        $rows = json_decode($this->ReadPropertyString('OneWireSensors'), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Address'])) {
                    $map[$row['Address']] = $row;
                }
            }
        }
        return $map;
    }

    private function isOneWireSelected(string $address): bool
    {
        $config = $this->getOneWireConfigMap();
        return boolval($config[$address]['Selected'] ?? true);
    }

    /**
     * Anzeigename: vom Nutzer vergebener Name, sonst ein generischer Standardname mit den
     * letzten vier Adressstellen zur Unterscheidung mehrerer Sensoren.
     */
    private function oneWireCaption(string $address): string
    {
        $config = $this->getOneWireConfigMap();
        $name = trim($config[$address]['Name'] ?? '');
        if ($name !== '') {
            return $name;
        }
        return $this->Translate('1-Wire sensor') . ' ' . substr($address, -4);
    }

    /**
     * Alle bekannten Adressen in Anzeige-Reihenfolge: zuerst die gespeicherte (per Drag & Drop
     * sortierte) Liste, danach noch unbekannte, aber bereits empfangene Adressen.
     */
    private function getOrderedOneWireAddresses(): array
    {
        $seen = json_decode($this->ReadAttributeString('SeenOneWire'), true) ?: [];
        $saved = [];
        $rows = json_decode($this->ReadPropertyString('OneWireSensors'), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Address']) && in_array($row['Address'], $seen, true)) {
                    $saved[] = $row['Address'];
                }
            }
        }
        foreach ($seen as $address) {
            if (!in_array($address, $saved, true)) {
                $saved[] = $address;
            }
        }
        return $saved;
    }

    private function getOneWirePositionMap(): array
    {
        $map = [];
        //Bewusst oberhalb des Wertebereichs der festen TopicMap-Positionen (max. rund 1510
        //bei 151 Topics), damit 1-Wire-Sensoren nicht mit deren Reihenfolge kollidieren.
        $position = 2000;
        foreach ($this->getOrderedOneWireAddresses() as $address) {
            $map[$address] = $position;
            $position += 10;
        }
        return $map;
    }

    /**
     * Legt die Variable eines 1-Wire-Sensors an bzw. aktualisiert Name/Darstellung.
     */
    private function maintainOneWireVariable(string $address, bool $refreshOnly = false)
    {
        $ident = 'OneWire_' . $address;
        $variableID = @$this->GetIDForIdent($ident);
        if (($variableID !== false) != $refreshOnly) {
            return;
        }

        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1
        ];
        if ($refreshOnly) {
            $current = @IPS_GetVariablePresentation($variableID);
            if (!is_array($current) || !$this->presentationMatches($current, $presentation)) {
                $this->MaintainVariable($ident, $this->oneWireCaption($address), VARIABLETYPE_FLOAT, $presentation, $this->getOneWirePositionMap()[$address] ?? 2000, true);
            }
            //Vom Nutzer gepflegten Namen nachfuehren, unabhaengig von der Darstellung
            if (IPS_GetName($variableID) !== $this->oneWireCaption($address)) {
                IPS_SetName($variableID, $this->oneWireCaption($address));
            }
            return;
        }

        $this->MaintainVariable($ident, $this->oneWireCaption($address), VARIABLETYPE_FLOAT, $presentation, $this->getOneWirePositionMap()[$address] ?? 2000, true);
    }

    /**
     * Baut die Zeilen der 1-Wire-Sensorliste im Formular: alle jemals empfangenen Adressen,
     * inklusive Name/Auswahl aus der gespeicherten Konfiguration.
     */
    private function buildOneWireListRows(): array
    {
        $seen = json_decode($this->ReadAttributeString('SeenOneWire'), true) ?: [];
        $config = $this->getOneWireConfigMap();
        $rows = [];
        foreach ($this->getOrderedOneWireAddresses() as $address) {
            $rows[] = [
                'Selected' => boolval($config[$address]['Selected'] ?? true),
                'Name'     => $config[$address]['Name'] ?? '',
                'Address'  => $address,
                'Received' => in_array($address, $seen) ? $this->Translate('Yes') : ''
            ];
        }
        return $rows;
    }

    /**
     * Meldet die Funktionen dieser Instanz an andere Module (z.B. Energiefluss-Visualisierungen),
     * damit die Waermepumpe dort ohne manuelle Zuweisung auftaucht.
     *
     * Teil des gemeinsamen, herstellerneutralen 'heatpump'-Vertragstyps (NRG-Stack-Konvention,
     * von EMS final abgestimmt): jedes Waermepumpen-Modul (z.B. auch WPHub fuer die Panasonic-
     * Comfort-Cloud) implementiert denselben Feldsatz additiv, fuellt aber nur, was seine
     * eigene Datenquelle tatsaechlich hergibt - fehlende Felder bleiben 0. Konsumenten (z.B.
     * NRGDashboard) sollten generisch je nach vorhandenem Feld rendern, nicht HeishaMon-
     * spezifisch wissen, welche Quelle welche Felder liefert.
     *
     * Rueckgabe je Eintrag:
     *   Type     'heatpump'
     *   Caption  Name der Instanz
     *   PowerID  Variable mit der elektrischen Leistung in W (0, falls nicht vorhanden)
     *   EnergyID Variable mit dem Energiezaehlerstand in kWh, 0 wenn kein externer Zaehler
     *            konfiguriert ist. Bewusst NICHT "Stromverbrauch heute", da dieser Wert
     *            taeglich zurueckgesetzt wird und als kumulativer Zaehler ungeeignet ist.
     *   Measured true  = PowerID stammt aus einer echten Messung
     *            false = HeishaMon-Schaetzung der Waermepumpe, grob in ~200-W-Stufen.
     *                    Konsumenten sollten den Wert dann nicht mit Nachkommastellen
     *                    darstellen, das waere Scheingenauigkeit.
     *            Nicht aus EnergyID ableitbar: Leistungs- und Energiezaehler lassen sich
     *            unabhaengig voneinander konfigurieren.
     *   unit     Physikalische Einheit von PowerID, informativ (z.B. fuer Konsumenten ohne
     *            eigenes Variablenprofil auf der Presentation-basierten Power_Total-Variable).
     *   *ID      Ab contractVersion 1.3: ausgewaehlte Heizkreislauf-Datenpunkte fuer eine
     *            Anlagenschema-Visualisierung (z.B. NRGDashboard) - pumpFlowID, pumpSpeedID,
     *            pumpDutyID, threeWayValveStateID, twoWayValveStateID, mainInletTempID,
     *            mainOutletTempID, z1WaterTempID, z2WaterTempID, dhwTempID, bufferTempID,
     *            compressorFreqID, dischargeTempID, defrostingStateID. Jeweils 0, wenn der
     *            Datenpunkt (noch) nicht empfangen wurde oder abgewaehlt/versteckt ist -
     *            NICHT abhaengig vom Sichtbarkeitsstatus (analog PowerID/EnergyID).
     *            WICHTIG: pumpFlowID/pumpSpeedID/pumpDutyID/twoWayValveStateID betreffen die
     *            INTERNE Pumpe/das interne Ventil im Innengeraet (main/...-Kernprotokoll).
     *            Fuer eine externe, von der optionalen 2. Steuerplatine angesteuerte
     *            Heizkreis-Pumpe/-Mischventil siehe z1PumpID/z1MixingValveID unten - das ist
     *            physikalisch etwas anderes, nicht dieselbe Pumpe an anderer Stelle im Vertrag.
     *   z1PumpID/z2PumpID Ab contractVersion 1.4: externe Zone-1/2-Heizkreispumpe an der
     *            optionalen 2. Steuerplatine (optional/Z1_Water_Pump bzw. .../Z2_Water_Pump).
     *            Boolean-Variable (An/Aus). 0, wenn keine optionale Platine verbaut ist/sendet.
     *   z1MixingValveID/z2MixingValveID Ab contractVersion 1.4: externes Zone-1/2-Mischventil
     *            an der optionalen 2. Steuerplatine. Integer-Variable mit STELLRICHTUNG, KEINER
     *            absoluten Position: 0=Aus, 1=Zu (Decrease), 2=Auf (Increase). 0-Wert also
     *            zweideutig zwischen "kein Datenpunkt vorhanden" und "Ventil steht gerade still"
     *            - Konsumenten, die das unterscheiden muessen, sollten zusaetzlich pruefen, ob
     *            die Variable ueberhaupt existiert (IPS_VariableExists), nicht nur den Wert.
     *   fan1SpeedID/fan2SpeedID Ab contractVersion 1.5: Luefterdrehzahl des Aussengeraets in
     *            U/min (main/Fan1_Motor_Speed bzw. main/Fan2_Motor_Speed - Fan2 nur bei
     *            groesseren Geraeten mit zwei Lueftern). 0, wenn nicht empfangen.
     *   contractVersion 'Major.Minor' des Vertrags (Suite-Konvention, SUITE.md im EMS-Repo).
     *            Major nur bei Bruch; Kompatibilitaet nur innerhalb derselben Major. Fehlt = '1.0'.
     */
    public function GetFunctions(): array
    {
        $powerID = @$this->GetIDForIdent('Power_Total');
        $energyID = $this->ReadPropertyInteger('EnergyVariable');
        $reachableID = @$this->GetIDForIdent('Reachable');

        return [
            [
                'Type'            => 'heatpump',
                'Caption'         => IPS_GetName($this->InstanceID),
                'PowerID'         => $powerID === false ? 0 : $powerID,
                'EnergyID'        => ($energyID > 0 && IPS_VariableExists($energyID)) ? $energyID : 0,
                'Measured'        => $this->hasMeasuredPower(),
                'unit'            => 'W',
                //Wenn die WP nicht erreichbar ist, friert PowerID beim letzten bekannten Wert ein
                //(kein aktiver Reset auf 0, um keinen falschen "Anlage aus"-Zustand vorzutaeuschen).
                //Konsumenten sollten bei reachable=false den Wert als potenziell veraltet behandeln.
                'reachable'            => $reachableID === false ? true : (bool) GetValue($reachableID),
                'pumpFlowID'           => $this->idForIdent('Pump_Flow'),
                'pumpSpeedID'          => $this->idForIdent('Pump_Speed'),
                'pumpDutyID'           => $this->idForIdent('Pump_Duty'),
                'threeWayValveStateID' => $this->idForIdent('ThreeWay_Valve_State'),
                'twoWayValveStateID'   => $this->idForIdent('TwoWay_Valve_State'),
                'mainInletTempID'      => $this->idForIdent('Main_Inlet_Temp'),
                'mainOutletTempID'     => $this->idForIdent('Main_Outlet_Temp'),
                'z1WaterTempID'        => $this->idForIdent('Z1_Water_Temp'),
                'z2WaterTempID'        => $this->idForIdent('Z2_Water_Temp'),
                'dhwTempID'            => $this->idForIdent('DHW_Temp'),
                'bufferTempID'         => $this->idForIdent('Buffer_Temp'),
                'compressorFreqID'     => $this->idForIdent('Compressor_Freq'),
                'dischargeTempID'      => $this->idForIdent('Discharge_Temp'),
                'defrostingStateID'    => $this->idForIdent('Defrosting_State'),
                'z1PumpID'             => $this->idForIdent('Optional_Z1_Water_Pump'),
                'z1MixingValveID'      => $this->idForIdent('Optional_Z1_Mixing_Valve'),
                'z2PumpID'             => $this->idForIdent('Optional_Z2_Water_Pump'),
                'z2MixingValveID'      => $this->idForIdent('Optional_Z2_Mixing_Valve'),
                'fan1SpeedID'          => $this->idForIdent('Fan1_Motor_Speed'),
                'fan2SpeedID'          => $this->idForIdent('Fan2_Motor_Speed'),
                'contractVersion'      => '1.5'
            ]
        ];
    }

    /**
     * Variablen-ID zu einem Ident, 0 wenn (noch) nicht vorhanden - unabhaengig vom
     * Sichtbarkeitsstatus (siehe GetFunctions-Dokumentation).
     */
    private function idForIdent(string $ident): int
    {
        $variableID = @$this->GetIDForIdent($ident);
        return $variableID === false ? 0 : $variableID;
    }

    /**
     * Liegt eine echte Leistungsmessung vor? Einzige Quelle der Wahrheit fuer die
     * Wertermittlung in updateTotalPower und das Measured-Flag in GetFunctions.
     */
    private function hasMeasuredPower(): bool
    {
        $powerID = $this->ReadPropertyInteger('PowerVariable');
        return $powerID > 0 && IPS_VariableExists($powerID);
    }

    /**
     * Fuehrt die elektrische Gesamtleistung nach: gemessener Wert des externen Zaehlers,
     * andernfalls die Summe der HeishaMon-Schaetzwerte (Heizen + Kuehlen + Warmwasser).
     */
    private function updateTotalPower()
    {
        if (@$this->GetIDForIdent('Power_Total') === false) {
            return;
        }
        if ($this->hasMeasuredPower()) {
            $this->SetValue('Power_Total', floatval(GetValue($this->ReadPropertyInteger('PowerVariable'))));
            return;
        }
        $this->SetValue('Power_Total', $this->getElectricalPower());
    }

    public function RequestAction($Ident, $Value)
    {
        $definition = $this->getDefinitionByIdent($Ident);
        if ($definition === null) {
            throw new Exception($this->Translate('Unknown Ident: ') . $Ident);
        }
        if (!array_key_exists('set', $definition)) {
            throw new Exception($this->Translate('Variable is read-only: ') . $Ident);
        }

        switch ($definition['kind']) {
            case 'bool':
                $payload = boolval($Value) ? '1' : '0';
                $this->SetValue($Ident, boolval($Value));
                break;
            case 'float':
                $payload = strval(floatval($Value));
                $this->SetValue($Ident, floatval($Value));
                break;
            default:
                $payload = strval(intval($Value));
                $this->SetValue($Ident, intval($Value));
                break;
        }

        $this->SendSetCommand($definition['set'], $payload);
    }

    /**
     * Sendet einen beliebigen HeishaMon-Befehl, z.B. HEISHA_SendSetCommand(12345, 'SetQuietMode', '2');
     */
    public function SendSetCommand(string $Command, string $Value)
    {
        $baseTopic = $this->ReadPropertyString('MQTTTopic');
        if ($baseTopic == '') {
            return;
        }
        $this->sendMQTT($baseTopic . '/commands/' . $Command, $Value);
    }

    /**
     * Setzt die Heiz-/Kuehlkurven, erwartet das JSON-Dokument laut HeishaMon-Doku (SET16).
     */
    public function SetCurves(string $CurvesJSON)
    {
        json_decode($CurvesJSON);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo $this->Translate('Invalid JSON document');
            return;
        }
        $this->SendSetCommand('SetCurves', $CurvesJSON);
    }

    private function maintainTopicVariable(string $ident, string $subTopic, array $definition, bool $refreshOnly = false)
    {
        $variableID = @$this->GetIDForIdent($ident);
        //Im Normalfall (Empfang) nur einmal anlegen, nicht bei jeder Nachricht;
        //beim Auffrischen (ApplyChanges) nur bestehende Variablen aktualisieren
        if (($variableID !== false) != $refreshOnly) {
            return;
        }

        $presentation = $this->buildPresentation($definition);

        //Nur schreiben, wenn sich die Darstellung wirklich unterscheidet
        if ($refreshOnly) {
            $current = @IPS_GetVariablePresentation($variableID);
            if (is_array($current) && $this->presentationMatches($current, $presentation)) {
                return;
            }
        }

        $caption = $this->Translate($definition['cap']);
        $position = $this->getPositionMap()[$subTopic] ?? 0;

        switch ($definition['kind']) {
            case 'bool':
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_BOOLEAN, $presentation, $position, true);
                break;
            case 'int':
            case 'enum':
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_INTEGER, $presentation, $position, true);
                break;
            case 'float':
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_FLOAT, $presentation, $position, true);
                break;
            default:
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_STRING, $presentation, $position, true);
                break;
        }

        if (array_key_exists('set', $definition)) {
            $this->EnableAction($ident);
        }
    }

    /**
     * Wie MaintainVariable fuer die Berechnungs-Variablen (Float), aber ohne unnoetige
     * Schreibvorgaenge, wenn die Variable samt Darstellung bereits passt.
     */
    private function maintainCalculationVariable(string $ident, string $caption, array $presentation, int $position, bool $keep)
    {
        $variableID = @$this->GetIDForIdent($ident);
        if (!$keep && $variableID === false) {
            return;
        }
        if ($keep && $variableID !== false) {
            $current = @IPS_GetVariablePresentation($variableID);
            if (is_array($current) && $this->presentationMatches($current, $presentation)) {
                return;
            }
        }
        $this->MaintainVariable($ident, $caption, VARIABLETYPE_FLOAT, $presentation, $position, $keep);
    }

    /**
     * Vergleicht die Soll-Darstellung mit der vorhandenen. Der Kernel ergaenzt beim Speichern
     * Default-Parameter, daher werden nur die von uns gesetzten Schluessel verglichen.
     */
    private function presentationMatches(array $current, array $target): bool
    {
        foreach ($target as $key => $value) {
            if (!array_key_exists($key, $current)) {
                return false;
            }
            $currentValue = $current[$key];
            //OPTIONS u.ae. sind JSON-Strings, die der Kernel umformatieren kann
            if (is_string($currentValue) && is_string($value)) {
                $decodedCurrent = json_decode($currentValue, true);
                $decodedTarget = json_decode($value, true);
                if ($decodedCurrent !== null && $decodedTarget !== null) {
                    if ($decodedCurrent != $decodedTarget) {
                        return false;
                    }
                    continue;
                }
            }
            if ($currentValue != $value) {
                return false;
            }
        }
        return true;
    }

    private function buildPresentation(array $definition): array
    {
        switch ($definition['kind']) {
            case 'bool':
                if (array_key_exists('set', $definition)) {
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                        'CAPTION_ON'   => $this->Translate($definition['on'] ?? 'On'),
                        'CAPTION_OFF'  => $this->Translate($definition['off'] ?? 'Off')
                    ];
                }
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'OPTIONS'      => json_encode([
                        [
                            'Value'       => true,
                            'Caption'     => $this->Translate($definition['on'] ?? 'On'),
                            'IconActive'  => false,
                            'Icon'        => '',
                            'ColorActive' => false,
                            'ColorValue'  => -1
                        ],
                        [
                            'Value'       => false,
                            'Caption'     => $this->Translate($definition['off'] ?? 'Off'),
                            'IconActive'  => false,
                            'Icon'        => '',
                            'ColorActive' => false,
                            'ColorValue'  => -1
                        ]
                    ])
                ];

            case 'enum':
                //Zustands-Topics koennen laut HeishaMon-Doku -1 (unbekannt) liefern
                $options = [[
                    'Value'       => -1,
                    'Caption'     => $this->Translate('Unknown'),
                    'IconActive'  => false,
                    'Icon'        => '',
                    'ColorActive' => false,
                    'ColorValue'  => -1
                ]];
                foreach ($definition['options'] as $value => $optionCaption) {
                    $options[] = [
                        'Value'       => $value,
                        'Caption'     => $this->Translate($optionCaption),
                        'IconActive'  => false,
                        'Icon'        => '',
                        'ColorActive' => false,
                        'ColorValue'  => -1
                    ];
                }
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'OPTIONS'      => json_encode($options)
                ];

            case 'int':
            case 'float':
                if (array_key_exists('set', $definition) && array_key_exists('min', $definition)) {
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                        'MIN'          => $definition['min'],
                        'MAX'          => $definition['max'],
                        'STEP_SIZE'    => $definition['step'] ?? 1,
                        'SUFFIX'       => $definition['suffix'] ?? '',
                        'DIGITS'       => $definition['digits'] ?? 0
                    ];
                }
                if (array_key_exists('set', $definition)) {
                    //Schaltbare Werte ohne festen Bereich brauchen die Eingabe-Darstellung;
                    //die reine Wertedarstellung kann keine Eingabe und laesst die Konsole
                    //mit "Unexpected presentation when trying to determine minimum" abstuerzen
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT,
                        'SUFFIX'       => $definition['suffix'] ?? '',
                        'DIGITS'       => $definition['digits'] ?? 0
                    ];
                }
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'SUFFIX'       => $definition['suffix'] ?? '',
                    'DIGITS'       => $definition['digits'] ?? 0
                ];

            default:
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
                ];
        }
    }

    private function getDefinitionByIdent(string $ident): ?array
    {
        foreach (HeishaMonTopics::topics() as $subTopic => $definition) {
            if (HeishaMonTopics::identFromTopic($subTopic) == $ident) {
                return $definition;
            }
        }
        return null;
    }

    private function sendMQTT(string $topic, string $payload)
    {
        $data = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $payload
        ];
        $dataJSON = json_encode($data, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__, $dataJSON, 0);
        $result = @$this->SendDataToParent($dataJSON);
        if ($result === false) {
            $lastError = error_get_last();
            $this->SendDebug(__FUNCTION__ . ' Error', $lastError['message'] ?? 'unknown', 0);
        }
    }
}
