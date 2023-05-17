<?php

declare(strict_types=1);
    class ArchivdatenAnomalien extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyInteger('LoggedVariable', 0);
            $this->RegisterPropertyString('StartDate', '');
            $this->RegisterPropertyString('EndDate', '');
            $this->RegisterPropertyBoolean('rawData', false);
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
        }

        public function deleteAnomalies($resultList)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $resultList = (array) $resultList;
            foreach ($resultList as $tmpValue) {
                if (is_array($tmpValue)) {
                    foreach ($tmpValue as $listValue) {
                        if ($listValue['Delete']) {
                            IPS_LogMessage('Test', $listValue['Date']);
                            $Date = strtotime($listValue['Date']);
                            AC_DeleteVariableData($archiveID, $this->ReadPropertyInteger('LoggedVariable'), $Date, $Date);
                            AC_ReAggregateVariable($archiveID, $this->ReadPropertyInteger('LoggedVariable'));
                        }
                    }
                }
            }
        }

        public function checkAnomalies(bool $rawData = false)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $variableID = $this->ReadPropertyInteger('LoggedVariable');
            $aggregationType = 1;
            $startDate = json_decode($this->ReadPropertyString('StartDate'), true);
            $endDate = json_decode($this->ReadPropertyString('EndDate'), true);

            $startDate = strtotime($startDate['day'] - 2 . '.' . $startDate['month'] . '.' . $startDate['year'] . '00:00:00');
            $endDate = strtotime($endDate['day'] + 2 . '.' . $endDate['month'] . '.' . $endDate['year'] . '23:59:59');
            $resultListValues = [];
            if (!$rawData) {
                $values = AC_GetAggregatedValues($archiveID, $variableID, $aggregationType, $startDate, $endDate, 0);
                $filteredValues = $this->filter_variable($values, $rawData);
                IPS_LogMessage('filteredValues', print_r($filteredValues, true));

                foreach ($filteredValues as $Value) {
                    $startDate = strtotime($Value['Date']) - 172800; //Value Datum - zwei Tag
                    $endDate = strtotime($Value['Date']) + 172800; //Value Datum + ein Tag

                    $rawValues = AC_GetLoggedValues($archiveID, $variableID, $startDate, $endDate, 0);
                    $filteredRawValues = $this->filter_variable($rawValues, true);
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
                $resultListValues = $this->filter_variable($values, $rawData);
            }
            $this->UpdateFormField('resultList', 'values', json_encode($resultListValues));
        }

        protected function sd_square($x, $mean)
        {
            return pow($x - $mean, 2);
        }
        private function filter_variable($logData, $rawData)
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
                        'ValueBefore' => $logData[$i][$keyValue],
                        'Value'       => $logData[$i - 1][$keyValue],
                        'ValueAfter'  => $logData[$i - 2][$keyValue]
                    ];
                    // Fehler in Logfile eintragen
                    IPS_LogMessage('Medianfilter', $this->ReadPropertyInteger('LoggedVariable') . ' ' . $changes . '. diff1:' . $diff1 . ' $diff2:' . $diff2);

                    // eine Änderung mehr
                    $changes++;
                }
            }

            // Wenn es Änderungen gab
            if ($changes > 0) {
                // Anzahl der Fehler ins Logfile
                IPS_LogMessage('Medianfilter', $this->ReadPropertyInteger('LoggedVariable') . ': Fehlerhafte Werte:' . $changes);
            } else {
                IPS_LogMessage('Medianfilter', $this->ReadPropertyInteger('LoggedVariable') . ': Alles OK');
            }
            return $failedValues;
        }

        private function remove_outliers($dataset, $magnitude = 1)
        {
            $count = count($dataset);
            //$mean = array_sum($dataset) / $count; // Calculate the mean

            $mean = array_sum(array_column($dataset, 'Value'));

            $deviation = sqrt(array_sum(array_map('ArchivdatenAnomalien::sd_square', array_column($dataset, 'Value'), array_fill(0, $count, $mean))) / $count) * $magnitude; // Calculate standard deviation and times by magnitude

            return array_filter(array_column($dataset, 'Value'), function ($x) use ($mean, $deviation)
            {
                return $x <= $mean + $deviation && $x >= $mean - $deviation;
            }); // Return filtered array of values that lie within $mean +- $deviation.
        }
    }