<?php

declare(strict_types=1);
	class ArchivdatenAnomalien extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			$this->RegisterPropertyInteger('LoggedVariable',0);
			$this->RegisterPropertyInteger('AggregationType',1);
			$this->RegisterPropertyInteger('Outlier',30);
			$this->RegisterPropertyString('StartDate','');
			$this->RegisterPropertyString('EndDate','');

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

		public function checkAnomalies() {
			$archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
			$variableID = $this->ReadPropertyInteger('LoggedVariable');
			$aggregationType = $this->ReadPropertyInteger('AggregationType');
			$startDate = json_decode($this->ReadPropertyString('StartDate'),true);
			$endDate = json_decode($this->ReadPropertyString('EndDate'),true);

			

			$startDate = strtotime($startDate['day']. '.'.$startDate['month']. '.'. $startDate['year']. '00:00:00');
			$endDate = strtotime($endDate['day']. '.'.$endDate['month']. '.'. $endDate['year']. '23:59:59');

			//$values = AC_GetAggregatedValues($archiveID, $variableID, $aggregationType, $startDate, $endDate, 0);
			$values = AC_GetLoggedValues ($archiveID, $variableID, $startDate, $endDate, 0);

			$test = $this->remove_outliers($values);

			$keys = array_keys($test);

			$resultListValues = [];
/**					foreach ($keys as $key) {
			IPS_LogMessage('test',print_r($values[$key],true));
		$resultListValues[] = [
					'Date' => date('d.m.Y H:i:s',$values[$key]['TimeStamp']),
					'ValueBefore' => $values[$key+1]['Value'],
					'Value' => $values[$key]['Value'],
					'ValueAfter' => $values[$key-1]['Value'],
					'Delete' => false

				];
			}
			*/

			$resultListValues = $this->filter_variable($values);
			IPS_LogMessage('test',print_r($resultListValues,true));
			$this->UpdateFormField("resultList", "values", json_encode($resultListValues));

		}


				private function filter_variable($logData) {

					$failedValues = [];
				// Anzahl der Werte
					$entries = count($logData);
				// Macht erst ab 3 Werten Sinn
					if ($entries < 2) return;
				// Anzahl der Fehler protokolieren
					$changes = 0;
					for ($i = 2; $i < $entries; $i++){
				// Differenz Wert2-Wert1
						$diff1 = $logData[$i - 1]['Value'] - $logData[$i - 2]['Value'];
				// Differenz Wert3-Wert2
						$diff2 = $logData[$i]['Value'] - $logData[$i - 1]['Value'];
				// Wenn der mittlere Wert entweder der größte oder kleinste Wert ist stimmt was nicht
						if ((($diff1 < -0.1) && ($diff2 > 0.1)) ||
							(($diff1 > 0.1) && ($diff2 < -0.1))){ 
				// lösche mittleren Wert
						$failedValues[] = [
							'Date' => date('d.m.Y H:i:s',$logData[$i - 1]['TimeStamp']),
							'ValueBefore' => $logData[$i]['Value'],
							'Value' => $logData[$i - 1]['Value'],
							'ValueAfter' => $logData[$i-2]['Value']
						];
				// Fehler in Logfile eintragen
							IPS_LogMessage("Medianfilter", $this->ReadPropertyInteger('LoggedVariable').' '.$changes.'. diff1:'.$diff1.' $diff2:'.$diff2);

				// eine Änderung mehr
							$changes++;
						}
					}

				// Wenn es Änderungen gab
					if ($changes > 0){
				// Anzahl der Fehler ins Logfile
						IPS_LogMessage("Medianfilter", $this->ReadPropertyInteger('LoggedVariable').': Fehlerhafte Werte:'.$changes); 
					}
					else{
						IPS_LogMessage("Medianfilter", $this->ReadPropertyInteger('LoggedVariable').': Alles OK'); 
					}
					return $failedValues;
				}


		private function remove_outliers($dataset, $magnitude = 1) {

			$count = count($dataset);
			//$mean = array_sum($dataset) / $count; // Calculate the mean
			
			$mean = array_sum(array_column($dataset, 'Value'));

			$deviation = sqrt(array_sum(array_map('ArchivdatenAnomalien::sd_square', array_column($dataset, 'Value'), array_fill(0, $count, $mean))) / $count) * $magnitude; // Calculate standard deviation and times by magnitude

			return array_filter(array_column($dataset, 'Value'), function($x) use ($mean, $deviation) { return ($x <= $mean + $deviation && $x >= $mean - $deviation); }); // Return filtered array of values that lie within $mean +- $deviation.
		  }
		  
		  protected function sd_square($x, $mean) {
			return pow($x - $mean, 2);
		  } 
	}