<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/COMMON.php'; 
require_once __DIR__ . '/../libs/vendor/autoload.php';

/**
 * BoschEBike – IP-Symcon Modul für die Bosch eBike Data Act API
 *
 * Fragt periodisch Bike-Profil und Telemetriedaten ab und
 * speichert sie als IPS-Variablen. OAuth 2.0 Refresh-Token-Flow
 * wird vom Modul automatisch verwaltet.
 *
 * Setup:
 *  1. Bosch Portal: App anlegen → Client-ID + Secret erhalten
 *  2. In Postman: Einmalig Authorization Code Flow durchführen
 *     → Access Token + Refresh Token kopieren
 *  3. Im Modul: Client-ID, Secret, Bike-ID und beide Tokens eintragen
 *  4. Speichern → Modul übernimmt ab hier den Token-Refresh selbst
 */

class BoschEBike extends IPSModule {

	use EV_COMMON;

    // ─── API-Konstanten ───────────────────────────────────────────────────────
    const BASE_URL  = 'https://api.bosch-ebike.com';
    const TOKEN_URL = 'https://p9.authz.bosch.com/auth/realms/obc/protocol/openid-connect/token';

    // Bike-Profil: GET /smart-system/v1/bikes/{bikeId}
    const ENDPOINT_BIKE   = '/bike-profile/smart-system/v1/bikes/%s';
    // Telemetrie: GET /smart-system/v1/bikes/{bikeId}/telemetry
    const ENDPOINT_TELEM  = '/smart-system/v1/bikes/%s/telemetry';
    // Alle Bikes auflisten: GET /smart-system/v1/bikes
    const ENDPOINT_BIKES  = '/bike-profile/smart-system/v1/bikes';


	private $logLevel = 3;
	private $logCnt = 0;
	private $enableIPSLogOutput = false;    

	private $client;

	public function __construct($InstanceID) {
	
		parent::__construct($InstanceID);		// Diese Zeile nicht löschen
		
		//$this->logLevel = @$this->ReadPropertyInteger("LogLevel"); 
        $this->logLevel = LogLevel::TRACE;
		if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("Log-Level is %d", $this->logLevel)); }

		$currentStatus = @$this->GetStatus();
		if($currentStatus == 102) {				//Instanz ist aktiv
			$this->client = new GuzzleHttp\Client(['verify' => false]);	//disable SSL-Certificate verify
		} else {
			if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("Current Status is '%s'", $currentStatus)); }	
		}

	}

    public function Create(): void  {
        
        parent::Create();

        // Konfigurierbare Eigenschaften (durch Benutzer gesetzt)
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('BikeID', '');
        $this->RegisterPropertyString('InitialAccessToken', '');
        $this->RegisterPropertyString('InitialRefreshToken', '');
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('DebugLog', false);

        // Persistente Attribute (vom Modul verwaltet, ändern sich zur Laufzeit)
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);   // Unix-Timestamp
        $this->RegisterAttributeInteger('RetryCount', 0);

        // Variablenprofile anlegen
        $this->RegisterProfiles();

        // Update-Timer
        $this->RegisterTimer('UpdateTimer', 0, 'BoschEBike_UpdateData($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();

        // Initiale Tokens aus Properties übernehmen (falls Attribute noch leer)
        $this->SeedInitialTokens();

        // IPS-Variablen anlegen / pflegen
        $this->MaintainVariables();

        // Timer aktivieren oder deaktivieren
        $this->UpdateTimer();
    }

    public function UpdateData(): bool {

        $result = false;
        try {

            $bikeId = $this->ReadPropertyString('BikeID');
            if ($bikeId === '') {
                $this->SetValue('APIStatus', 'Fehler: Bike-ID nicht konfiguriert');
                return false;
            }

            $apiUrl = self::BASE_URL . sprintf(self::ENDPOINT_BIKE, $bikeId);
            $jsonData = $this->FetchBikeData($apiUrl);
            if($jsonData !== false) {

                if(isset($jsonData->createdAt)) { $this->SaveVariableValue(strtotime($jsonData->createdAt), $this->InstanceID, "createdAt", "Created At", VARIABLETYPE_INTEGER, 10, "~UnixTimestamp", false); }

                if(isset($jsonData->driveUnit)) {
                    $jsonDriveUnit = $jsonData->driveUnit;
                    $dummyModulId_DriveUnit = $this->GetDummyModuleID("driveUnit", "Drive Unit", $this->InstanceID, 100);
                    if(isset($jsonDriveUnit->productName)) { $this->SaveVariableValue($jsonDriveUnit->productName, $dummyModulId_DriveUnit, "productName", "Produc tName", VARIABLETYPE_STRING, 10, "", false);  }
                    if(isset($jsonDriveUnit->partNumber)) { $this->SaveVariableValue($jsonDriveUnit->partNumber, $dummyModulId_DriveUnit, "partNumber", "Part Number", VARIABLETYPE_STRING, 11, "", false);  }
                    if(isset($jsonDriveUnit->serialNumber)) { $this->SaveVariableValue($jsonDriveUnit->serialNumber, $dummyModulId_DriveUnit, "serialNumber", "Serial Number", VARIABLETYPE_STRING, 12, "", false); }

                    if(isset($jsonDriveUnit->odometer)) { $this->SaveVariableValue($jsonDriveUnit->odometer / 1000, $dummyModulId_DriveUnit, "odometer", "Odometer", VARIABLETYPE_FLOAT, 20, "BoschEBike.km", false); }
                    if(isset($jsonDriveUnit->rearWheelCircumferenceUser)) { $this->SaveVariableValue($jsonDriveUnit->rearWheelCircumferenceUser, $dummyModulId_DriveUnit, "rearWheelCircumferenceUser", "Hinterradumfang", VARIABLETYPE_FLOAT, 30, "", false); }
                    if(isset($jsonDriveUnit->maximumAssistanceSpeed)) { $this->SaveVariableValue($jsonDriveUnit->maximumAssistanceSpeed, $dummyModulId_DriveUnit, "maximumAssistanceSpeed", "Maximum AssistanceSpeed", VARIABLETYPE_FLOAT, 40, "BoschEBike.kmh", false); }


                    if(isset($jsonDriveUnit->activeAssistModes)) {       
                    $dummyModulId_ActiveAssistModes = $this->GetDummyModuleID("activeAssistModes", "Assist Modes - Reachable Range", $dummyModulId_DriveUnit, 100);
                        foreach ($jsonDriveUnit->activeAssistModes as $jsonActiveAssistMode) {
                            if(isset($jsonActiveAssistMode->name)) { $this->SaveVariableValue($jsonActiveAssistMode->reachableRange, $dummyModulId_ActiveAssistModes, $jsonActiveAssistMode->name, $jsonActiveAssistMode->name, VARIABLETYPE_FLOAT, 0, "BoschEBike.km", false); }

                        }
                    }

                    if(isset($jsonDriveUnit->powerOnTime)) {                    
                        if(isset($jsonDriveUnit->powerOnTime->total)) { $this->SaveVariableValue($jsonDriveUnit->powerOnTime->total, $dummyModulId_DriveUnit, "powerOnTimeTotal", "PowerOnTime - Total", VARIABLETYPE_FLOAT, 50, "BoschEBike.h", false); }
                        if(isset($jsonDriveUnit->powerOnTime->withMotorSupport)) { $this->SaveVariableValue($jsonDriveUnit->powerOnTime->withMotorSupport, $dummyModulId_DriveUnit, "PowerOnTime_WithMotorSupport", "PowerOnTime - WithMotorSupport", VARIABLETYPE_FLOAT, 51, "BoschEBike.h", false); }
                    }

                    if(isset($jsonDriveUnit->walkAssistConfiguration)) {                    
                        if(isset($jsonDriveUnit->walkAssistConfiguration->isEnabled)) { $this->SaveVariableValue($jsonDriveUnit->walkAssistConfiguration->isEnabled, $dummyModulId_DriveUnit, "walkAssistEnabled", "WalkAssist Enabled", VARIABLETYPE_BOOLEAN, 20, "", false); }
                        if(isset($jsonDriveUnit->walkAssistConfiguration->maximumSpeed)) { $this->SaveVariableValue($jsonDriveUnit->walkAssistConfiguration->maximumSpeed, $dummyModulId_DriveUnit, "walkAssistMaximumSpeed", "WalkAssist MaximumSpeed", VARIABLETYPE_FLOAT, 21, "BoschEBike.kmh", false); }
                    }

                }

                if(isset($jsonData->remoteControl)) {
                    $jsonRemoteControl = $jsonData->remoteControl;
                    $dummyModulId_remoteControl = $this->GetDummyModuleID("remoteControl", "RemoteControl", $this->InstanceID, 200);
    
                    if(isset($jsonRemoteControl->productName)) { $this->SaveVariableValue($jsonRemoteControl->productName, $dummyModulId_remoteControl, "productName", "Produc tName", VARIABLETYPE_STRING, 10, "", false);  }
                    if(isset($jsonRemoteControl->partNumber)) { $this->SaveVariableValue($jsonRemoteControl->partNumber, $dummyModulId_remoteControl, "partNumber", "Part Number", VARIABLETYPE_STRING, 11, "", false);  }
                    if(isset($jsonRemoteControl->serialNumber)) { $this->SaveVariableValue($jsonRemoteControl->serialNumber, $dummyModulId_remoteControl, "serialNumber", "Serial Number", VARIABLETYPE_STRING, 12, "", false);  }
    
                }   

                if(isset($jsonData->batteries[0])) {
                    $jsonBatterie = $jsonData->batteries[0];
                    $dummyModulId_Batterie_0 = $this->GetDummyModuleID("batteries", "Batteries", $this->InstanceID, 300);
                    
                    if(isset($jsonBatterie->productName)) { $this->SaveVariableValue($jsonBatterie->productName, $dummyModulId_Batterie_0, "productName", "Product Name", VARIABLETYPE_STRING, 10, "", false);  }
                    if(isset($jsonBatterie->partNumber)) { $this->SaveVariableValue($jsonBatterie->partNumber, $dummyModulId_Batterie_0, "partNumber", "Part Number", VARIABLETYPE_STRING, 11, "", false);  }
                    if(isset($jsonBatterie->serialNumber)) { $this->SaveVariableValue($jsonBatterie->serialNumber, $dummyModulId_Batterie_0, "serialNumber", "Serial Number", VARIABLETYPE_STRING, 12, "", false);  }

                    if(isset($jsonBatterie->deliveredWhOverLifetime)) { $this->SaveVariableValue($jsonBatterie->deliveredWhOverLifetime, $dummyModulId_Batterie_0, "deliveredWhOverLifetime", "Delivered OverLifetime", VARIABLETYPE_FLOAT, 20, "BoschEBike.Wh", false); }

                    if(isset($jsonBatterie->chargeCycles)) {                    
                        if(isset($jsonBatterie->chargeCycles->total)) { $this->SaveVariableValue($jsonBatterie->chargeCycles->total, $dummyModulId_Batterie_0, "chargeCycles_total", "ChargeCycles Total", VARIABLETYPE_FLOAT, 30, "", false); }
                        if(isset($jsonBatterie->chargeCycles->onBike)) { $this->SaveVariableValue($jsonBatterie->chargeCycles->onBike, $dummyModulId_Batterie_0, "chargeCycles_onBike", "ChargeCycles OnBike", VARIABLETYPE_FLOAT, 31, "", false); }
                        if(isset($jsonBatterie->chargeCycles->offBike)) { $this->SaveVariableValue($jsonBatterie->chargeCycles->offBike, $dummyModulId_Batterie_0, "chargeCycles_offBike", "ChargeCycles OffBike", VARIABLETYPE_FLOAT, 32, "", false); }
                    }

                }            

                if(isset($jsonData->serviceDue)) {
                    $jsonServiceDue = $jsonData->serviceDue;
                    $dummyModulId_serviceDue = $this->GetDummyModuleID("serviceDue", "Service Due", $this->InstanceID, 400);
    
                    if(isset($jsonServiceDue->date)) { $this->SaveVariableValue(strtotime($jsonServiceDue->date), $dummyModulId_serviceDue, "serviceDueDate", "Date", VARIABLETYPE_INTEGER, 10, "~UnixTimestamp", false); }
                    if(isset($jsonServiceDue->odometer)) { $this->SaveVariableValue($jsonServiceDue->odometer / 1000, $dummyModulId_serviceDue, "serviceDueOdometer", "Odometer", VARIABLETYPE_FLOAT, 11, "BoschEBike.km", false); }
    
                }   

                $this->SaveVariableValue(time(), $this->InstanceID, "LastUpdate", "LastUpdate", VARIABLETYPE_INTEGER, 800, "~UnixTimestamp", false); 
                $this->SaveVariableValue('Update OK', $this->InstanceID, "APIStatus", "APIStatus", VARIABLETYPE_STRING, 800, "", false); 

                $this->WriteAttributeInteger('RetryCount', 0);
                $result = true;

            } else{
                $this->SaveVariableValue('Update Failed > no jsonData', $this->InstanceID, "APIStatus", "APIStatus", VARIABLETYPE_STRING, 800, "", false); 
                $result = false;
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->SaveVariableValue($msg, $this->InstanceID, "APIStatus", "APIStatus", VARIABLETYPE_STRING, 800, "", false); 
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {

            if($result) {
                SetValue($this->GetIDForIdent("updateCntOk"), GetValue($this->GetIDForIdent("updateCntOk")) + 1); 
            } else {
                SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1); 
            }

            return $result;
        }
    }


    public function FetchBikeData(string $apiUrl, bool $isRetry = false) {
        $result = false;
        try {

            $token = $this->EnsureValidToken();
            if ($token === '') {
                return false;
            }

            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $apiUrl )); }

            $res = $this->client->request('GET', $apiUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept: application/json',                  
                    ]
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode )); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Bike Data: %s", $responseData)); }
                $result = json_decode($responseData);
            } else if ($statusCode === 401 && !$isRetry) {          // 401 → Token abgelaufen → Refresh → einmal wiederholen
                $this->SendDebug('OAuth', '401 – starte Token-Refresh', 0);
                if ($this->RefreshAccessToken()) {
                    return $this->FetchBikeData($apiUrl, true);
                }
            } else {
                $result = false;
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'!", $statusCode, __FUNCTION__);
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);  
            }
      
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        }
    }


    /**
     * Access Token manuell per Refresh Token erneuern.
     * Gibt true bei Erfolg, false bei Fehler zurück.
     */
    public function RefreshAccessToken(): bool {
        $clientId     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        if ($clientId === '') {
            $this->SetValue('APIStatus', 'Fehler: Client-ID fehlt');
            return false;
        }
        if ($refreshToken === '') {
            $this->SetValue('APIStatus', 'Fehler: Kein Refresh-Token – bitte in Konfiguration eintragen');
            return false;
        }

        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $response = $this->HttpPost(self::TOKEN_URL, $postData, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if ($response === false) {
            $this->SetValue('APIStatus', 'Fehler: Token-Endpoint nicht erreichbar');
            return false;
        }

        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            $error = $data['error_description'] ?? $data['error'] ?? $response;
            $this->LogMessage("BoschEBike Token-Refresh fehlgeschlagen: {$error}", KL_ERROR);
            $this->SetValue('APIStatus', "Token-Refresh fehlgeschlagen: {$error}");
            return false;
        }

        $this->WriteAttributeString('AccessToken', $data['access_token']);

        // Bosch gibt oft einen neuen Refresh-Token zurück (Rotation)
        if (!empty($data['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
            $this->SendDebug('OAuth', 'Neuer Refresh-Token gespeichert', 0);
        }

        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 300;
        $this->WriteAttributeInteger('TokenExpiry', time() + $expiresIn);

        $this->SendDebug('OAuth', "Token-Refresh OK, gültig {$expiresIn}s", 0);
        return true;
    }


    /**
     * Token-Status anzeigen (Debug-Hilfe).
     */
    public function GetTokenStatus(): string  {
        $expiry  = $this->ReadAttributeInteger('TokenExpiry');
        $hasAT   = $this->ReadAttributeString('AccessToken') !== '';
        $hasRT   = $this->ReadAttributeString('RefreshToken') !== '';
        $expires = $expiry > 0 ? date('d.m.Y H:i:s', $expiry) : 'unbekannt';
        $ttl     = $expiry > 0 ? max(0, $expiry - time()) : 0;

        return sprintf(
            "Access-Token vorhanden: %s\nRefresh-Token vorhanden: %s\nAccess-Token gültig bis: %s (noch %ds)\nBike-ID: %s",
            $hasAT ? 'Ja' : 'NEIN',
            $hasRT ? 'Ja' : 'NEIN',
            $expires,
            $ttl,
            $this->ReadPropertyString('BikeID') ?: '(nicht gesetzt)'
        );
    }


    /**
     * Stellt sicher, dass ein gültiger Access-Token vorhanden ist.
     * Refresht proaktiv 90 Sekunden vor Ablauf.
     */
    private function EnsureValidToken(): string  {
        $expiry = $this->ReadAttributeInteger('TokenExpiry');

        // Proaktiver Refresh: 90s vor Ablauf
        if ($expiry > 0 && time() >= ($expiry - 90)) {
            $this->SendDebug('OAuth', 'Proaktiver Refresh (Token läuft bald ab)', 0);
            $this->RefreshAccessToken();
        }

        $token = $this->ReadAttributeString('AccessToken');
        if ($token === '') {
            $this->SetValue('APIStatus', 'Fehler: Kein Access-Token – bitte Tokens in Konfiguration eintragen');
            $this->LogMessage('BoschEBike: Kein Access-Token vorhanden', KL_WARNING);
        }

        return $token;
    }


    private function HttpGet(string $url, array $headers): string|false {
        $opts = [
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
        ];
        return @file_get_contents($url, false, stream_context_create($opts));
    }

    private function HttpPost(string $url, string $body, array $headers): string|false {
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
        ];
        return @file_get_contents($url, false, stream_context_create($opts));
    }

    // ─── Setup-Hilfsmethoden ──────────────────────────────────────────────────

    private function SeedInitialTokens(): void {
        // Access-Token aus Property übernehmen falls Attribut noch leer
        if ($this->ReadAttributeString('AccessToken') === '') {
            $t = $this->ReadPropertyString('InitialAccessToken');
            if ($t !== '') {
                $this->WriteAttributeString('AccessToken', $t);
                // Expiry unbekannt → auf "jetzt" setzen → sofortiger Refresh beim nächsten Aufruf
                $this->WriteAttributeInteger('TokenExpiry', time() + 60);
                $this->LogMessage('BoschEBike: Initial-Access-Token übernommen', KL_NOTIFY);
            }
        }

        // Refresh-Token aus Property übernehmen falls Attribut noch leer
        if ($this->ReadAttributeString('RefreshToken') === '') {
            $t = $this->ReadPropertyString('InitialRefreshToken');
            if ($t !== '') {
                $this->WriteAttributeString('RefreshToken', $t);
                $this->LogMessage('BoschEBike: Initial-Refresh-Token übernommen', KL_NOTIFY);
            }
        }
    }

    private function MaintainVariables(): void {

		$this->MaintainVariable("updateCntOk", "Update Cnt", VARIABLETYPE_INTEGER, '', 800, true);
		$this->MaintainVariable("updateCntError", "Update Cnt Error", VARIABLETYPE_INTEGER, '', 801, true);
        $this->MaintainVariable('LastUpdate', 'Letztes Update',  VARIABLETYPE_INTEGER, '', 810, true);
        $this->MaintainVariable('APIStatus',  'API-Status',      VARIABLETYPE_STRING,  '', 811, true);
    }

    private function RegisterProfiles(): void {
        $profiles = [
            'BoschEBike.km'   => ['Kilometer',       ' km',   0.0, 0.0, 0.0, 1],
            'BoschEBike.kmh'  => ['km/h',            ' km/h', 0.0, 0.0, 0.0, 1],
            'BoschEBike.h'    => ['Stunden',          ' h',    0.0, 0.0, 0.0, 2],
            'BoschEBike.min'  => ['Minuten',          ' min',  0.0, 0.0, 0.0, 1],
            'BoschEBike.Wh'   => ['Wh',              ' Wh',   0.0, 0.0, 0.0, 0],
            'BoschEBike.W'    => ['Watt',             ' W',    0.0, 0.0, 0.0, 0],
            'BoschEBike.kcal' => ['Kalorien',         ' kcal', 0.0, 0.0, 0.0, 0],
            'BoschEBike.rpm'  => ['Trittfrequenz',    ' rpm',  0.0, 0.0, 0.0, 0],
        ];

        foreach ($profiles as $name => [$icon, $suffix, $min, $max, $step, $digits]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileText($name, '', $suffix);
                IPS_SetVariableProfileDigits($name, $digits);
            }
        }
    }

    private function UpdateTimer(): void {
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $bikeId   = $this->ReadPropertyString('BikeID');
        $clientId = $this->ReadPropertyString('ClientID');

        $ready = $interval > 0 && $bikeId !== '' && $clientId !== '';
        $this->SetTimerInterval('UpdateTimer', $ready ? $interval * 60 * 1000 : 0);
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