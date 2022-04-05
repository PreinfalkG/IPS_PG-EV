<?php

declare(strict_types=1);

require_once __DIR__ . '/easeeCloud_API.php'; 
require_once __DIR__ . '/../libs/COMMON.php'; 
require_once __DIR__ . '/../libs/vendor/autoload.php';


	class EaseeCloudAPI extends IPSModule
	{

		use EV_COMMON;
		use EaseeCloud_API;
		//use GuzzleHttp\Client;

		const PROF_NAMES = ["AuthenticateRetrieveAccessToken", "fetchRefreshToken"];

		private $logLevel = 3;
		private $enableIPSLogOutput = false;
		private $parentRootId;
		private $archivInstanzID;

		private $userName;
		private $password;
		private $chargerId;
		private $siteId;
	
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
					$this->userName = $this->ReadPropertyString("tbUserName");
					$this->password = $this->ReadPropertyString("tbPassword");		
					$this->chargerId = $this->ReadPropertyString("tbChargerId");		
					$this->siteId = $this->ReadPropertyString("tbSiteId");

					$this->userId = GetValue($this->GetIDForIdent("userId"));
					$this->oAuth_tokenType = GetValue($this->GetIDForIdent("oAuth_tokenType"));
					$this->oAuth_accessToken = GetValue($this->GetIDForIdent("oAuth_accessToken"));
					$this->oAuth_accessTokenExpiresIn = GetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"));
					$this->oAuth_accessTokenExpiresAt = GetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"));
					$this->oAuth_accessClaims = GetValue($this->GetIDForIdent("oAuth_accessClaims"));
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

			$this->RegisterPropertyString("tbUserName", "car.preinfalk@gmail.com");
			$this->RegisterPropertyString("tbPassword", "cupraBORN!74");
			$this->RegisterPropertyString("tbChargerId", "EHCWTFPZ");
			$this->RegisterPropertyString("tbSiteId", "290649");

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
			
			$this->RegisterTimer('Timer_AutoUpdate', 0, 'ECA_Timer_AutoUpdate($_IPS["TARGET"]);');

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

			if (!$this->userName || !$this->password) {
				$msg = "No username or password set";
				if($this->logLevel >= LogLevel::FATAL) { $this->AddLog(__FUNCTION__, $msg, 0); }
				throw new \Exception($msg);
			} else {
				
				$result = $this->AuthenticateRetrieveAccessToken();
				if($result) {
					if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate and retrieve access Token DONE [%s]", $caller), 0); }
				} else {
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'Authenticate and retrieve access Token' [%s] !", $caller), 0); }	
				}

			}
		}


		public function RefreshAccessToken(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RefreshAccessToken [%s] ...", $caller), 0); }
			return $this->fetchRefreshToken();
		}

		public function UpdateCharger(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateCharger [%s] ...", $caller), 0); }

			//DoTo
		}
		
		public function UpdateSite(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateSite [%s] ...", $caller), 0); }
			
			//DoTo
		}
		
		public function UpdateCargeSession(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateCargeSession [%s] ...", $caller), 0); }

			//DoTo
		}

		public function UpdateData(string $caller='?') {

			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateData [%s] ...", $caller), 0); }

				$currentStatus = $this->GetStatus();
				if($currentStatus == 102) {		
				
					$start_Time = microtime(true);
					try {
						
						
						$this->UpdateCharger($caller);
						$this->UpdateSite($caller);
						$this->UpdateCargeSession($caller);

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
			SetValue($this->GetIDForIdent("oAuth_accessClaims"), "");
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

			if ( !IPS_VariableProfileExists('EV.Percent') ) {
				IPS_CreateVariableProfile('EV.Percent', VARIABLE::TYPE_FLOAT );
				IPS_SetVariableProfileDigits('EV.Percent', 1 );
				IPS_SetVariableProfileText('EV.Percent', "", " %" );
				//IPS_SetVariableProfileValues('EV.Percent', 0, 0, 0);
			} 	


			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Profiles registered", 0); }
		}

		protected function RegisterVariables() {
			

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
			$varId = $this->RegisterVariableString("oAuth_accessClaims", "oAuth accessClaims", "", 954);
			//IPS_SetHidden($varId, true);
			$varId = $this->RegisterVariableString("oAuth_refreshToken", "oAuth refreshToken", "", 955);
			//IPS_SetHidden($varId, true);

			IPS_ApplyChanges($this->archivInstanzID);
			if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Variables registered", 0); }

		}


		protected function profilingStart($profName) {
			$profAttrCnt = "prof_" . $profName;
			$profAttrDuration = "prof_" . $profName . "_Duration";
			$this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
			$this->WriteAttributeFloat($profAttrDuration, microtime(true));
 
		}

		protected function profilingEnd($profName) {
			$profAttrCnt = "prof_" . $profName . "_OK";
			$profAttrDuration = "prof_" . $profName . "_Duration";
			$this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
			$duration = $this->CalcDuration_ms($this->ReadAttributeFloat($profAttrDuration));
			$this->WriteAttributeFloat($profAttrDuration, $duration);			
			SetValue($this->GetIDForIdent("updateCntOk"), GetValue($this->GetIDForIdent("updateCntOk")) + 1);  
		}	
		
		protected function profilingFault($profName, $msg) {
			$profAttrCnt = "prof_" . $profName  . "_NotOK";
			$profAttrDuration = "prof_" . $profName . "_Duration";
			$this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
			$this->WriteAttributeFloat($profAttrDuration, -1);	
			SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
			SetValue($this->GetIDForIdent("updateLastError"), $msg);			
		}	

		public function GetProfilingData(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("GetProfilingData [%s] ...", $caller), 0); }
			$profDataArr = [];
			foreach(self::PROF_NAMES as $profName) {
				$arrEntry = array();
				$arrEntry["cntStart"] = $this->ReadAttributeInteger("prof_" . $profName);
				$arrEntry["cntOK"] = $this->ReadAttributeInteger("prof_" . $profName . "_OK");
				$arrEntry["cntNotOk"] = $this->ReadAttributeInteger("prof_" . $profName  . "_NotOK");
				$arrEntry["duration"] = $this->ReadAttributeFloat("prof_" . $profName . "_Duration");
				$profDataArr[$profName] = $arrEntry;
			}
			return $profDataArr;				
		}

		public function GetProfilingDataAsText(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("GetProfilingDataAsText [%s] ...", $caller), 0); }
			return print_r($this->GetProfilingData($caller), true);
		}

		public function Reset_ProfilingData(string $caller='?') {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Reset_ProfilingData [%s] ...", $caller), 0); }
			foreach(self::PROF_NAMES as $profName) {
				$this->WriteAttributeInteger("prof_" . $profName, 0);
				$this->WriteAttributeInteger("prof_" . $profName . "_OK", 0);
				$this->WriteAttributeInteger("prof_" . $profName  . "_NotOK", 0);
				$this->WriteAttributeFloat("prof_" . $profName . "_Duration", 0);
			}
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