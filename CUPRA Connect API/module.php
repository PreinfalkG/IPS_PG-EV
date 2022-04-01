<?php

declare(strict_types=1);

require_once __DIR__ . '/CUPRA_API.php'; 
require_once __DIR__ . '/../libs/COMMON.php'; 
require_once __DIR__ . '/../libs/vendor/autoload.php';


	class CUPRAConnectAPI extends IPSModule
	{

		use EV_COMMON;
		use CUPRA_API;
		//use GuzzleHttp\Client;

		private $logLevel = 3;
		private $enableIPSLogOutput = false;
		private $parentRootId;
		private $archivInstanzID;

		private $cupraIdEmail;
		private $cupraIdPassword;
		private $VIN;
	
		private $client;
		private $clientCookieJar;

		public function __construct($InstanceID) {
		
			parent::__construct($InstanceID);		// Diese Zeile nicht löschen
		
			if(IPS_InstanceExists($InstanceID)) {

				$this->parentRootId = IPS_GetParent($InstanceID);
				$this->archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];

				$currentStatus = $this->GetStatus();
				if($currentStatus == 102) {				//Instanz ist aktiv
					$this->logLevel = $this->ReadPropertyInteger("LogLevel");
					$this->cupraIdEmail = $this->ReadPropertyString("tbCupraIdEmail");
					$this->cupraIdPassword = $this->ReadPropertyString("tbCupraIdPassword");		
					$this->VIN = $this->ReadPropertyString("tbVIN");		

					$this->client = new GuzzleHttp\Client();
					$this->clientCookieJar = new GuzzleHttp\Cookie\CookieJar();
	
					if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel), 0); }
				} else {
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Current Status is '%s'", $currentStatus), 0); }	
				}

			} else {
				IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, sprintf("INFO: Instance '%s' not exists", $InstanceID));
			}
		}


		public function Create()
		{
			//Never delete this line!
			parent::Create();

			IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, sprintf("Create Modul '%s' ...", $this->InstanceID));
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Create Modul '%s [%s']...", IPS_GetName($this->InstanceID), $this->InstanceID), 0); }

			$this->RegisterPropertyBoolean('AutoUpdate', false);
			$this->RegisterPropertyInteger("TimerInterval", 240);		
			$this->RegisterPropertyInteger("LogLevel", 4);

			$this->RegisterPropertyString("tbCupraIdEmail", "");
			$this->RegisterPropertyString("tbCupraIdPassword", "");
			$this->RegisterPropertyString("tbVIN", "");

			$this->RegisterTimer('Timer_AutoUpdate', 0, 'CCA_Timer_AutoUpdate($_IPS["TARGET"]);');


			$runlevel = IPS_GetKernelRunlevel();
			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("KernelRunlevel '%s'", $runlevel), 0); }	
			if ( $runlevel == KR_READY ) {
				//$this->RegisterHook(self::WEB_HOOK);
			} else {
				$this->RegisterMessage(0, IPS_KERNELMESSAGE);
			}

		}

		public function Destroy()
		{
			IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
			$this->SetUpdateInterval(0);		//Stop Auto-Update Timer
			parent::Destroy();					//Never delete this line!
		}

		public function ApplyChanges()
		{
			parent::ApplyChanges();				//Never delete this line!

			$this->logLevel = $this->ReadPropertyInteger("LogLevel");
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Log-Level to %d", $this->logLevel), 0); }
			
			if (IPS_GetKernelRunlevel() != KR_READY) {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("GetKernelRunlevel is '%s'", IPS_GetKernelRunlevel()), 0); }
				//return;
			}

			$this->RegisterProfiles();
			$this->RegisterVariables();  
				
			$autoUpdate = $this->ReadPropertyBoolean("AutoUpdate");		
			if($autoUpdate) {
				$timerInterval = $this->ReadPropertyInteger("TimerInterval");
			} else {
				$timerInterval = 0;
			}

			$this->SetUpdateInterval($timerInterval);

		}



		public function MessageSink($TimeStamp, $SenderID, $Message, $Data)	{

			$logMsg = sprintf("TimeStamp: %s | SenderID: %s | Message: %s | Data: %s", $TimeStamp, $SenderID, $Message, print_r($Data,true));
			IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, $logMsg);
			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, $logMsg, 0); }

			parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
			//if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) 	{
			//		$this->RegisterHook(self::WEB_HOOK);
			//}
		}

		
		public function SetUpdateInterval(int $timerInterval) {
			if ($timerInterval == 0) {  
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Auto-Update stopped [TimerIntervall = 0]", 0); }	
			}else if ($timerInterval < 240) { 
				$timerInterval = 240; 
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval), 0); }	
			} else {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval), 0); }
			}
			$this->SetTimerInterval("Timer_AutoUpdate", $timerInterval*1000);	
		}


		public function Timer_AutoUpdate() {

			if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "Timer_AutoUpdate called ...", 0); }

			$skipUdateSec = 600;
			$lastUpdate  = time() - round(IPS_GetVariable($this->GetIDForIdent("updateCntError"))["VariableUpdated"]);
			if ($lastUpdate > $skipUdateSec) {

				$this->UpdateData("AutoUpdateTimer");

			} else {
				SetValue($this->GetIDForIdent("updateCntSkip"), GetValue($this->GetIDForIdent("updateCntSkip")) + 1);
				$logMsg =  sprintf("INFO :: Skip Update for %d sec for Instance '%s' [%s] >> last error %d seconds ago...", $skipUdateSec, $this->InstanceID, IPS_GetName($this->InstanceID),  $lastUpdate);
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg, 0); }
				IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, $logMsg);
			}						
		}


		public function Authenticate(string $Text) {

	
			if (!$this->cupraIdEmail || !$this->cupraIdPassword) {
				throw new \Exception("No email or password set");
			}
	
			// Execute each step, in sequence
			$this->fetchLogInForm();
			$this->submitEmailAddressForm($this->cupraIdEmail);
			$this->submitPasswordForm($this->cupraIdEmail, $this->cupraIdPassword);
			$this->fetchInitialAccessTokens();

		}


		public function RefreshAccessToken(string $Text) {

		}

		public function UpdateUserInfo(string $Text) {
			$jsonData = $this->FetchUserInfo();
			var_dump($jsonData);
			if($jsonData !== false) {

				SetValue($this->GetIDForIdent("sub"), $jsonData->sub);
				SetValue($this->GetIDForIdent("name"), $jsonData->name);
				SetValue($this->GetIDForIdent("given_name"), $jsonData->given_name);
				SetValue($this->GetIDForIdent("family_name"), $jsonData->family_name);
				SetValue($this->GetIDForIdent("email"), $jsonData->email);
				SetValue($this->GetIDForIdent("email_verified"), $jsonData->email_verified);
				SetValue($this->GetIDForIdent("updated_at"), $jsonData->updated_at);
	
			}
		}
		
		public function UpdateVehiclesAndEnrollmentStatus(string $Text) {
			$jsonData = $this->FetchVehiclesAndEnrollmentStatus();

		}
		
		public function UpdateVehicleData(string $Text) {
			$jsonData = $this->FetchVehicleData();
		}
		
		public function UpdateData(string $Text) {


			if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "UpdateData ...", 0); }

				$currentStatus = $this->GetStatus();
				if($currentStatus == 102) {		
				
					$start_Time = microtime(true);

					try {

						

					
						SetValue($this->GetIDForIdent("updateCntOk"), GetValue($this->GetIDForIdent("updateCntOk")) + 1);  
						if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Update IPS Variables DONE",0); }

					} catch (Exception $e) {
						$errorMsg = $e->getMessage();
						//$errorMsg = print_r($e, true);
						SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
						SetValue($this->GetIDForIdent("updateLastError"), $errorMsg);
						if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Exception occurred :: %s", $errorMsg),0); }
						IPS_LogMessage(__METHOD__, $errorMsg);
					}

					$duration = $this->CalcDuration_ms($start_Time);
					SetValue($this->GetIDForIdent("updateLastDuration"), $duration); 

				} else {
					//SetValue($this->GetIDForIdent("instanzInactivCnt"), GetValue($this->GetIDForIdent("instanzInactivCnt")) + 1);
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Instanz '%s - [%s]' not activ [Status=%s]", $this->InstanceID, IPS_GetName($this->InstanceID), $currentStatus), 0); }
				}
				
		}	


		public function ResetUpdateVariables(string $Text) {
            if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, 'RESET Update Variables', 0); }
			SetValue($this->GetIDForIdent("updateCntOk"), 0);
			SetValue($this->GetIDForIdent("updateCntSkip"), 0);
			SetValue($this->GetIDForIdent("updateCntError"), 0); 
			SetValue($this->GetIDForIdent("updateLastError"), "-"); 
			SetValue($this->GetIDForIdent("updateLastDuration"), 0); 
		}


		protected function RegisterProfiles() {


			if ( !IPS_VariableProfileExists('EV.level') ) {
				IPS_CreateVariableProfile('EV.level', VARIABLE::TYPE_INTEGER );
				IPS_SetVariableProfileDigits('EV.level', 0 );
				IPS_SetVariableProfileText('EV.level', "", " %" );
				IPS_SetVariableProfileValues('EV.level', 0, 100, 1);
			} 
			if ( !IPS_VariableProfileExists('EV.km') ) {
				IPS_CreateVariableProfile('EV.km', VARIABLE::TYPE_INTEGER );
				IPS_SetVariableProfileDigits('EV.km', 0 );
				IPS_SetVariableProfileText('EV.km', "", " km" );
				//IPS_SetVariableProfileValues('EV.km', 0, 0, 0);
			} 		
			
			if ( !IPS_VariableProfileExists('EV.kWh') ) {
				IPS_CreateVariableProfile('EV.kWh', VARIABLE::TYPE_FLOAT );
				IPS_SetVariableProfileDigits('EV.kWh', 1 );
				IPS_SetVariableProfileText('EV.kWh', "", " kWh" );
				//IPS_SetVariableProfileValues('EV.kWh', 0, 0, 0);
			} 

			if ( !IPS_VariableProfileExists('EV.kWh_100km') ) {
				IPS_CreateVariableProfile('EV.kWh_100km', VARIABLE::TYPE_FLOAT );
				IPS_SetVariableProfileDigits('EV.kWh_100km', 1 );
				IPS_SetVariableProfileText('EV.kWh_100km', "", " kWh/100km" );
				//IPS_SetVariableProfileValues('EV.kWh_100km', 0, 0, 0);
			} 			

			if ( !IPS_VariableProfileExists('EV.Percent') ) {
				IPS_CreateVariableProfile('EV.Percent', VARIABLE::TYPE_FLOAT );
				IPS_SetVariableProfileDigits('EV.Percent', 1 );
				IPS_SetVariableProfileText('EV.Percent', "", " %" );
				//IPS_SetVariableProfileValues('EV.Percent', 0, 0, 0);
			} 	

			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Profiles registered", 0); }
		}

		protected function RegisterVariables() {
			
			$this->RegisterVariableString("sub", "User ID [sub]", "", 100);
			$this->RegisterVariableString("name", "Name", "", 110);
			$this->RegisterVariableString("given_name", "Given Name", "", 120);
			$this->RegisterVariableString("family_name", "Family Name", "", 130);
			$this->RegisterVariableString("email", "E-Mail", "", 140);
			$this->RegisterVariableString("email_verified", "E-Mail verified", "", 150);
			$this->RegisterVariableInteger("updated_at", "Updated at", "~UnixTimestamp", 160);



			$varId = $this->RegisterVariableInteger("level", "Batterie Ladezustand", "EV.level", 300);
			//AC_SetLoggingStatus($this->archivInstanzID, $varId, true);

			$varId = $this->RegisterVariableInteger("range", "Geschätzte Reichweite", "EV.km", 310);
			//AC_SetLoggingStatus($this->archivInstanzID, $varId, true);			


			$varId = $this->RegisterVariableInteger("chargingStatus", "Charging Status", "EV.ChargingStatus", 350);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableString("chargingStatusTxt", "Charging Status", "", 351);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableInteger("chargeRemainingTime", "Charge Remaining Time", "", 360);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableInteger("odometer", "Odometer", "EV.km", 400);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableFloat("latitude", "Latitude", "", 410);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableFloat("longitude", "Longitude", "", 420);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableInteger("timestamp", "Timestamp", "~UnixTimestamp", 500);


			$varId = $this->RegisterVariableFloat("calcBattEnergyLeft", "[calc] verbleibende Batteriekapazität", "EV.kWh", 800);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableFloat("calcConsumption", "[calc] Verbrauch", "EV.kWh_100km", 801);
			IPS_SetHidden($varId, true);	
			
			$varId = $this->RegisterVariableInteger("calcEstimatedRangeOnFullCharge", "[calc] Geschätzte Reichweite bei voller Ladung", "EV.km", 802);
			IPS_SetHidden($varId, true);				

			$varId = $this->RegisterVariableFloat("calcPercentOfWLTP", "[calc] Prozent von WLTP [424km]", "EV.Percent", 803);
			IPS_SetHidden($varId, true);		

			$varId = $this->RegisterVariableFloat("calcBattCharged", "[calc] Batterie geladen", "EV.kWh", 810);
			IPS_SetHidden($varId, true);

			$varId = $this->RegisterVariableFloat("calcBattDisCharged", "[calc] Batterie entladen", "EV.kWh", 811);
			IPS_SetHidden($varId, true);			


			$this->RegisterVariableInteger("updateCntOk", "Update Cnt", "", 900);
			$this->RegisterVariableFloat("updateCntSkip", "Update Cnt Skip", "", 910);	
			$this->RegisterVariableInteger("updateCntError", "Update Cnt Error", "", 920);
			$this->RegisterVariableString("updateLastError", "Update Last Error", "", 930);
			$this->RegisterVariableFloat("updateLastDuration", "Last API Request Duration [ms]", "", 940);	

	
			$varId = $this->RegisterVariableString("oAuth_tokenType", "oAuth tokenType", "", 950);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_accessToken", "oAuth accessToken", "", 951);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_accessTokenExpiresIn", "oAuth accessTokenExpiresIn", "", 952);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableInteger("oAuth_accessTokenExpiresAt", "oAuth accessTokenExpiresAt", "~UnixTimestamp", 953);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_idToken", "oAuth idToken", "", 954);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_refreshToken", "oAuth refreshToken", "", 955);
			//IPS_SetHidden($varId, true);

			
			

			IPS_ApplyChanges($this->archivInstanzID);
			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Variables registered", 0); }

		}


		protected function AddLog($name, $daten, $format) {
			$this->SendDebug("[" . __CLASS__ . "] - " . $name, $daten, $format); 	
	
			if($this->enableIPSLogOutput) {
				if($format == 0) {
					IPS_LogMessage("[" . __CLASS__ . "] - " . $name, $daten);	
				} else {
					IPS_LogMessage("[" . __CLASS__ . "] - " . $name, $this->String2Hex($daten));			
				}
			}
		}




	}