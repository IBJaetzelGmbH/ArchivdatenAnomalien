<?php

declare(strict_types=1);
    class ArchivdatenAnomalien extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyInteger('LoggedVariable', 0);
            $this->RegisterPropertyString('CheckedVariables', '{}');
            $this->RegisterPropertyString('StartDate', '');
            $this->RegisterPropertyString('EndDate', '');
            $this->RegisterPropertyBoolean('rawData', false);

            $this->SetBuffer('CheckedVariables', '{}');
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

            $checkedVariables = $this->ReadPropertyString('CheckedVariables');
            $this->SetBuffer('CheckedVariables', $checkedVariables);
        }

        public function GetConfigurationForm()
        {
            //Reset Liste CheckedVariables, falls nicht gespeichert wurde
            $checkedVariables = $this->ReadPropertyString('CheckedVariables');
            $this->SetBuffer('CheckedVariables', $checkedVariables);

            $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $loggedVariables = AC_GetAggregationVariables($archiveID, false);

            $listValues = [];

            foreach ($loggedVariables as $variable) {
                if ($variable['AggregationType'] == 1) {
                    $listValues[] = [
                        'VariableID'        => $variable['VariableID'],
                        'editable'          => false
                    ];
                }
            }
            $Form['elements'][0]['items'][0]['values'] = $listValues;
            IPS_LogMessage('Form', print_r($Form, true));

            return json_encode($Form);
        }

        public function deleteAnomalies($resultList)
        {
            $deleted = 0;
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $resultList = (array) $resultList;
            foreach ($resultList as $tmpValue) {
                if (is_array($tmpValue)) {
                    foreach ($tmpValue as $listValue) {
                        if ($listValue['Delete']) {
                            $Date = strtotime($listValue['Date']);
                            AC_DeleteVariableData($archiveID, $listValue['VariableID'], $Date, $Date);
                            AC_ReAggregateVariable($archiveID, $listValue['VariableID']);
                            $deleted++;
                        }
                    }
                }
                if ($deleted > 0) {
                    $this->UpdateFormField('PopupInfoLabel', 'caption', $deleted . ' ' . $this->Translate('anomalies have been deleted.'));
                    if ($deleted == 1) {
                        $this->UpdateFormField('PopupInfoLabel', 'caption', $deleted . ' ' . $this->Translate('anomalie deleted.'));
                    }
                    $this->UpdateFormField('PopupInfo', 'visible', true);
                }
            }
            $this->checkAnomalies();
        }

        public function checkAnomalies(bool $rawData = false)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            //$variableID = $this->ReadPropertyInteger('LoggedVariable');
            $listVariableIDs = json_decode($this->ReadPropertyString('CheckedVariables'), true);
            $aggregationType = 1;
            $propStartDate = json_decode($this->ReadPropertyString('StartDate'), true);
            $propEndDate = json_decode($this->ReadPropertyString('EndDate'), true);

            $startDate = strtotime($propStartDate['day'] . '.' . $propStartDate['month'] . '.' . $propStartDate['year'] . '00:00:00') - 86400;
            $endDate = strtotime($propEndDate['day'] . '.' . $propEndDate['month'] . '.' . $propEndDate['year'] . '23:59:59') + 86400;

            $resultListValues = [];
            foreach ($listVariableIDs as $valueVariableID) {
                $variableID = $valueVariableID['VariableID'];
                if (!$rawData) {
                    $values = AC_GetAggregatedValues($archiveID, $variableID, $aggregationType, $startDate, $endDate, 0);

                    $filteredValues = $this->filter_variable($values, $rawData, $variableID);

                    foreach ($filteredValues as $Value) {
                        $valueStartDate = strtotime($Value['Date']); // - 172800; //Value Datum - zwei Tag
                        $valueEndDate = strtotime($Value['Date']); // + 172800; //Value Datum + ein Tag

                        $rawValues = AC_GetLoggedValues($archiveID, $variableID, $valueEndDate, $endDate, 0);
                        $filteredRawValues = $this->filter_variable($rawValues, true, $variableID);
                        if (count($filteredRawValues) > 0) {
                            foreach ($filteredRawValues as $rawValue) {
                                if (array_search($rawValue['Date'], array_column($resultListValues, 'Date')) === false) {
                                    array_push($resultListValues, $rawValue);
                                }
                            }
                        }
                    }
                } else {
                    $values = AC_GetLoggedValues($archiveID, $variableID, $startDate, $endDate, 0);
                    $filteredRawValues = $this->filter_variable($values, true, $variableID);
                    if (count($filteredRawValues) > 0) {
                        foreach ($filteredRawValues as $rawValue) {
                            if (array_search($rawValue['Date'], array_column($resultListValues, 'Date')) === false) {
                                array_push($resultListValues, $rawValue);
                            }
                        }
                    }
                }
            }

            $this->UpdateFormField('resultList', 'values', json_encode($resultListValues));
        }

        public function addCheckedVariables($variableID)
        {
            if ($variableID > 0) {
                $values = json_decode($this->GetBuffer('CheckedVariables'), true);

                if (array_search($variableID, array_column($values, 'VariableID')) === false) {
                    $values[] = [
                        'VariableID'        => $variableID,
                        'editable'          => false
                    ];
                }
                $this->SetBuffer('CheckedVariables', json_encode($values));
                $this->UpdateFormField('CheckedVariables', 'values', json_encode($values));
            }
        }

        public function deleteCheckedVariables($variableID)
        {
            $values = json_decode($this->GetBuffer('CheckedVariables'), true);

            $key = array_search($variableID, array_column($values, 'VariableID'));
            unset($values[$key]);
            $values = array_values($values);
            $this->SetBuffer('CheckedVariables', json_encode($values));
            $this->UpdateFormField('CheckedVariables', 'values', json_encode($values));
        }

        private function filter_variable($logData, $rawData, $variableID)
        {
            $keyValue = 'Avg';
            if ($rawData) {
                $keyValue = 'Value';
            }
            $failedValues = [];
            // Anzahl der Werte
            $entries = count($logData);
            // Macht erst ab 3 Werten Sinn
            if ($entries < 2) {
                return $failedValues;
            }
            // Anzahl der Fehler protokolieren
            $changes = 0;
            for ($i = 2; $i < $entries; $i++) {
                // Differenz Wert2-Wert1
                $diff1 = $logData[$i - 1][$keyValue] - $logData[$i - 2][$keyValue];
                // Differenz Wert3-Wert2
                $diff2 = $logData[$i][$keyValue] - $logData[$i - 1][$keyValue];
                // Wenn der mittlere Wert entweder der größte oder kleinste Wert ist stimmt was nicht
                if ((($diff1 < -0.1) && ($diff2 > 0.1)) ||
                            (($diff1 > 0.1) && ($diff2 < -0.1))) {
                    // lösche mittleren Wert
                    $failedValues[] = [
                        'Date'        => date('d.m.Y H:i:s', $logData[$i - 1]['TimeStamp']),
                        'VariableID'  => $variableID,
                        'ValueBefore' => $logData[$i][$keyValue],
                        'Value'       => $logData[$i - 1][$keyValue],
                        'ValueAfter'  => $logData[$i - 2][$keyValue]
                    ];
                    $changes++;
                }
            }
            return $failedValues;
        }
    }