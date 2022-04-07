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

		const PROF_NAMES = ["FetchLogInForm", "submitEmailAddressForm", "submitPasswordForm", "fetchInitialAccessTokens", "fetchRefreshedAccessTokens", "FetchUserInfo", "FetchVehiclesAndEnrollmentStatus", "FetchVehicleData"];

		private $logLevel = 3;
		private $enableIPSLogOutput = false;
		private $parentRootId;
		private $archivInstanzID;

		private $cupraIdEmail;
		private $cupraIdPassword;
		private $vin;
	
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
					$this->vin = $this->ReadPropertyString("tbVIN");		

					$this->userId = GetValue($this->GetIDForIdent("userId"));
					$this->oAuth_tokenType = GetValue($this->GetIDForIdent("oAuth_tokenType"));
					$this->oAuth_accessToken = GetValue($this->GetIDForIdent("oAuth_accessToken"));
					$this->oAuth_accessTokenExpiresIn = GetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"));
					$this->oAuth_accessTokenExpiresAt = GetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"));
					$this->oAuth_idToken = GetValue($this->GetIDForIdent("oAuth_idToken"));
					$this->oAuth_refreshToken = GetValue($this->GetIDForIdent("oAuth_refreshToken"));

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

			//Register Attributes for simple profiling
			foreach(self::PROF_NAMES as $profName) {
				$this->RegisterAttributeInteger("prof_" . $profName, 0);
				$this->RegisterAttributeInteger("prof_" . $profName . "_OK", 0);
				$this->RegisterAttributeInteger("prof_" . $profName  . "_NotOK", 0);
				$this->RegisterAttributeFloat("prof_" . $profName . "_Duration", 0);
			}
			//$this->RegisterAttributeInteger("prof_FetchLogInForm", 0);
			//$this->RegisterAttributeInteger("prof_FetchLogInForm_OK", 0);
			//$this->RegisterAttributeInteger("prof_FetchLogInForm_NotOk", 0);
			//$this->RegisterAttributeFloat("prof_FetchLogInForm_Duration", 0);
			
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
			//$this->SetUpdateInterval(0);		//Stop Auto-Update Timer > 'Warning: Instanz existiert nicht'
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
				$this.UpdateData(__FUNCTION__);
			} else {
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval), 0); }
				$this->UpdateData(__FUNCTION__);
			}
			$this->SetTimerInterval("Timer_AutoUpdate", $timerInterval*1000);	
		}


		public function Timer_AutoUpdate() {

			if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "Timer_AutoUpdate called ...", 0); }

			$skipUdateSec = 600;
			$lastUpdate  = time() - round(IPS_GetVariable($this->GetIDForIdent("updateCntError"))["VariableUpdated"]);
			if ($lastUpdate > $skipUdateSec) {

				$this->UpdateData(__FUNCTION__);

			} else {
				SetValue($this->GetIDForIdent("updateCntSkip"), GetValue($this->GetIDForIdent("updateCntSkip")) + 1);
				$logMsg =  sprintf("INFO :: Skip Update for %d sec for Instance '%s' [%s] >> last error %d seconds ago...", $skipUdateSec, $this->InstanceID, IPS_GetName($this->InstanceID),  $lastUpdate);
				if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg, 0); }
				IPS_LogMessage("[" . __CLASS__ . "] - " . __FUNCTION__, $logMsg);
			}						
		}


		public function Authenticate(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate API [%s] ...", $caller), 0); }

			if (!$this->cupraIdEmail || !$this->cupraIdPassword) {
				$msg = "No email or password set";
				if($this->logLevel >= LogLevel::FATAL) { $this->AddLog(__FUNCTION__, $msg, 0); }
				throw new \Exception($msg);
			} else {
				$result = $this->fetchLogInForm();
				if($result) {
					$result = $this->submitEmailAddressForm($this->cupraIdEmail);
					if($result) {
						$result = $this->submitPasswordForm($this->cupraIdEmail, $this->cupraIdPassword);
						if($result) {
							$result = $this->fetchInitialAccessTokens();
							if($result) {
								if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate and fetchInitialAccessTokens DONE [%s]", $caller), 0); }
							} else {
								if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'fetchInitialAccessTokens' [%s] !", $caller), 0); }	
							}
						} else {
							if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'submitPasswordForm' [%s] !", $caller), 0); }
						}
					} else {
						if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'submitEmailAddressForm' [%s] !", $caller), 0); }
					}
				} else {
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'fetchLogInForm' [%s] !", $caller), 0); }
				}
			}
		}


		public function RefreshAccessToken(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RefreshAccessToken [%s] ...", $caller), 0); }
			return $this->fetchRefreshedAccessTokens();
		}

		public function UpdateUserInfo(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateUserInfo [%s] ...", $caller), 0); }

			$jsonData = $this->FetchUserInfo();
			if($jsonData !== false) {
				$categoryId = $this->GetCategoryID("userInfo", "User Info", $this->parentRootId, 10);

				$this->SaveVariableValue($jsonData->sub, $categoryId, "sub", "sub [=UserId]", VARIABLE::TYPE_STRING, 1, "", false);
				$this->SaveVariableValue($jsonData->name, $categoryId, "name", "Name", VARIABLE::TYPE_STRING, 2, "", false);
				$this->SaveVariableValue($jsonData->given_name, $categoryId, "given_name", "Given Name", VARIABLE::TYPE_STRING, 3, "", false);
				$this->SaveVariableValue($jsonData->family_name, $categoryId, "family_name", "Family Name", VARIABLE::TYPE_STRING, 4, "", false);
				$this->SaveVariableValue($jsonData->email, $categoryId, "email", "E-Mail", VARIABLE::TYPE_STRING, 5, "", false);
				$this->SaveVariableValue($jsonData->email_verified, $categoryId, "email_verified", "E-Mail verified", VARIABLE::TYPE_STRING, 6, "", false);
				$this->SaveVariableValue($jsonData->updated_at, $categoryId, "updated_at", "updated at", VARIABLE::TYPE_INTEGER, 7, "~UnixTimestamp", false);
			}
			SetValue($this->GetIDForIdent("lastUpdateUserInfo"), time());  
		}
		
		public function UpdateVehiclesAndEnrollmentStatus(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateVehiclesAndEnrollmentStatus [%s] ...", $caller), 0); }
			$jsonData = $this->FetchVehiclesAndEnrollmentStatus();
			if($jsonData !== false) {

				$vehicleCnt = 0;
				$categoryPos = 20;
				foreach($jsonData->vehicles as $vehicle) {
					$pos = 0;
					$vehicleCnt++;
					$categoryPos++;
					$categoryId = $this->GetCategoryID($vehicle->vin, $vehicle->vin, $this->parentRootId, $categoryPos);
					
					$this->SaveVariableValue($vehicle->vin, $categoryId, "vin", "VIN", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->enrollmentStatus, $categoryId, "enrollmentStatus", "enrollmentStatus", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->vehicleNickname, $categoryId, "vehicleNickname", "vehicleNickname", VARIABLE::TYPE_STRING, $pos++, "", false);

					$dummyModulId = $this->GetDummyModuleID("specifications", "Specifications", $categoryId, 10);
					$this->SaveVariableValue($vehicle->specifications->salesType, $dummyModulId, "salesType", "salesType", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->colors->exterior, $dummyModulId, "color_exterior", "color exterior", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->colors->interior, $dummyModulId, "color_interior", "color interior", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->colors->roof, $dummyModulId, "color_roof", "color roof", VARIABLE::TYPE_STRING, $pos++, "", false);

					$this->SaveVariableValue($vehicle->specifications->wheels->rims, $dummyModulId, "wheels_rims", "wheels rims", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->wheels->tires, $dummyModulId, "wheels_tires", "wheels tires", VARIABLE::TYPE_STRING, $pos++, "", false);
					
					$this->SaveVariableValue($vehicle->specifications->steeringRight, $dummyModulId, "steeringRight", "steeringRight", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->sunroof, $dummyModulId, "sunroof", "sunroof tires", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->heatedSeats, $dummyModulId, "heatedSeats", "heatedSeats", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($vehicle->specifications->marketEntry, $dummyModulId, "marketEntry", "marketEntry", VARIABLE::TYPE_STRING, $pos++, "", false);
				}
				SetValue($this->GetIDForIdent("lastUpdateVehiclesAndEnrollment"), time());  
			}
		}
		
		public function UpdateVehicleData(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateVehicleData [%s] ...", $caller), 0); }

			$jsonData = $this->FetchVehicleData($this->vin);
			if($jsonData !== false) {

					$pos = 0;
					$categoryId = $this->GetCategoryID($this->vin, $this->vin, $this->parentRootId, 21);

					$dummyModulId = $this->GetDummyModuleID("primaryEngine", "Primary Engine", $categoryId, 20);
					$primaryEngine = $jsonData->engines->primary;
					$this->SaveVariableValue($primaryEngine->type, $dummyModulId, "type", "Type", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->fuelType, $dummyModulId, "fuelType", "Tuel Type", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->range->value, $dummyModulId, "range_value", "Range", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false);
					$this->SaveVariableValue($primaryEngine->range->unit, $dummyModulId, "range_unit", "Range Unit", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->level, $dummyModulId, "level", "Level", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);

					$dummyModulId = $this->GetDummyModuleID("charging", "Charging", $categoryId, 30);
					$charging = $jsonData->services->charging;
					$this->SaveVariableValue($charging->status, $dummyModulId, "status", "Status", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($charging->targetPct, $dummyModulId, "targetPct", "target Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);
					$this->SaveVariableValue($charging->chargeMode, $dummyModulId, "chargeMode", "Charge Mode", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($charging->active, $dummyModulId, "active", "active", VARIABLE::TYPE_BOOLEAN, $pos++, "", false);
					$this->SaveVariableValue($charging->remainingTime, $dummyModulId, "remainingTime", "Remaining Time", VARIABLE::TYPE_INTEGER, $pos++, "", false);
					$this->SaveVariableValue($charging->progressBarPct, $dummyModulId, "progressBarPct", "ProgressBar Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);

					$dummyModulId = $this->GetDummyModuleID("climatisation", "climatisation", $categoryId, 40);
					$climatisation = $jsonData->services->climatisation;
					$this->SaveVariableValue($climatisation->status, $dummyModulId, "status", "Status", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($climatisation->active, $dummyModulId, "active", "active", VARIABLE::TYPE_BOOLEAN, $pos++, "", false);
					$this->SaveVariableValue($climatisation->remainingTime, $dummyModulId, "remainingTime", "Remaining Time", VARIABLE::TYPE_INTEGER, $pos++, "", false);
					$this->SaveVariableValue($climatisation->progressBarPct, $dummyModulId, "progressBarPct", "ProgressBar Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);					

					SetValue($this->GetIDForIdent("lastUpdateVehicleStatus"),  time());  
			}
		}

		public function UpdateData(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateData [%s] ...", $caller), 0); }

				$currentStatus = $this->GetStatus();
				if($currentStatus == 102) {		
				
					$start_Time = microtime(true);
					try {
						
						if($caller == "ModulForm") {
							$this->UpdateUserInfo($caller);
							$this->UpdateVehiclesAndEnrollmentStatus($caller);
						} else {

							$lastUpdateUserInfo = GetValue($this->GetIDForIdent("lastUpdateUserInfo"));  
							if(time() > ($lastUpdateUserInfo + 3600 * 4)) {
								$this->UpdateUserInfo($caller);
							}

							$lastUpdateVehiclesAndEnrollment = GetValue($this->GetIDForIdent("lastUpdateVehiclesAndEnrollment"));  
							if(time() > ($lastUpdateVehiclesAndEnrollment + 3600 * 4)) {
								$this->UpdateVehiclesAndEnrollmentStatus($caller);
							}
						}

						$this->UpdateVehicleData($caller);

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

					//$duration = $this->CalcDuration_ms($start_Time);
					//SetValue($this->GetIDForIdent("updateLastDuration"), $duration); 

				} else {
					//SetValue($this->GetIDForIdent("instanzInactivCnt"), GetValue($this->GetIDForIdent("instanzInactivCnt")) + 1);
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Instanz '%s - [%s]' not activ [Status=%s]", $this->InstanceID, IPS_GetName($this->InstanceID), $currentStatus), 0); }
				}
				
		}	


		public function Reset_UpdateVariables(string $caller='?') {
 			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET Update Variables [%s] ...", $caller), 0); }
			SetValue($this->GetIDForIdent("lastUpdateUserInfo"), 0);
			SetValue($this->GetIDForIdent("lastUpdateVehiclesAndEnrollment"), 0);
			SetValue($this->GetIDForIdent("lastUpdateVehicleStatus"), 0);
			SetValue($this->GetIDForIdent("updateCntOk"), 0);
			SetValue($this->GetIDForIdent("updateCntSkip"), 0);
			SetValue($this->GetIDForIdent("updateCntError"), 0); 
			SetValue($this->GetIDForIdent("updateLastError"), "-"); 
		}

		public function Reset_oAuthData(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET oAuth Variables [%s] ...", $caller), 0); }
			SetValue($this->GetIDForIdent("userId"), "");
			SetValue($this->GetIDForIdent("oAuth_tokenType"), "");
			SetValue($this->GetIDForIdent("oAuth_accessToken"), "");
			SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"), 0);
			SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"), 0);
			SetValue($this->GetIDForIdent("oAuth_idToken"), "");
			SetValue($this->GetIDForIdent("oAuth_refreshToken"), "");
		}

		public function GetClassInfo() {
			return print_r($this, true);
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

			if ( !IPS_VariableProfileExists('EV.CUPRA.ChargingStatus') ) {
				IPS_CreateVariableProfile('EV.CUPRA.ChargingStatus', VARIABLE::TYPE_INTEGER );
				IPS_SetVariableProfileText('EV.CUPRA.ChargingStatus', "", "" );
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargingStatus', -1, "[%d] Error", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargingStatus', 0, "[%d] NotReadyForCharging", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargingStatus', 1, "[%d] Charging", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargingStatus', 2, "[%d] n.a.", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargingStatus', 3, "[%d] n.a.", "", -1);
			}

			if ( !IPS_VariableProfileExists('EV.CUPRA.ChargeMode') ) {
				IPS_CreateVariableProfile('EV.CUPRA.ChargeMode', VARIABLE::TYPE_INTEGER );
				IPS_SetVariableProfileText('EV.CUPRA.ChargeMode', "", "" );
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargeMode', 0, "[%d] off", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargeMode', 1, "[%d] manual", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargeMode', 2, "[%d] n.a.", "", -1);
				IPS_SetVariableProfileAssociation ('EV.CUPRA.ChargeMode', 3, "[%d] n.a.", "", -1);
			}


			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Profiles registered", 0); }
		}

		protected function RegisterVariables() {
			
			$this->RegisterVariableInteger("lastUpdateUserInfo", "last Update 'User Info'", "~UnixTimestamp", 900);
			$this->RegisterVariableInteger("lastUpdateVehiclesAndEnrollment", "last Update 'Vehicles & Enrollment'", "~UnixTimestamp", 901);
			$this->RegisterVariableInteger("lastUpdateVehicleStatus", "last Update 'Vehicle Status'", "~UnixTimestamp", 902);

			$this->RegisterVariableInteger("updateCntOk", "Update Cnt", "", 910);
			$this->RegisterVariableFloat("updateCntSkip", "Update Cnt Skip", "", 911);	
			$this->RegisterVariableInteger("updateCntError", "Update Cnt Error", "", 912);
			$this->RegisterVariableString("updateLastError", "Update Last Error", "", 913);

			$varId = $this->RegisterVariableString("userId", "User ID", "", 940);
	
			$varId = $this->RegisterVariableString("oAuth_tokenType", "oAuth tokenType", "", 950);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_accessToken", "oAuth accessToken", "", 951);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableInteger("oAuth_accessTokenExpiresIn", "oAuth accessTokenExpiresIn", "", 952);
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