<?php 

trait EaseeCloud_API {

    static $API_BaseURL = 'https://api.easee.cloud/api';
    static $USER_AGENT = 'IPS/x.x';

    private $userId;

    private $oAuth_tokenType;
    private $oAuth_accessToken;
    private $oAuth_accessTokenExpiresIn;
    private $oAuth_accessTokenExpiresAt;
    private $oAuth_accessClaims;
    private $oAuth_refreshToken;

    private $helperVarPos = 0;


    private function AuthenticateRetrieveAccessToken() {

        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
  
            $url = self::$API_BaseURL . '/accounts/login';
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Login URL: %s", $url ), 0); }

            $res =	$this->client->request('POST', $url,
                [
                    'headers' => [
                        'user-agent' => self::$USER_AGENT,
                        'content-type' => 'application/*+json',
                        'accept' => 'application/json',
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'body' => '{"userName":"' . $this->userName . '","password":"' . $this->password . '"}',
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode ), 0); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Login Response Data: %s", $responseData), 0); }

                $responseJson = json_decode($responseData , true); 
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Response Json Data: %s", print_r($responseJson, true)), 0); }	
    
                if (!$responseJson['accessToken'] || !$responseJson['expiresIn'] || !$responseJson['refreshToken']) {
                    $msg = "ERROR :: Invalid response from Login request!";
                    if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                    throw new \Exception($msg);
                }
    
                $this->oAuth_tokenType = $responseJson['tokenType'];
                $this->oAuth_accessToken = $responseJson['accessToken'];
                $this->oAuth_accessTokenExpiresIn = $responseJson['expiresIn'];
                $this->oAuth_accessTokenExpiresAt = time() + $this->oAuth_accessTokenExpiresIn;
                $this->oAuth_accessClaims = $responseJson['accessClaims'];
                $this->oAuth_refreshToken = $responseJson['refreshToken'];
    
                $this->oAuth_accessClaims = implode(",",  $this->oAuth_accessClaims);

                SetValue($this->GetIDForIdent("oAuth_tokenType"), $this->oAuth_tokenType);
                SetValue($this->GetIDForIdent("oAuth_accessToken"), $this->oAuth_accessToken);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"), $this->oAuth_accessTokenExpiresIn);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"), $this->oAuth_accessTokenExpiresAt);
                SetValue($this->GetIDForIdent("oAuth_accessClaims"), $this->oAuth_accessClaims);
                SetValue($this->GetIDForIdent("oAuth_refreshToken"), $this->oAuth_refreshToken);
    
                $result = $this->oAuth_accessToken;
                $this->profilingEnd(__FUNCTION__);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n tokentype: %s \n expiresIn: %s | accessClaims: %s \n accessToken: %s \n refreshToken: %s | ",
                    $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessClaims,  $this->oAuth_accessToken,  $this->oAuth_refreshToken), 0); }	
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("WARN - Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $msg, 0); }         
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            //throw new Exception($msg, 10, $e); 
        } 
        return $result;
    }

    private function fetchRefreshToken() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $url = self::$API_BaseURL . '/accounts/refresh_token';
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("refreshToken URL: %s", $url ), 0); }

            $res =	$this->client->request('POST', $url,
                [
                    'headers' => [
                        'user-agent' => self::$USER_AGENT,
                        'content-type' => 'application/*+json',
                        'accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->oAuth_accessToken,
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'body' => '{"accessToken":"'.$this->oAuth_accessToken.'","refreshToken":"'.$this->oAuth_refreshToken.'"}',
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode ), 0); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("RefreshToken Response Data: %s", $responseData), 0); }

                $responseJson = json_decode($responseData , true); 
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Response Json Data: %s", print_r($responseJson, true)), 0); }	
    
                if (!$responseJson['accessToken'] || !$responseJson['expiresIn'] || !$responseJson['refreshToken']) {
                    $msg = "ERROR :: Invalid response from RefreshToken request!";
                    if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                    throw new \Exception($msg);
                }
    
                $this->oAuth_tokenType = $responseJson['tokenType'];
                $this->oAuth_accessToken = $responseJson['accessToken'];
                $this->oAuth_accessTokenExpiresIn = $responseJson['expiresIn'];
                $this->oAuth_accessTokenExpiresAt = time() + $this->oAuth_accessTokenExpiresIn;
                $this->oAuth_accessClaims = $responseJson['accessClaims'];
                $this->oAuth_refreshToken = $responseJson['refreshToken'];
    
                $this->oAuth_accessClaims = implode(",",  $this->oAuth_accessClaims);

                SetValue($this->GetIDForIdent("oAuth_tokenType"), $this->oAuth_tokenType);
                SetValue($this->GetIDForIdent("oAuth_accessToken"), $this->oAuth_accessToken);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"), $this->oAuth_accessTokenExpiresIn);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"), $this->oAuth_accessTokenExpiresAt);
                SetValue($this->GetIDForIdent("oAuth_accessClaims"), $this->oAuth_accessClaims);
                SetValue($this->GetIDForIdent("oAuth_refreshToken"), $this->oAuth_refreshToken);
    
                $result = true;
                $this->profilingEnd(__FUNCTION__);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n tokentype: %s \n expiresIn: %s | accessClaims: %s \n accessToken: %s \n refreshToken: %s | ",
                    $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessClaims,  $this->oAuth_accessToken,  $this->oAuth_refreshToken), 0); }	
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("WARN - Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);                
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw new Exception($msg, 10, $e);             
        } 
        return $result;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - 

    public function GetAccessToken(): string  {
        $accessToken = false;
        if (time() >= $this->oAuth_accessTokenExpiresAt) {
            if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("INFO: oAuth AcessToken expired at %s > need Refreshed AccessToken", date('d.m.Y H:i:s',$this->oAuth_accessTokenExpiresAt)), 0); }
            if(empty($this->oAuth_refreshToken)) {
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "INFO: oAuth refreshToken is 'empty' > new authentication required", 0); }
                $accessToken = $this->Authenticate("GetAccessToken()");
            } else {
                $accessToken = $this->fetchRefreshToken();
                if($accessToken !== false) {
                    if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "WARN: Problem fetching refrehed Access Tokcne > new authentication required", 0); }
                    $accessToken = $this->Authenticate("GetAccessToken()");
                }
            }
        } else {
            $accessToken = $this->oAuth_accessToken;
        }
        return $accessToken;
    }


    public function Authenticate(string $caller='?') {

        $result = false;
        if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate API [%s] ...", $caller), 0); }

        if (!$this->userName || !$this->password) {
            $msg = "No username or password set";
            if($this->logLevel >= LogLevel::FATAL) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw new \Exception($msg);
        } else {
            
            $result = $this->AuthenticateRetrieveAccessToken();
            if($result !== false) {
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Authenticate and retrieve access Token DONE [%s]", $caller), 0); }
            } else {
                if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, sprintf("FAILD 'Authenticate and retrieve access Token' [%s] !", $caller), 0); }	
            }

        }
        return $result;
    }


    public function RefreshAccessToken(string $caller='?') {
        if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("RefreshAccessToken [%s] ...", $caller), 0); }
        return $this->fetchRefreshToken();
    }



    private function fetchApiData($apiName, $url) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] URL: %s", $apiName, $url ), 0); }
            
            $accessToken = $this->GetAccessToken();

            $res =	$this->client->request('GET', $url,
                [
                    'headers' => [
                        'user-agent' => self::$USER_AGENT,
                        'accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $accessToken,
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'http_errors' => false
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Status: %s ", $apiName, $statusCode), 0); }

            if($statusCode == 200) {
                $result = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Data: %s",  $apiName, $result), 0); }

                 //$resultData = json_decode($resultData , true); 
                //if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Json: %s", $apiName, print_r($resultData, true)), 0); }	
            
                $this->profilingEnd(__FUNCTION__, false);
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("WARN - Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, $msg, 0); }         
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            //throw new Exception($msg, 10, $e); 
        }
        return $result;
    }



    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - 

    public function Get_ChargerState() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $result = $this->fetchApiData("Charger_State", sprintf("%s/chargers/%s/state", self::$API_BaseURL, $this->chargerId));
            $this->profilingEnd(__FUNCTION__);
        } catch (\Exception|\Throwable $e) {
            $result = false;
            $msg = sprintf("_%s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } 
        return $result;
    }

    public function Get_ChargerOngoingChargingSession() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);        
            $result = $this->fetchApiData("Charger_OngoingChargingSession", sprintf("%s/chargers/%s/sessions/ongoing", self::$API_BaseURL, $this->chargerId));
             $this->profilingEnd(__FUNCTION__);
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        } 
    }

    public function Get_ChargerLatestChargingSession() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);        
            $result = $this->fetchApiData("Charger_LatestChargingSession", sprintf("%s/chargers/%s/sessions/latest", self::$API_BaseURL, $this->chargerId));
            $this->profilingEnd(__FUNCTION__);
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        } 
    }

    public function Get_ChargerDetails() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);        
            $result = $this->fetchApiData("Charger_Details", sprintf("%s/chargers/%s/details", self::$API_BaseURL, $this->chargerId));
            $this->profilingEnd(__FUNCTION__);
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        } 
    }

    public function Get_ChargerConfiguration() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);        
            $result = $this->fetchApiData("Charger_Configuration", sprintf("%s/chargers/%s/config", self::$API_BaseURL, $this->chargerId));
            $this->profilingEnd(__FUNCTION__);
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        } 
    }


    public function Get_ChargerSite() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);        
            $result = $this->fetchApiData("Charger_Site", sprintf("%s/chargers/%s/site", self::$API_BaseURL, $this->chargerId));
            $this->profilingEnd(__FUNCTION__);
        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        } 
    }


    //$success = array_walk_recursive($dataArr, array($this, 'ExtractDataValues'), $apiName);
    protected function ExtractDataValues($value, $key, $apiName) {
        $this->AddLog(__FUNCTION__, sprintf("[%s] %s - %s {%s}", $apiName, $key, $value, gettype($value)), 0);
    }

    private function FlattenMultiArr($arr, $apiName, $maxDepth, $depth=0) {
        $depth++;
        $returnArr = array();
        foreach($arr as $key => $value) {

               if(is_array($value)) {
                   if($depth <= $maxDepth) { 
                       $returnArr = array_merge($returnArr, $this->FlattenMultiArr($value, $apiName . "_" . $key, $maxDepth, $depth));
                    }
            } else {
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("%s[%s] %s - %s {%s}", $depth, $apiName, $key, $value, gettype($value)), 0); }
                $returnArr[$apiName] = $value;
            }
        }
        return $returnArr;
    }




}


?>