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

			

			$startDate = strtotime($startDate['day']. '.'.$startDate['month']. '.'. $startDate['year']);
			$endDate = strtotime($endDate['day']. '.'.$endDate['month']. '.'. $endDate['year']);

			//$values = AC_GetAggregatedValues($archiveID, $variableID, $aggregationType, $startDate, $endDate, 0);
			$values = AC_GetLoggedValues ($archiveID, $variableID, $startDate, $endDate, 0);

			$test = $this->remove_outliers($values);

			$keys = array_keys($test);

			$resultListValues = [];
			foreach ($keys as $key) {
			IPS_LogMessage('test',print_r($values[$key],true));
				$resultListValues[] = [
					'Date' => date('d.m.Y H:i:s',$values[$key]['TimeStamp']),
					'ValueBefore' => $values[$key+1]['Value'],
					'Value' => $values[$key]['Value'],
					'ValueAfter' => $values[$key-1]['Value'],
					'Delete' => false

				];
			}
			$this->UpdateFormField("resultList", "values", json_encode($resultListValues));

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