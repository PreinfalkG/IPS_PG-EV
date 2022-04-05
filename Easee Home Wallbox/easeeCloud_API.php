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


}


?>