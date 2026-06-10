<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HeishaMonTopics.php';

/**
 * DG_HeishaMon
 *
 * Bindet einen HeishaMon (https://github.com/heishamon/HeishaMon) an IP-Symcon an.
 * Das Modul wird unter einem MQTT Server / MQTT Client (Splitter) angelegt und
 * erzeugt fuer jedes empfangene Topic automatisch eine passende Statusvariable.
 * Schreibbare Werte werden ueber <Basistopic>/commands/SetXxx an den HeishaMon gesendet.
 */
class DG_HeishaMon extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('MQTTTopic', 'panasonic_heat_pump');
        $this->RegisterPropertyBoolean('DebugUnknownTopics', false);

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

        $topics = HeishaMonTopics::topics();
        if (!array_key_exists($subTopic, $topics)) {
            if ($this->ReadPropertyBoolean('DebugUnknownTopics')) {
                $this->SendDebug('Unknown Topic', $subTopic . ' = ' . $payload, 0);
            }
            return '';
        }

        $definition = $topics[$subTopic];
        $ident = HeishaMonTopics::identFromTopic($subTopic);

        $this->maintainTopicVariable($ident, $subTopic, $definition);

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
        return '';
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

    private function maintainTopicVariable(string $ident, string $subTopic, array $definition)
    {
        //Variable nur einmal anlegen, nicht bei jeder Nachricht
        if (@$this->GetIDForIdent($ident) !== false) {
            return;
        }

        $caption = $this->Translate($definition['cap']);
        $position = array_search($subTopic, array_keys(HeishaMonTopics::topics())) + 10;
        $presentation = $this->buildPresentation($definition);

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
                $options = [];
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
