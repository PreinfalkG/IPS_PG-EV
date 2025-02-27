<?php 

trait CUPRA_API {

    static $AUTH_HOST = 'https://identity.vwgroup.io';
    static $TOKEN_HOST = 'https://identity.vwgroup.io/oidc/v1/token';	
    static $TOKEN_HOST_REFRESH = 'https://ola.prod.code.seat.cloud.vwgroup.com/authorization/api/v1/token';
    static $AUTH_USER_AGENT = 'Go-http-client/1.1';
    static $APP_USER_AGENT = 'Go-http-client/1.1';
    static $API_ClientId = "3c756d46-f1ba-4d78-9f9a-cff0d5292d51%40apps_vw-dilab_com";
    static $API_ClientSecret = "eb8814e641c81a2640ad62eeccec11c98effc9bccd4269ab7af338b50a94b3a2";
		
    static $API_REDIRECT_URI = "cupra://oauth-callback";		
    static $API_NONCE = "jTytVezXD5zsXyYQbKp0yCsbHR9yRuvL7d9aUziaEmy";
    static $API_STATE = "66cca5d4-872e-4c9a-8e2f-47a37e9854fb";
    static $TOKEN_BRAND = "cupra";

    private $codeChallenge; 
    private $codeVerifier; 

    private $csrf; 
    private $relayState; 
    private $hmac; 
    private $nextFormAction; 

    private $identityKit_state;
    private $identityKit_code;
    private $identityKit_token_type;
    private $identityKit_id_token;

    private $userId;

    private $oAuth_tokenType;
    private $oAuth_accessToken;
    private $oAuth_accessTokenExpiresIn;
    private $oAuth_accessTokenExpiresAt;
    private $oAuth_idToken;
    private $oAuth_refreshToken;


    private function FetchLogInForm() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $this->state = self::GenerateMockUuid();
            $PKCEPair = $this->GeneratePKCEPair();

            $this->codeChallenge = $PKCEPair['codeChallenge'];
            $this->codeVerifier = $PKCEPair['codeVerifier'];
            
            $url = sprintf("%s/oidc/v1/authorize?client_id=%s&code_challenge=%s&code_challenge_method=%s&redirect_uri=%s&response_type=%s&scope=%s&nonce=%s&state=%s",
                            self::$AUTH_HOST, self::$API_ClientId, $this->codeChallenge, "S256", self::$API_REDIRECT_URI, "code id_token", "openid profile mbb", self::$API_NONCE, self::$API_STATE);

            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("authorize URL: %s", $url )); }	

            $res = $this->client->request('GET', $url, ['cookies' => $this->clientCookieJar]);

            $returnedHtml = strval($res->getBody());
            //$pos1 = strpos($returnedHtml, "<head>");
            //$pos2 = strpos($returnedHtml, "</head>");
            $parts = explode("head>", $returnedHtml);
            $repairedHtml = rtrim($parts[0], "<") . $parts[2];
            
            $xml = new SimpleXMLElement($repairedHtml);

            $csrfQuery = $xml->xpath("//*[@name='_csrf']/@value");
            $relayStateQuery = $xml->xpath("//*[@name='relayState']/@value");
            $hmacQuery = $xml->xpath("//*[@name='hmac']/@value");
            $formActionQuery = $xml->xpath("//*[@name='emailPasswordForm']/@action");

            if (!$csrfQuery || !$relayStateQuery || !$hmacQuery || !$formActionQuery) {
                $msg = 'Could not find the required values (csrf, relayState, hmac, nextFormAction) in HTML of first step of log-in process!';
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }	
                throw new \Exception(msg);
            }
        
            $this->csrf = strval($csrfQuery[0][0][0]);
            $this->relayState = strval($relayStateQuery[0][0][0]);
            $this->hmac = strval($hmacQuery[0][0][0]);
            $this->nextFormAction = strval($formActionQuery[0][0][0]);

            $result = true;
            $this->profilingEnd(__FUNCTION__);
            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("extracted Values :: csrf: %s | relayState: %s | hmac: %s | emailPasswordForm@Action: %s", $this->csrf, $this->relayState, $this->hmac, $this->nextFormAction )); }	

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        }
    }


    private function submitEmailAddressForm($emailAddress) {
        $result = false;      
        try {
            $this->profilingStart(__FUNCTION__);
            $url = self::$AUTH_HOST . $this->nextFormAction;
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("POST Email Form: %s", $url )); }	

            $res =	$this->client->request('POST', $url, 
                [
                    'cookies' => $this->clientCookieJar,
                    'headers' => [
                        'user-agent' => self::$AUTH_USER_AGENT,
                        'content-type' => 'application/x-www-form-urlencoded',
                        'accept-language' => 'en-us',
                        'accept' => '*/*'
                    ],
                    'form_params' => [
                        '_csrf' => $this->csrf,
                        'relayState' => $this->relayState,
                        'hmac' => $this->hmac,
                        'email' => $emailAddress
                    ]
                ]
            );

            $returnedHtml = strval($res->getBody());

            $posStart = strpos($returnedHtml, 'templateModel:');
            if ($posStart === false) {
                $msg = "ERROR :: 'templateModel' not found in ReturnedHtml!";
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);
            } else {
                $posStart = $posStart + 14;
                $posEnd = strpos($returnedHtml, '/identifier"}');
                if ($posEnd === false) {
                    $msg = "ERROR :: '/identifier' not found in ReturnedHtml!";
                    if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                    throw new \Exception($msg);
                } else {
                    $posEnd = $posEnd + 13;
                    $templateModelJsonStr = substr($returnedHtml, $posStart, $posEnd - $posStart);

                    if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("templateModel found on Pos '%s' to '%s'", $posStart, $posEnd)); }	
                    if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("templateModel Json extracted: %s", $templateModelJsonStr)); }	

                    $templateModelJson = json_decode($templateModelJsonStr);

                    $this->hmac = $templateModelJson->hmac;        
                    $postAction =  $templateModelJson->postAction;
                    $identifierUrl =  $templateModelJson->identifierUrl;
            
                    $this->nextFormAction = str_replace($identifierUrl, $postAction, $this->nextFormAction);

                    $result = true;
                    $this->profilingEnd(__FUNCTION__);
                    if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("extracted Values :: hmac: %s | credentialsForm@Action: %s", $this->hmac, $this->nextFormAction )); }	
                }

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


    private function submitPasswordForm($emailAddress, $password) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $url = self::$AUTH_HOST . $this->nextFormAction;
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("POST Email Form: %s", $url )); }	

            $res =	$this->client->request('POST', $url, 
                [
                    'cookies' => $this->clientCookieJar,
                    'headers' => [
                        'user-agent' => self::$AUTH_USER_AGENT,
                        'content-type' => 'application/x-www-form-urlencoded',
                        'accept-language' => 'en-us',
                        'accept' => '*/*'
                        ],
                    'form_params' => [
                        '_csrf' => $this->csrf,
                        'relayState' => $this->relayState,
                        'hmac' => $this->hmac,
                        'email' => $emailAddress,
                        'password' => $password
                        ],
                    'allow_redirects' => false
                ]
            );

            $headerLocation = $res->getHeaderLine('Location');
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_1  URL: %s", $headerLocation )); }	

            $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
            $headerLocation = $res->getHeaderLine('Location');
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_2 URL: %s", $headerLocation )); }	

            $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
            $headerLocation = $res->getHeaderLine('Location');
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_3 URL: %s", $headerLocation )); }	
            $urlParts = parse_url($headerLocation);
            parse_str($urlParts['query'], $queryArr);
            $this->userId =  $queryArr["user_id"];
            SetValue($this->GetIDForIdent("userId"), $this->userId);	
            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("oauth client callback success > extract userId '%s'",$this->userId )); }	
 

            $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
            $headerLocation = $res->getHeaderLine('Location');
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_4 URL: %s", $headerLocation )); }	

            //$headerLocation = str_replace($headerLocation, "#", "?", $headerLocation);
            //$urlParts = parse_url($headerLocation);
            //if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, print_r($urlParts, true)); }	
            //$queryArr = parse_str($urlParts['query']);

            $pos = strpos($headerLocation, 'cupra://oauth-callback#');
            if ($pos === false) {
                $msg = "ERROR :: 'cupra://oauth-callback' not found!";
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);
            } else {
                $pos = strpos($headerLocation, '#');
                $queryParam = substr($headerLocation, $pos +1);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Query start at Pos '%s' > %s ", $pos, $queryParam)); }

                parse_str($queryParam, $queryArr);
                if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, print_r($queryArr, true)); }

                $this->identityKit_state = $queryArr["state"];
                $this->identityKit_code = $queryArr["code"];
                $this->identityKit_token_type = $queryArr["token_type"];
                $this->identityKit_id_token = $queryArr["id_token"];

                $result = true;
                $this->profilingEnd(__FUNCTION__);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted Values :: state: %s | tocken_type: %s | id_token: %s | code: %s ", $this->identityKit_state, $this->identityKit_code, $this->identityKit_token_type, $this->identityKit_id_token)); }
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

    
    private function fetchInitialAccessTokens() {

        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            if (!$this->identityKit_code || !$this->codeVerifier) {
                $msg = "ERROR :: Can not request access tokens without valid 'code' and 'codeVerifier' values!";
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);
            }

			$url = self::$TOKEN_HOST;
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("exchangeAuthCode URL: %s", $url )); }

            $res =	$this->client->request('POST', $url,
                [
                    'headers' => [
                        'user-agent' => self::$AUTH_USER_AGENT,
                        'content-type' => 'application/x-www-form-urlencoded',
                        'accept-language' => 'en-us',
                        'accept' => '*/*',
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'form_params' => [
					
		    			'client_id' => '3c756d46-f1ba-4d78-9f9a-cff0d5292d51@apps_vw-dilab_com',    //self::$API_ClientId,
	    				'client_secret' => self::$API_ClientSecret,
    					'grant_type' => "authorization_code",
                        'brand' => self::$TOKEN_BRAND,
                        'code' => $this->identityKit_code,
                        'code_verifier' => $this->codeVerifier,
                        'id_token' => $this->identityKit_id_token,
                        'redirect_uri' => self::$API_REDIRECT_URI,
                        'state' => $this->identityKit_state,
                        'token_type' => $this->identityKit_token_type
                    ]
                ]
            );


            $responseData = strval($res->getBody());
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("exchangeAuthCode Response Data: %s", $responseData )); }	
            
            $responseJson = json_decode($responseData , true); 

            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Response Json Data: %s", print_r($responseJson, true))); }	

            if (!$responseJson['access_token'] || !$responseJson['id_token'] || !$responseJson['refresh_token']) {
                $msg = "ERROR :: Invalid response from initial token request!";
                if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);
            }

            $this->oAuth_tokenType = $responseJson['token_type'];
            $this->oAuth_accessToken = $responseJson['access_token'];
            $this->oAuth_accessTokenExpiresIn = $responseJson['expires_in'];
            $this->oAuth_accessTokenExpiresAt = time() + $this->oAuth_accessTokenExpiresIn;
            $this->oAuth_idToken = $responseJson['id_token'];
            $this->oAuth_refreshToken = $responseJson['refresh_token'];

            SetValue($this->GetIDForIdent("oAuth_tokenType"), $this->oAuth_tokenType);
            SetValue($this->GetIDForIdent("oAuth_accessToken"), $this->oAuth_accessToken);
            SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"), $this->oAuth_accessTokenExpiresIn);
            SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"), $this->oAuth_accessTokenExpiresAt);
            SetValue($this->GetIDForIdent("oAuth_idToken"), $this->oAuth_idToken);
            SetValue($this->GetIDForIdent("oAuth_refreshToken"), $this->oAuth_refreshToken);

            $result = true;
            $this->profilingEnd(__FUNCTION__);
            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n token_type: %s \n expires_in: %s | expires_at: %s \n id_token: %s \n refresh_token: %s | ",
                $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessToken,  $this->oAuth_idToken,  $this->oAuth_refreshToken)); }	

        } catch (Exception $e) {
            $result = false;
            $msg = sprintf("ERROR: %s",  $e->getMessage());
            $this->profilingFault(__FUNCTION__, $msg);
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
        } finally {
            return $result;
        }          

    }

    
    private function fetchRefreshedAccessTokens() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);

            $url = self::$TOKEN_HOST_REFRESH;
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("refreshTokens URL: %s", $url )); }

            $res =	$this->client->request('POST', $url,
                [
                    'headers' => [
                        'user-agent' => self::$AUTH_USER_AGENT,
                        'content-type' => 'application/x-www-form-urlencoded',
                        'accept-language' => 'en-us',
                        'accept' => '*/*',
                        'accept-encoding' => 'gzip, deflate, br'
                    ],
                    'form_params' => [
                        'client_id' => "3c756d46-f1ba-4d78-9f9a-cff0d5292d51@apps_vw-dilab_com",  //self::$API_ClientId,
                        'refresh_token' => $this->oAuth_refreshToken,
                        'grant_type' => 'refresh_token'                        
                    ]
                ]
            );

            $responseData = strval($res->getBody());
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("refreshTokens Response Data: %s", $responseData )); }	
            $responseJson = json_decode($responseData , true); 
            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Response Json Data: %s", print_r($responseJson, true))); }	

            if (!$responseJson['access_token'] || !$responseJson['id_token'] || !$responseJson['refresh_token']) {
                $msg = "WARN :: Invalid response from Refresh token request!";
                if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, $msg, 0); }
                //throw new \Exception($msg);
                $result = false;
            } else {

                $this->oAuth_tokenType = $responseJson['token_type'];
                $this->oAuth_accessToken = $responseJson['access_token'];
                $this->oAuth_accessTokenExpiresIn = $responseJson['expires_in'];
                $this->oAuth_accessTokenExpiresAt = time() + $this->oAuth_accessTokenExpiresIn;
                $this->oAuth_idToken = $responseJson['id_token'];
                $this->oAuth_refreshToken = $responseJson['refresh_token'];

                SetValue($this->GetIDForIdent("oAuth_tokenType"), $this->oAuth_tokenType);
                SetValue($this->GetIDForIdent("oAuth_accessToken"), $this->oAuth_accessToken);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresIn"), $this->oAuth_accessTokenExpiresIn);
                SetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"), $this->oAuth_accessTokenExpiresAt);
                SetValue($this->GetIDForIdent("oAuth_idToken"), $this->oAuth_idToken);
                SetValue($this->GetIDForIdent("oAuth_refreshToken"), $this->oAuth_refreshToken);

                $result = true;
                $this->profilingEnd(__FUNCTION__);
                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n token_type: %s \n expires_in: %s | expires_at: %s \n id_token: %s \n refresh_token: %s | ",
                    $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessToken,  $this->oAuth_idToken,  $this->oAuth_refreshToken)); }	

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

    public function FetchUserInfo() {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            $accessToken = $this->GetAccessToken();
            $url = "https://identity-userinfo.vwgroup.io/oidc/userinfo";
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url )); }

            $res = $this->client->request('GET', $url, [
                    'headers' => [
                        'authorization' => 'Bearer ' . $accessToken
                    ]
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode )); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("User Info: %s", $responseData)); }
                $result = json_decode($responseData);
                $this->profilingEnd(__FUNCTION__);
            } else {
                $result = false;
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'!", $statusCode, __FUNCTION__);
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

    public function FetchVehiclesAndEnrollmentStatus() {
        $result = false;  
        try {
            $this->profilingStart(__FUNCTION__);
            $accessToken = $this->GetAccessToken();
            $url = sprintf("https://ola.prod.code.seat.cloud.vwgroup.com/v2/users/%s/garage/vehicles", $this->userId);
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url )); }

            $res = $this->client->request('GET', $url, [
                    'headers' => [
                        'authorization' => 'Bearer ' . $accessToken
                    ]
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode )); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Vehicles and Enrollment Status: %s", $responseData)); }
                $result = json_decode($responseData);
                $this->profilingEnd(__FUNCTION__);
            } else {
                $result = false;
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'!", $statusCode, __FUNCTION__);
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

    public function FetchVehicleData(string $apiUrl) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);

            $accessToken = $this->GetAccessToken();

            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $apiUrl )); }

            $res = $this->client->request('GET', $apiUrl, [
                    'headers' => [
                        'authorization' => 'Bearer ' . $accessToken
                    ]
                ]
            );

            $statusCode = $res->getStatusCode();
            if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode )); }

            if($statusCode == 200) {
                $responseData = strval($res->getBody());
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Vehicle Data: %s", $responseData)); }
                $result = json_decode($responseData);

                $logVehicleData = $this->ReadPropertyBoolean("logVehicleData");
                if($logVehicleData) {
                    $this->WriteToLogFile($responseData, "EV/");
                    //$this->WriteToLogFile(json_encode($result), "EV/");
                }


                $this->profilingEnd(__FUNCTION__);
            } else {
                $result = false;
                $msg = sprintf("Invalid response StatusCode [%s] at '%s'!", $statusCode, __FUNCTION__);
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

    public function FetchVehicleData_old(string $vin) {
        $result = false;
        try {
            $this->profilingStart(__FUNCTION__);
            if (empty($vin)) {
                $msg = "WARN :: VIN is 'empty' -> cannot load vehicle data!";
                if($this->logLevel >= LogLevel::WARN) { $this->AddLog(__FUNCTION__, $msg, 0); }
                throw new \Exception($msg);
                $result = false;
            } else {
                $accessToken = $this->GetAccessToken();
                $url = sprintf("https://ola.prod.code.seat.cloud.vwgroup.com/v2/users/%s/vehicles/%s/mycar", $this->userId, $vin);

                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url )); }

                $res = $this->client->request('GET', $url, [
                        'headers' => [
                            'authorization' => 'Bearer ' . $accessToken
                        ]
                    ]
                );

                $statusCode = $res->getStatusCode();
                if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Response Status: %s", $statusCode )); }

                if($statusCode == 200) {
                    $responseData = strval($res->getBody());
                    if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Vehicles Data: %s", $responseData)); }
                    $result = json_decode($responseData);
                    $this->profilingEnd(__FUNCTION__);
                } else {
                    $result = false;
                    $msg = sprintf("Invalid response StatusCode [%s] at '%s'!", $statusCode, __FUNCTION__);
                    if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
                    throw new \Exception($msg);  
                }
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

    public function GetAccessToken(): string  {
        if (time() >= $this->oAuth_accessTokenExpiresAt) {
            if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("INFO: oAuth AcessToken expired at %s > need Refreshed AccessToken", date('d.m.Y H:i:s',$this->oAuth_accessTokenExpiresAt))); }
            if(empty($this->oAuth_refreshToken)) {
                if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "INFO: oAuth refreshToken is 'empty' > new authentication required"); }
                $this->Authenticate("");
            } else {
                $result = $this->fetchRefreshedAccessTokens();
                if(!$result) {
                    if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, "WARN: Problem fetching refrehed Access Tokcne > new authentication required"); }
                    $this->Authenticate("");
                }
            }
        }
        return $this->oAuth_accessToken;
    }


    static public function GenerateMockUuid(): string
    {
        // This method doesn't create unique values or cryptographically secure values. 
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function GeneratePKCEPair(): array
    {
        $bytes = random_bytes(64 / 2);
        $codeVerifier = bin2hex($bytes);

        $hashOfVerifier = hash('sha256', $codeVerifier, true);
        $codeChallenge = strtr(base64_encode($hashOfVerifier), '+/', '-_'); 

        return [
            'codeVerifier' => $codeVerifier, 
            'codeChallenge' => $codeChallenge
        ];
    }


}


?>