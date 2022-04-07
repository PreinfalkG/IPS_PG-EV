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
    
                $result = true;
                $this->profilingEnd(__FUNCTION__);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n tokentype: %s \n expiresIn: %s | accessClaims: %s \n accessToken: %s \n refreshToken: %s | ",
                    $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessClaims,  $this->oAuth_accessToken,  $this->oAuth_refreshToken), 0); }	



                $this->profilingEnd(__FUNCTION__);
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);                
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        }  

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

                $this->profilingEnd(__FUNCTION__);
            } else {
                $result = false;
                $responseData = strval($res->getBody());
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'! > %s", $statusCode, __FUNCTION__, $responseData);
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);                
            }

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        }
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - 

    public function GetAccessToken(): string  {
        if (time() >= $this->oAuth_accessTokenExpiresAt) {
            if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("INFO: oAuth AcessToken expired at %s > need Refreshed AccessToken", date('d.m.Y H:i:s',$this->oAuth_accessTokenExpiresAt)), 0); }
            if(empty($this->oAuth_refreshToken)) {
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "INFO: oAuth refreshToken is 'empty' > new authentication required", 0); }
                $this->Authenticate("GetAccessToken()");
            } else {
                $result = $this->fetchRefreshToken();
                if(!$result) {
                    if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "WARN: Problem fetching refrehed Access Tokcne > new authentication required", 0); }
                    $this->Authenticate("GetAccessToken()");
                }
            }
        }
        return $this->oAuth_accessToken;
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


    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - 

    public function Get_ChargerState(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_State", sprintf("%s/chargers/%s/state", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger", 10, "Charger State", 20);
        }
        return $responseData;
    }


    public function Get_ChargerOngoingChargingSession(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_OngoingChargingSession", sprintf("%s/chargers/%s/sessions/ongoing", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger", 10, "OngoingChargingSession", 30);
        }
        return $responseData;
    }

    public function Get_ChargerLatestChargingSession(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_LatestChargingSession", sprintf("%s/chargers/%s/sessions/latest", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger", 10, "LatestChargingSession", 40);
        }
        return $responseData;
    }


    public function Get_ChargerDetails(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_Details", sprintf("%s/chargers/%s/details", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger_Infos", 20, "Charger Details", 10);
        }
        return $responseData;
    }


    public function Get_ChargerConfiguration(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_Configuration", sprintf("%s/chargers/%s/config", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger_Infos", 20, "Configuration", 50);
        }
        return $responseData;
    }


    public function Get_ChargerSite(bool $updateIPSvars) {
        $responseData = $this->fetchApiData("Charger_Site", sprintf("%s/chargers/%s/site", self::$API_BaseURL, $this->chargerId));
        if(($responseData !== false) &&  $updateIPSvars) {
            $dataArr = json_decode($responseData, true); 
            $this->UpdateIpsVariables($dataArr, "Charger_Infos", 20, "Site", 60);
        }
        return $responseData;
    }


    protected function UpdateIpsVariables($dataArr, $categoryName, $categoryPos, $apiName, $pos) {
        $categoryId = $this->GetCategoryID(str_replace(' ','', $categoryName), $categoryName, $this->parentRootId, $categoryPos);
        $dummyModulId = $this->GetDummyModuleID(str_replace(' ','', $apiName), $apiName, $categoryId, $pos);
        $this->CreateUpdateIpsVariablesFlatten($dummyModulId, "", $dataArr, 5);
    }
  
    private function CreateUpdateIpsVariablesFlatten($parentId, $parentName, $arr, $maxDepth, $depth=0) {
        $depth++;
        $pos = 0;
        $returnArr = array();
        foreach($arr as $key => $value) {

               if(is_array($value)) {
                   if($depth <= $maxDepth) { 
                       $returnArr = array_merge($returnArr, $this->CreateUpdateIpsVariablesFlatten($parentId, $key."_", $value, $maxDepth, $depth));
                    }
            } else {
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("%s[%s] %s - %s {%s}", $depth, $parentName, $key, $value, gettype($value)), 0); }

                $pos++;
                $thisPos = ($depth * 100) + $pos;
                $varIdent = $parentName . $key;
                if($parentName == "") {
                    $varName = $key;
                } else {
                    $varName = sprintf("%s %s",$parentName, $key);
                }
                $this->SaveVariableValue($value, $parentId, $varIdent, $varName, -1, $thisPos, "", false);
                $returnArr[$parentName] = $value;
            }
        }
        return $returnArr;
    }


    private function fetchApiData($apiName, $url) {

        $resultData = false;

        $this->profilingStart(__FUNCTION__);

        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] URL: %s", $apiName, $url ), 0); }

        $res =	$this->client->request('GET', $url,
            [
                'headers' => [
                    'user-agent' => self::$USER_AGENT,
                    'accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->GetAccessToken(),
                    'accept-encoding' => 'gzip, deflate, br'
                ]
            ]
        );

        $statusCode = $res->getStatusCode();
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Status: %s ", $apiName, $statusCode), 0); }

        if($statusCode == 200) {
            $resultData = strval($res->getBody());
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("[%] Response Data: %s",  $apiName, $resultData), 0); }

            //$resultData = json_decode($resultData , true); 
            //if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("[%s] Response Json: %s", $apiName, print_r($resultData, true)), 0); }	
         
            $this->profilingEnd(__FUNCTION__);
        } else {
            $resultData = false;
            $msg = sprintf("ERROR [%s] > Invalid response StatusCode '%s' ! > %s", $apiName, $statusCode, __FUNCTION__, strval($res->getBody()));
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw new \Exception($msg);                
        }

        return $resultData;

    }



    //$success = array_walk_recursive($dataArr, array($this, 'ExtractDataValues'), $apiName);
    public function ExtractDataValues($value, $key, $apiName) {
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