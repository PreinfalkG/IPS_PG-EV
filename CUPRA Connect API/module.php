<?php

declare(strict_types=1);

require_once __DIR__ . '/CUPRA_API.php'; 
require_once __DIR__ . '/../libs/COMMON.php'; 
require_once __DIR__ . '/../libs/vendor/autoload.php';


class CUPRAConnectAPI extends IPSModule {

	use EV_COMMON;
	use CUPRA_API;
	//use GuzzleHttp\Client;

	const PROF_NAMES = ["FetchLogInForm", "submitEmailAddressForm", "submitPasswordForm", "fetchInitialAccessTokens", "fetchRefreshedAccessTokens", "FetchUserInfo", "FetchVehiclesAndEnrollmentStatus", "FetchVehicleData"];

	private $logLevel = 3;
	private $logCnt = 0;
	private $enableIPSLogOutput = false;

	private $cupraIdEmail;
	private $cupraIdPassword;
	private $vin;

	private $client;
	private $clientCookieJar;

	public function __construct($InstanceID) {
	
		parent::__construct($InstanceID);		// Diese Zeile nicht löschen
		
		$this->logLevel = @$this->ReadPropertyInteger("LogLevel"); 
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel)); }

		$currentStatus = @$this->GetStatus();
		if($currentStatus == 102) {				//Instanz ist aktiv
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

			//$this->client = new GuzzleHttp\Client();
			$this->client = new GuzzleHttp\Client(['verify' => false]);	//disable SSL-Certificate verify
			$this->clientCookieJar = new GuzzleHttp\Cookie\CookieJar();
		} else {
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Current Status is '%s'", $currentStatus)); }	
		}

	}


	public function Create() {
		
		parent::Create();				//Never delete this line!

		$logMsg = sprintf("Create Modul '%s [%s]'...", IPS_GetName($this->InstanceID), $this->InstanceID);
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg); }
		IPS_LogMessage(__CLASS__."_".__FUNCTION__, $logMsg);

		$logMsg = sprintf("KernelRunlevel '%s'", IPS_GetKernelRunlevel());
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, $logMsg); }

		$this->RegisterPropertyBoolean('AutoUpdate', false);
		$this->RegisterPropertyInteger("TimerInterval", 240);		
		$this->RegisterPropertyInteger("LogLevel", 4);

		$this->RegisterPropertyString("tbCupraIdEmail", "");
		$this->RegisterPropertyString("tbCupraIdPassword", "");
		$this->RegisterPropertyString("tbVIN", "");

		$this->RegisterPropertyBoolean('logVehicleData', false);
		$this->RegisterPropertyBoolean('createGPX', false);

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
		
		$this->RegisterTimer('TimerAutoUpdate_CCA', 0, 'CCA_TimerAutoUpdate_CCA($_IPS["TARGET"]);');

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}

	public function Destroy() {
		IPS_LogMessage(__CLASS__."_".__FUNCTION__, sprintf("Destroy Modul '%s' ...", $this->InstanceID));
		parent::Destroy();						//Never delete this line!
	}

	public function ApplyChanges() {

		parent::ApplyChanges();				//Never delete this line!

		$this->logLevel = $this->ReadPropertyInteger("LogLevel");
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Log-Level to %d", $this->logLevel)); }
		
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
		$logMsg = sprintf("TimeStamp: %s | SenderID: %s | Message: %s | Data: %s", $TimeStamp, $SenderID, $Message, json_encode($Data));
		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, $logMsg); }
		//IPS_LogMessage(__CLASS__."_".__FUNCTION__, $logMsg);
	}

	
	public function SetUpdateInterval(int $timerInterval) {
		if ($timerInterval == 0) {  
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Auto-Update stopped [TimerIntervall = 0]"); }	
		}else if ($timerInterval < 120) { 
			$timerInterval = 120; 
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval)); }	
			$this->UpdateData(__FUNCTION__);
		} else {
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Set Auto-Update Timer Intervall to %s sec", $timerInterval)); }
			$this->UpdateData(__FUNCTION__);
		}
		$this->SetTimerInterval("TimerAutoUpdate_CCA", $timerInterval*1000);	
	}


	public function TimerAutoUpdate_CCA() {

		if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, "TimerAutoUpdate_CCA called ..."); }

		$skipUdateSec = 600;
		$lastUpdate  = time() - round(IPS_GetVariable($this->GetIDForIdent("updateCntError"))["VariableUpdated"]);
		if ($lastUpdate > $skipUdateSec) {

			$this->UpdateData(__FUNCTION__);

		} else {
			SetValue($this->GetIDForIdent("updateCntSkip"), GetValue($this->GetIDForIdent("updateCntSkip")) + 1);
			$logMsg =  sprintf("INFO :: Skip Update for %d sec for Instance '%s' [%s] >> last error %d seconds ago...", $skipUdateSec, $this->InstanceID, IPS_GetName($this->InstanceID),  $lastUpdate);
			if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $logMsg); }
		}						
	}


	public function Authenticate(string $caller='?') {

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate API [%s] ...", $caller)); }

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
							if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate and fetchInitialAccessTokens DONE [%s]", $caller)); }
						} else {
							if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'fetchInitialAccessTokens' [%s] !", $caller)); }	
						}
					} else {
						if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'submitPasswordForm' [%s] !", $caller)); }
					}
				} else {
					if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'submitEmailAddressForm' [%s] !", $caller)); }
				}
			} else {
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'fetchLogInForm' [%s] !", $caller)); }
			}
		}
	}


	public function RefreshAccessToken(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RefreshAccessToken [%s] ...", $caller)); }
		return $this->fetchRefreshedAccessTokens();
	}

	public function UpdateUserInfo(string $caller='?') {

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateUserInfo [%s] ...", $caller)); }

		$jsonData = $this->FetchUserInfo();
		if($jsonData !== false) {
			$categoryId = $this->GetCategoryID("userInfo", "User Info", IPS_GetParent($this->InstanceID), 10);

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

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateVehiclesAndEnrollmentStatus [%s] ...", $caller)); }
		$jsonData = $this->FetchVehiclesAndEnrollmentStatus();
		if($jsonData !== false) {

			$vehicleCnt = 0;
			$categoryPos = 20;
			foreach($jsonData->vehicles as $vehicle) {
				$pos = 0;
				$vehicleCnt++;
				$categoryPos++;
				$categoryId = $this->GetCategoryID($vehicle->vin, $vehicle->vin, IPS_GetParent($this->InstanceID), $categoryPos);
				
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

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateVehicleData [%s] ...", $caller)); }
		$baseApiUrl = "https://ola.prod.code.seat.cloud.vwgroup.com";
		
		if(1!=1) {

			//weconnect_cupra/api/cupra/elements/vehicle.py	
			//https://github.com/daernsinstantfortress/WeConnect-Cupra-python/blob/main/weconnect_cupra/api/cupra/elements/vehicle.py

			$url = sprintf("%s/vehicles/%s/connection", $baseApiUrl, $this->vin);
			// {"connection":{"mode":"online"}}

			$url = sprintf("%s/v2/vehicles/%s/status", $baseApiUrl, $this->vin);
			//{"locked":false,"lights":"off","engine":"off","hood":{"open":"false","locked":"false"},"trunk":{"open":"false","locked":"false"},"doors":{"frontLeft":{"open":"false","locked":"false"},"frontRight":{"open":"false","locked":"false"},"rearLeft":{"open":"false","locked":"false"},"rearRight":{"open":"false","locked":"false"}},"windows":{"frontLeft":"closed","frontRight":"closed","rearLeft":"closed","rearRight":"closed"}}

			$url = sprintf("%s/v1/vehicles/%s/mileage", $baseApiUrl, $this->vin);
			// {"mileageKm":16392}

			$url = sprintf("%s/vehicles/%s/charging/status", $baseApiUrl, $this->vin);
			// {"status":{"battery":{"carCapturedTimestamp":"2024-07-24T13:21:48Z","currentSOC_pct":80,"cruisingRangeElectric_km":339},"charging":{"carCapturedTimestamp":"2024-07-24T13:21:48Z","chargingState":"notReadyForCharging","chargeType":"invalid","chargeMode":"manual","chargingSettings":"default","remainingChargingTimeToComplete_min":0,"chargePower_kW":0.0,"chargeRate_kmph":0.0},"plug":{"carCapturedTimestamp":"2024-07-24T18:27:17Z","plugConnectionState":"disconnected","plugLockState":"unlocked","externalPower":"unavailable"}}}

			$url = sprintf("%s/vehicles/%s/charging/settings", $baseApiUrl, $this->vin);
			// {"settings":{"maxChargeCurrentAC":"maximum","carCapturedTimestamp":"2024-07-24T18:27:18Z","autoUnlockPlugWhenCharged":"permanent","targetSoc_pct":80,"batteryCareModeEnabled":true,"batteryCareTargetSocPercentage":80}}

			$url = sprintf("%s/v1/vehicles/%s/climatisation/status", $baseApiUrl, $this->vin);
			//  {"climatisationStatus":{"carCapturedTimestamp":"2024-07-24T18:27:18Z","remainingClimatisationTimeInMinutes":0,"climatisationState":"off","climatisationTrigger":"off"},"windowHeatingStatus":{"carCapturedTimestamp":"2024-07-24T18:27:18Z","windowHeatingStatus":[{"windowLocation":"front","windowHeatingState":"off"},{"windowLocation":"rear","windowHeatingState":"off"}]}}

			$url = sprintf("%s/v2/vehicles/%s/climatisation/settings", $baseApiUrl, $this->vin);
			// {"carCapturedTimestamp":"2024-07-24T18:27:17Z","targetTemperatureInCelsius":16.0,"targetTemperatureInFahrenheit":60.0,"unitInCar":"celsius","climatisationAtUnlock":false,"windowHeatingEnabled":false,"zoneFrontLeftEnabled":true,"zoneFrontRightEnabled":false}
		}	
		
		if (empty($this->vin)) {
			$msg = "WARN :: VIN is 'empty' -> cannot load vehicle data!";
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, $msg, 0); }
		} else {

			$pos = 0;
			$categoryId = $this->GetCategoryID($this->vin, $this->vin, IPS_GetParent($this->InstanceID), 21);

			$calcDummyModulId = $this->GetDummyModuleID("calcValues", "Calc Values", $categoryId, 700);

			// Online Connectsion
			$apiUrl = sprintf("%s/vehicles/%s/connection", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				if(isset($jsonData->connection->mode)) { 
					$connectionMode = $jsonData->connection->mode;
					$this->SaveVariableValue($connectionMode, $categoryId, "connectionMode", "Connection Mode", VARIABLE::TYPE_STRING, 100, "", false); 
					if($connectionMode == "online") {
						$this->SaveVariableValue(1, $categoryId, "connectionMode_Int", "Connection Mode Int", VARIABLE::TYPE_INTEGER, 101, "EV.connection.mode", false); 
					} else if($connectionMode == "offline") {
						$this->SaveVariableValue(0, $categoryId, "connectionMode_Int", "Connection Mode Int", VARIABLE::TYPE_INTEGER, 101, "EV.connection.mode", false); 
					} else {
						$this->SaveVariableValue(-1, $categoryId, "connectionMode_Int", "Connection Mode Int", VARIABLE::TYPE_INTEGER, 101, "EV.connection.mode", false); 
					}			
				}
			}

			// Status Türen und Fenster
			$apiUrl = sprintf("%s/v2/vehicles/%s/status", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				$dummyModulId = $this->GetDummyModuleID("status", "Status", $categoryId, 400);
				if(isset($jsonData->locked)) { $this->SaveVariableValue($jsonData->locked, $dummyModulId, "locked", "Locked", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->lights)) { $this->SaveVariableValue($jsonData->lights, $dummyModulId, "lights", "Lights", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->engine)) { $this->SaveVariableValue($jsonData->engine, $dummyModulId, "engine", "Engine", VARIABLE::TYPE_STRING, $pos++, "", false); }

				if(isset($jsonData->hood)) {
					if(isset($jsonData->hood->open)) { $this->SaveVariableValue($jsonData->hood->open, $dummyModulId, "hoodOpen", "Motorhaube offen", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
					if(isset($jsonData->hood->locked)) { $this->SaveVariableValue($jsonData->hood->locked, $dummyModulId, "hoodLocked", "Motorhaube verschlossen", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				}
				if(isset($jsonData->trunk)) {
					if(isset($jsonData->trunk->open)) { $this->SaveVariableValue($jsonData->trunk->open, $dummyModulId, "trunkOpen", "Kofferraum offen", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
					if(isset($jsonData->trunk->locked)) { $this->SaveVariableValue($jsonData->trunk->locked, $dummyModulId, "trunkLocked", "Kofferraum verschlossen", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				}	
				
				if(isset($jsonData->doors->frontLeft->open)) { $this->SaveVariableValue($jsonData->doors->frontLeft->open, $dummyModulId, 	"doorFrontLeftOpen", 	"Autotür offen: vorne links", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->frontRight->open)) { $this->SaveVariableValue($jsonData->doors->frontRight->open, $dummyModulId, "doorFrontRightOpen", 	"Autotür offen: vorne rechts", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->rearLeft->open)) { $this->SaveVariableValue($jsonData->doors->rearLeft->open, $dummyModulId, 	"doorRearLeftOpen", 	"Autotür offen: hinten links", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->rearRight->open)) { $this->SaveVariableValue($jsonData->doors->rearRight->open, $dummyModulId, 	"doorRearRightOpen", 	"Autotür offen: hinten rechts", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }

				if(isset($jsonData->doors->frontLeft->locked)) { $this->SaveVariableValue($jsonData->doors->frontLeft->locked, $dummyModulId, 	"doorFrontLeftLocked", 	"Autotür verschlossen: vorne links", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->frontRight->locked)) { $this->SaveVariableValue($jsonData->doors->frontRight->locked, $dummyModulId, "doorFrontRightLocked", "Autotür verschlossen: vorne rechts", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->rearLeft->locked)) { $this->SaveVariableValue($jsonData->doors->rearLeft->locked, $dummyModulId, 	"doorRearLeftLocked", 	"Autotür verschlossen: hinten links", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->doors->rearRight->locked)) { $this->SaveVariableValue($jsonData->doors->rearRight->locked, $dummyModulId, 	"doorRearRightLocked", 	"Autotür verschlossen: hinten rechts", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }

				if(isset($jsonData->windows->frontLeft)) { $this->SaveVariableValue($jsonData->windows->frontLeft, $dummyModulId, 	"windowFrontLeftOpen", 		"Fenster vorne links", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->windows->frontRight)) { $this->SaveVariableValue($jsonData->windows->frontRight, $dummyModulId, "windowFrontRightOpen", 	"Fenster vorne rechts", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->windows->rearLeft)) { $this->SaveVariableValue($jsonData->windows->rearLeft, $dummyModulId, 	"windowRearLeftLocked", 	"Fenster hinten links", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->windows->rearRight)) { $this->SaveVariableValue($jsonData->windows->rearRight, $dummyModulId, 	"windowRearRightLocked", 	"Fenster hinten rechts", VARIABLE::TYPE_STRING, $pos++, "", false); }

			}

			// Kilometerstand
			$apiUrl = sprintf("%s/v1/vehicles/%s/mileage", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
					if(isset($jsonData->mileageKm)) { $this->SaveVariableValue($jsonData->mileageKm, $categoryId, "mileage", "Kilometerstand", VARIABLE::TYPE_INTEGER, 200, "EV.km", false); }
			}


			// parking position
			$apiUrl = sprintf("%s/v1/vehicles/%s/parkingposition", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
					$dummyModulId = $this->GetDummyModuleID("parkingposition", "Parking Position", $categoryId, 210);
					if(isset($jsonData->lat)) { $this->SaveVariableValue($jsonData->lat, $dummyModulId, "posLat", "Latitude", VARIABLE::TYPE_FLOAT, 10, "", false); }
					if(isset($jsonData->lon)) { $this->SaveVariableValue($jsonData->lon, $dummyModulId, "posLon", "Longitude", VARIABLE::TYPE_FLOAT, 11, "", false); }
			}

			// Charging Status
			$apiUrl = sprintf("%s/vehicles/%s/charging/status", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				$dummyModulId = $this->GetDummyModuleID("chargingStatus", "Charging Status", $categoryId, 500);

				
				$currentSOC = 0;
				$cruisingRangeElectric = 0;
				if(isset($jsonData->status->battery->currentSOC_pct)) { 
					$currentSOC = $jsonData->status->battery->currentSOC_pct;
					$this->SaveVariableValue($currentSOC, $dummyModulId, "battery_currentSOC", "Battery: SOC", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false); 
				}
				if(isset($jsonData->status->battery->cruisingRangeElectric_km)) { 
					$cruisingRangeElectric = $jsonData->status->battery->cruisingRangeElectric_km;
					$this->SaveVariableValue($cruisingRangeElectric, $dummyModulId, "battery_cruisingRangeElectric", "Battery: Range", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false); 
				}
				if(($currentSOC > 0) AND ($cruisingRangeElectric > 0)) { 
					$calc_WLTP = round($cruisingRangeElectric / ($currentSOC / 100.0));
					$this->SaveVariableValue($calc_WLTP, $calcDummyModulId, "calc_WLTP", "Calc: WLTP Reichweite", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false);
				}								
				if(isset($jsonData->status->battery->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->status->battery->carCapturedTimestamp), $dummyModulId, "battery_carCapturedTimestamp", "Battery: Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }				

				if(isset($jsonData->status->charging->chargingState)) { $this->SaveVariableValue($jsonData->status->charging->chargingState, $dummyModulId, "charging_chargingState", "Charging: Charging State", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->charging->chargeType)) { $this->SaveVariableValue($jsonData->status->charging->chargeType, $dummyModulId, "charging_chargeType", "Charging: Charge Type", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->charging->chargeMode)) { $this->SaveVariableValue($jsonData->status->charging->chargeMode, $dummyModulId, "charging_chargeMode", "Charging: Charge Mode", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->charging->chargingSettings)) { $this->SaveVariableValue($jsonData->status->charging->chargingSettings, $dummyModulId, "charging_chargingSettings", "Charging Settings", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->charging->remainingChargingTimeToComplete_min)) { $this->SaveVariableValue($jsonData->status->charging->remainingChargingTimeToComplete_min, $dummyModulId, "charging_remainingChargingTimeToComplete", "Charging: Remaining Charging Time To Complete", VARIABLE::TYPE_INTEGER, $pos++, "EV.RemainingMin", false); }
				if(isset($jsonData->status->charging->chargePower_kW)) { $this->SaveVariableValue($jsonData->status->charging->chargePower_kW, $dummyModulId, "charging_chargePower_kW", "Charging: Charge Power", VARIABLE::TYPE_FLOAT, $pos++, "EV.kWatt", false); }
				if(isset($jsonData->status->charging->chargeRate_kmph)) { $this->SaveVariableValue($jsonData->status->charging->chargeRate_kmph, $dummyModulId, "charging_chargeRate", "Charging: Charge Rate", VARIABLE::TYPE_FLOAT, $pos++, "EV.kmph", false); }
				if(isset($jsonData->status->charging->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->status->charging->carCapturedTimestamp), $dummyModulId, "charging_carCapturedTimestamp", "Charging: Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }

				if(isset($jsonData->status->plug->plugConnectionState)) { $this->SaveVariableValue($jsonData->status->plug->plugConnectionState, $dummyModulId, "plug_plugConnectionState", "Plug: ConnectionState", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->plug->plugLockState)) { $this->SaveVariableValue($jsonData->status->plug->plugLockState, $dummyModulId, "plug_plugLockState", "Plug: LockState", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->plug->externalPower)) { $this->SaveVariableValue($jsonData->status->plug->externalPower, $dummyModulId, "plug_externalPower", "Plug: ExternalPower", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->status->plug->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->status->plug->carCapturedTimestamp), $dummyModulId, "plug_carCapturedTimestamp", "Plug: Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }
			}


			// Charging Settings
			$apiUrl = sprintf("%s/vehicles/%s/charging/settings", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				$dummyModulId = $this->GetDummyModuleID("chargingSettings", "Charging Settings", $categoryId, 510);

				if(isset($jsonData->settings->maxChargeCurrentAC)) { $this->SaveVariableValue($jsonData->settings->maxChargeCurrentAC, $dummyModulId, "maxChargeCurrentAC", "max Charge Current AC", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->settings->autoUnlockPlugWhenCharged)) { $this->SaveVariableValue($jsonData->settings->autoUnlockPlugWhenCharged, $dummyModulId, "autoUnlockPlugWhenCharged", "auto Unlock Plug When Charged", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->settings->targetSoc_pct)) { $this->SaveVariableValue($jsonData->settings->targetSoc_pct, $dummyModulId, "targetSoc_pct", "Target SOC", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false); }				

				if(isset($jsonData->settings->batteryCareModeEnabled)) { $this->SaveVariableValue($jsonData->settings->batteryCareModeEnabled, $dummyModulId, "batteryCareModeEnabled", "Battery Care Mode Enabled", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->settings->batteryCareTargetSocPercentage)) { $this->SaveVariableValue($jsonData->settings->batteryCareTargetSocPercentage, $dummyModulId, "batteryCareTargetSocPercentage", "Battery Care Target SCO", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false); }
				if(isset($jsonData->settings->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->settings->carCapturedTimestamp), $dummyModulId, "charging_carCapturedTimestamp", "Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }
			}


			// Climatisation Status
			$apiUrl = sprintf("%s/v1/vehicles/%s/climatisation/status", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				$dummyModulId = $this->GetDummyModuleID("climatisationStatus", "Climatisation Status", $categoryId, 600);

				if(isset($jsonData->climatisationStatus->remainingClimatisationTimeInMinutes)) { $this->SaveVariableValue($jsonData->climatisationStatus->remainingClimatisationTimeInMinutes, $dummyModulId, "remainingClimatisationTimeInMinutes", "Remaining Climatisation Time", VARIABLE::TYPE_INTEGER, $pos++, "EV.RemainingMin", false); }
				if(isset($jsonData->climatisationStatus->climatisationState)) { $this->SaveVariableValue($jsonData->climatisationStatus->climatisationState, $dummyModulId, "climatisationState", "Climatisation State", VARIABLE::TYPE_STRING, $pos++, "", false); }
				if(isset($jsonData->climatisationStatus->climatisationTrigger)) { $this->SaveVariableValue($jsonData->climatisationStatus->climatisationTrigger, $dummyModulId, "climatisationTrigger", "Climatisation Trigger", VARIABLE::TYPE_STRING, $pos++, "", false); }				
				if(isset($jsonData->climatisationStatus->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->climatisationStatus->carCapturedTimestamp), $dummyModulId, "climaStatus_carCapturedTimestamp", "Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }
						
				$dummyModulId = $this->GetDummyModuleID("windowHeatingStatus", "Window Heating Status", $categoryId, 610);
				if(isset($jsonData->windowHeatingStatus->windowHeatingStatus)) {
					foreach($jsonData->windowHeatingStatus->windowHeatingStatus as $windowHeatingStatus) {
						$windowLocation = $windowHeatingStatus->windowLocation;
						$windowHeatingState = $windowHeatingStatus->windowHeatingState;
						$this->SaveVariableValue($windowHeatingState, $dummyModulId, "window_".$windowLocation, $windowLocation, VARIABLE::TYPE_STRING, $pos++, "", false); 
					}
				}
				if(isset($jsonData->windowHeatingStatus->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->climatisationStatus->carCapturedTimestamp), $dummyModulId, "windowHeatingStatus_carCapturedTimestamp", "Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }
			
			}


			// Climatisation Status
			$apiUrl = sprintf("%s/v2/vehicles/%s/climatisation/settings", $baseApiUrl, $this->vin);
			$jsonData = $this->FetchVehicleData($apiUrl);
			if($jsonData !== false) {
				$dummyModulId = $this->GetDummyModuleID("climatisationSettings", "Climatisation settings", $categoryId, 650);
				if(isset($jsonData->targetTemperatureInCelsius)) { $this->SaveVariableValue($jsonData->targetTemperatureInCelsius, $dummyModulId, "targetTemperatureInCelsius", "Target Temperature", VARIABLE::TYPE_FLOAT, $pos++, "~Temperature", false); }
				if(isset($jsonData->targetTemperatureInFahrenheit)) { $this->SaveVariableValue($jsonData->targetTemperatureInFahrenheit, $dummyModulId, "targetTemperatureInFahrenheit", "Target Temperature (In Fahrenheit)", VARIABLE::TYPE_FLOAT, $pos++, "", false); }
				if(isset($jsonData->unitInCar)) { $this->SaveVariableValue($jsonData->unitInCar, $dummyModulId, "unitInCar", "Unit In Car", VARIABLE::TYPE_STRING, $pos++, "", false); }				
				if(isset($jsonData->climatisationAtUnlock)) { $this->SaveVariableValue($jsonData->climatisationAtUnlock, $dummyModulId, "climatisationAtUnlock", "Climatisation at Unlock", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->windowHeatingEnabled)) { $this->SaveVariableValue($jsonData->windowHeatingEnabled, $dummyModulId, "windowHeatingEnabled", "Window Heating Enabled", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->zoneFrontLeftEnabled)) { $this->SaveVariableValue($jsonData->zoneFrontLeftEnabled, $dummyModulId, "zoneFrontLeftEnabled", "Zone Front Left Enabled", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->zoneFrontRightEnabled)) { $this->SaveVariableValue($jsonData->zoneFrontRightEnabled, $dummyModulId, "zoneFrontRightEnabled", "Zone Front Right Enabled", VARIABLE::TYPE_BOOLEAN, $pos++, "", false); }
				if(isset($jsonData->carCapturedTimestamp)) { $this->SaveVariableValue(strtotime($jsonData->carCapturedTimestamp), $dummyModulId, "climaStatus_carCapturedTimestamp", "Fahrzeug Zeitstempel", VARIABLE::TYPE_INTEGER, $pos++, "~UnixTimestamp", false); }
			}

			SetValue($this->GetIDForIdent("lastUpdateVehicleData"),  time());  
		}

	}



	public function UpdateVehicleData_old(string $caller='?') {

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateVehicleData [%s] ...", $caller)); }

		$jsonData = $this->FetchVehicleData($this->vin);
		if($jsonData !== false) {

				$pos = 0;
				$categoryId = $this->GetCategoryID($this->vin, $this->vin, IPS_GetParent($this->InstanceID), 21);

				$dummyModulId = $this->GetDummyModuleID("primaryEngine", "Primary Engine", $categoryId, 20);
				if(isset($jsonData->engines->primary)) {
					$primaryEngine = $jsonData->engines->primary;
					$this->SaveVariableValue($primaryEngine->type, $dummyModulId, "type", "Type", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->fuelType, $dummyModulId, "fuelType", "Tuel Type", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->range->value, $dummyModulId, "range_value", "Range", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false);
					$this->SaveVariableValue($primaryEngine->range->unit, $dummyModulId, "range_unit", "Range Unit", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($primaryEngine->level, $dummyModulId, "level", "Level", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);
				}

				$dummyModulId = $this->GetDummyModuleID("measurements", "Measurements", $categoryId, 25);
				if(isset($jsonData->measurements)) {
					$measurements = $jsonData->measurements;
					$this->SaveVariableValue($measurements->mileageKm, $dummyModulId, "Odometer", "Kilometerstand", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false);
				} else {
					//$this->SaveVariableValue(0.0, $dummyModulId, "Odometer", "Kilometerstand", VARIABLE::TYPE_INTEGER, $pos++, "EV.km", false);
				}

				$dummyModulId = $this->GetDummyModuleID("charging", "Charging", $categoryId, 30);
				if(isset($jsonData->services->charging)) {
					$charging = $jsonData->services->charging;
					$this->SaveVariableValue($charging->status, $dummyModulId, "status", "Status", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($charging->targetPct, $dummyModulId, "targetPct", "target Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);
					$this->SaveVariableValue($charging->chargeMode, $dummyModulId, "chargeMode", "Charge Mode", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($charging->active, $dummyModulId, "active", "active", VARIABLE::TYPE_BOOLEAN, $pos++, "", false);
					$this->SaveVariableValue($charging->remainingTime, $dummyModulId, "remainingTime", "Remaining Time", VARIABLE::TYPE_INTEGER, $pos++, "", false);
					$this->SaveVariableValue($charging->progressBarPct, $dummyModulId, "progressBarPct", "ProgressBar Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);
				}

				$dummyModulId = $this->GetDummyModuleID("climatisation", "climatisation", $categoryId, 40);
				if(isset($jsonData->services->climatisation)) {
					$climatisation = $jsonData->services->climatisation;
					$this->SaveVariableValue($climatisation->status, $dummyModulId, "status", "Status", VARIABLE::TYPE_STRING, $pos++, "", false);
					$this->SaveVariableValue($climatisation->active, $dummyModulId, "active", "active", VARIABLE::TYPE_BOOLEAN, $pos++, "", false);
					$this->SaveVariableValue($climatisation->targetTemperatureKelvin, $dummyModulId, "targetTemperatureKelvin", "targetTemperatureKelvin", VARIABLE::TYPE_FLOAT, $pos++, "~Temperature", false, -273.15);
					$this->SaveVariableValue($climatisation->remainingTime, $dummyModulId, "remainingTime", "Remaining Time", VARIABLE::TYPE_INTEGER, $pos++, "", false);
					$this->SaveVariableValue($climatisation->progressBarPct, $dummyModulId, "progressBarPct", "ProgressBar Pct", VARIABLE::TYPE_INTEGER, $pos++, "EV.level", false);					
				}	
				SetValue($this->GetIDForIdent("lastUpdateVehicleData"),  time());  

				$logVehicleData = $this->ReadPropertyBoolean("logVehicleData");
				if($logVehicleData) {
					$this->WriteToLogFile(json_encode($jsonData), "EV/");
				}

		}
	}

	public function UpdateData(string $caller='?') {

		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("UpdateData [%s] ...", $caller)); }

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
					if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "Update IPS Variables DONE"); }

				} catch (Exception $e) {
					$errorMsg = $e->getMessage();
					//$errorMsg = print_r($e, true);
					SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
					SetValue($this->GetIDForIdent("updateLastError"), $errorMsg);
					if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, sprintf("Exception occurred :: %s", $errorMsg)); }
				}

				//$duration = $this->CalcDuration_ms($start_Time);
				//SetValue($this->GetIDForIdent("updateLastDuration"), $duration); 

			} else {
				//SetValue($this->GetIDForIdent("instanzInactivCnt"), GetValue($this->GetIDForIdent("instanzInactivCnt")) + 1);
				if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Instanz '%s - [%s]' not activ [Status=%s]", $this->InstanceID, IPS_GetName($this->InstanceID), $currentStatus)); }
			}
			
	}	


	public function Reset_UpdateVariables(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET Update Variables [%s] ...", $caller)); }
		SetValue($this->GetIDForIdent("lastUpdateUserInfo"), 0);
		SetValue($this->GetIDForIdent("lastUpdateVehiclesAndEnrollment"), 0);
		SetValue($this->GetIDForIdent("lastUpdateVehicleData"), 0);
		SetValue($this->GetIDForIdent("updateCntOk"), 0);
		SetValue($this->GetIDForIdent("updateCntSkip"), 0);
		SetValue($this->GetIDForIdent("updateCntError"), 0); 
		SetValue($this->GetIDForIdent("updateLastError"), "-"); 
	}

	public function Reset_oAuthData(string $caller='?') {
		if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RESET oAuth Variables [%s] ...", $caller)); }
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
		
		if ( !IPS_VariableProfileExists('EV.kWatt') ) {
			IPS_CreateVariableProfile('EV.kWatt', VARIABLE::TYPE_FLOAT );
			IPS_SetVariableProfileDigits('EV.kWatt', 3 );
			IPS_SetVariableProfileText('EV.kWatt', "", " kW" );
			//IPS_SetVariableProfileValues('EV.kWatt', 0, 0, 0);
		} 		

		if ( !IPS_VariableProfileExists('EV.kmph') ) {
			IPS_CreateVariableProfile('EV.kmph', VARIABLE::TYPE_FLOAT );
			IPS_SetVariableProfileDigits('EV.kmph', 3 );
			IPS_SetVariableProfileText('EV.kmph', "", " km pro Stunde" );
			//IPS_SetVariableProfileValues('EV.kmph', 0, 0, 0);
		} 		

		if ( !IPS_VariableProfileExists('EV.kWh') ) {
			IPS_CreateVariableProfile('EV.kWh', VARIABLE::TYPE_FLOAT );
			IPS_SetVariableProfileDigits('EV.kWh', 1 );
			IPS_SetVariableProfileText('EV.kWh', "", " kWh" );
			//IPS_SetVariableProfileValues('EV.kWh', 0, 0, 0);
		} 

		if ( !IPS_VariableProfileExists('EV.RemainingMin') ) {
			IPS_CreateVariableProfile('EV.RemainingMin', VARIABLE::TYPE_INTEGER );
			IPS_SetVariableProfileDigits('EV.RemainingMin', 0 );
			IPS_SetVariableProfileText('EV.RemainingMin', "", " Min" );
			//IPS_SetVariableProfileValues('EV.RemainingMin', 0, 0, 0);
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


		if ( !IPS_VariableProfileExists('EV.connection.mode') ) {
			IPS_CreateVariableProfile('EV.connection.mode', VARIABLE::TYPE_INTEGER );
			IPS_SetVariableProfileText('EV.connection.mode', "", "" );
			IPS_SetVariableProfileAssociation ('EV.connection.mode', -1, "[%d] n.a.", "", -1);
			IPS_SetVariableProfileAssociation ('EV.connection.mode', 0,  "[%d] offline", "", -1);
			IPS_SetVariableProfileAssociation ('EV.connection.mode', 1,  "[%d] online", "", -1);
		}

		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Profiles registered"); }
	}

	protected function RegisterVariables() {
		
		$this->RegisterVariableInteger("lastUpdateUserInfo", "last Update 'User Info'", "~UnixTimestamp", 900);
		$this->RegisterVariableInteger("lastUpdateVehiclesAndEnrollment", "last Update 'Vehicles & Enrollment'", "~UnixTimestamp", 901);
		$this->RegisterVariableInteger("lastUpdateVehicleData", "last Update 'Vehicle Status'", "~UnixTimestamp", 902);

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

		$archivInstanzID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
		IPS_ApplyChanges($archivInstanzID);
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, "Variables registered"); }

	}


	protected function AddLog($name, $daten, $format=0) {
		$this->logCnt++;
		$logSender = "[".__CLASS__."] - " . $name;
		if($this->logLevel >= LogLevel::DEBUG) {
			$logSender = sprintf("%02d-T%2d [%s] - %s", $this->logCnt, $_IPS['THREAD'], __CLASS__, $name);
		} 
		$this->SendDebug($logSender, $daten, $format); 	
	
		if($this->enableIPSLogOutput) {
			if($format == 0) {
				IPS_LogMessage($logSender, $daten);	
			} else {
				IPS_LogMessage($logSender, $this->String2Hex($daten));			
			}
		}
	}

}