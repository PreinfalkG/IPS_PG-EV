<?php 


trait CUPRA_API {

    static $AUTH_HOST = 'https://identity.vwgroup.io';
    static $TOKEN_HOST = 'https://tokenrefreshservice.apps.emea.vwapps.io';
    static $API_HOST = 'https://b-h-s.spr.us00.p.con-veh.net';
    static $AUTH_USER_AGENT = 'Go-http-client/1.1';
    static $APP_USER_AGENT = 'Go-http-client/1.1';
    static $API_ClientId = "50f215ac-4444-4230-9fb1-fe15cd1a9bcc@apps_vw-dilab_com";
    static $API_REDIRECT_URI = "seatconnect%3A%2F%2Fidentity-kit%2Flogin";
    static $API_NONCE = "jTytVezXD5zsXyYQbKp0yCsbHR9yRuvL7d9aUziaEmy";
    static $API_STATE = "66cca5d4-872e-4c9a-8e2f-47a37e9854fb";

    // DoTo !!!
    static $userId = "f4b84055-da5e-4884-b641-824cee00a9a9";

    static $TOKEN_BRAND = "seat";

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


    private $oAuth_tokenType;
    private $oAuth_accessToken;
    private $oAuth_accessTokenExpiresIn;
    private $oAuth_accessTokenExpiresAt;
    private $oAuth_idToken;
    private $oAuth_refreshToken;


    private function FetchLogInForm(): void
    {
        $this->state = self::GenerateMockUuid();

        $PKCEPair = $this->GeneratePKCEPair();

        $this->codeChallenge = $PKCEPair['codeChallenge'];
        $this->codeVerifier = $PKCEPair['codeVerifier'];

        //$url = self::API_HOST . '/oidc/v1/authorize?redirect_uri=car-net%3A%2F%2F%2Foauth-callback&scope=openid&prompt=login&code_challenge='.$this->codeChallenge.'&state=' . $this->state . '&response_type=code&client_id=' . self::APP_CLIENT_ID_IOS;
        $url = sprintf("%s/oidc/v1/authorize?client_id=%s&code_challenge=%s&code_challenge_method=%s&redirect_uri=%s&response_type=%s&scope=%s&nonce=%s&state=%s",
                        self::$AUTH_HOST, self::$API_ClientId, $this->codeChallenge, "S256", self::$API_REDIRECT_URI, "code id_token", "openid profile mbb", self::$API_NONCE, self::$API_STATE);

        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("authorize URL: %s", $url ), 0); }	

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

        if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("extracted Values :: csrf: %s | relayState: %s | hmac: %s | emailPasswordForm@Action: %s", $this->csrf, $this->relayState, $this->hmac, $this->nextFormAction ), 0); }	


    }


    private function submitEmailAddressForm($emailAddress): void
    {
        $url = self::$AUTH_HOST . $this->nextFormAction;
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("POST Email Form: %s", $url ), 0); }	

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

                if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("templateModel found on Pos '%s' to '%s'", $posStart, $posEnd), 0); }	
                if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, sprintf("templateModel Json extracted: %s", $templateModelJsonStr), 0); }	

                $templateModelJson = json_decode($templateModelJsonStr);

                $this->hmac = $templateModelJson->hmac;        
                $postAction =  $templateModelJson->postAction;
                $identifierUrl =  $templateModelJson->identifierUrl;
        
                $this->nextFormAction = str_replace($identifierUrl, $postAction, $this->nextFormAction);

                if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("extracted Values :: hmac: %s | credentialsForm@Action: %s", $this->hmac, $this->nextFormAction ), 0); }	

            }

        }

    }


    private function submitPasswordForm($emailAddress, $password) {
        $url = self::$AUTH_HOST . $this->nextFormAction;
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("POST Email Form: %s", $url ), 0); }	

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
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_1  URL: %s", $headerLocation ), 0); }	


        $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
        $headerLocation = $res->getHeaderLine('Location');
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_2 URL: %s", $headerLocation ), 0); }	

        $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
        $headerLocation = $res->getHeaderLine('Location');
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_3 URL: %s", $headerLocation ), 0); }	

        $res = $this->client->request('GET', $headerLocation, ['cookies' => $this->clientCookieJar, 'allow_redirects' => false]);
        $headerLocation = $res->getHeaderLine('Location');
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Redirect_4 URL: %s", $headerLocation ), 0); }	


        //$headerLocation = str_replace($headerLocation, "#", "?", $headerLocation);
        //$urlParts = parse_url($headerLocation);
        //if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, print_r($urlParts, true), 0); }	
        //$queryArr = parse_str($urlParts['query']);


        $pos = strpos($headerLocation, 'seatconnect://identity-kit/login#');
        if ($pos === false) {
            $msg = "ERROR :: 'seatconnect://identity-kit/login' not found!";
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw new \Exception($msg);
        } else {
            $pos = strpos($headerLocation, '#');
            $queryParam = substr($headerLocation, $pos +1);
            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Query start at Pos '%s' > %s ", $pos, $queryParam), 0); }

            parse_str($queryParam, $queryArr);
            if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, print_r($queryArr, true), 0); }

            $this->identityKit_state = $queryArr["state"];
            $this->identityKit_code = $queryArr["code"];
            $this->identityKit_token_type = $queryArr["token_type"];
            $this->identityKit_id_token = $queryArr["id_token"];

            if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted Values :: state: %s | tocken_type: %s | id_token: %s | code: %s ", $this->identityKit_state, $this->identityKit_code, $this->identityKit_token_type, $this->identityKit_id_token), 0); }

        }

    }

    
    private function fetchInitialAccessTokens() {

        if (!$this->identityKit_code || !$this->codeVerifier) {
            $msg = "ERROR :: Can not request access tokens without valid 'code' and 'codeVerifier' values!";
            if($this->logLevel >= LogLevel::ERROR) { $this->AddLog(__FUNCTION__, $msg, 0); }
            throw new \Exception($msg);
        }


        $url = self::$TOKEN_HOST . '/exchangeAuthCode';
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("exchangeAuthCode URL: %s", $url ), 0); }

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
                    'auth_code' =>  $this->identityKit_code,
                    'brand' => self::$TOKEN_BRAND,
                    'code' => $this->codeChallenge,
                    'code_verifier' => $this->codeVerifier,
                    'id_token' => $this->identityKit_id_token,
                    'state' => $this->identityKit_state,
                    'token_type' => $this->identityKit_token_type
                ]
            ]
        );


        $responseData = strval($res->getBody());
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("exchangeAuthCode Response Data: %s", $responseData ), 0); }	
        
        $responseJson = json_decode($responseData , true); 

        if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Response Json Data: %s", print_r($responseJson, true)), 0); }	

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

        if($this->logLevel >= LogLevel::DEBUG) { $this->AddLog(__FUNCTION__, sprintf("Extracted oAuth Data :: \n token_type: %s \n expires_in: %s | expires_at: %s \n id_token: %s \n refresh_token: %s | ",
            $this->oAuth_tokenType,  $this->oAuth_accessTokenExpiresIn,  $this->oAuth_accessTokenExpiresAt, $this->oAuth_accessToken,  $this->oAuth_idToken,  $this->oAuth_refreshToken), 0); }	

    }

    private function fetchRefreshedAccessTokens() {

    }


    public function FetchUserInfo() {
        $url = "https://identity-userinfo.vwgroup.io/oidc/userinfo";
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url ), 0); }

        $res = $this->client->request('GET', $url, [
                'headers' => [
                    'authorization' => 'Bearer ' . $this->GetAccessToken()
                ]
            ]
        );

        $responseData = strval($res->getBody());
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("User Info: %s", $responseData), 0); }

        $responseJson = json_decode($responseData);
        
        return $responseJson;
    }

    public function FetchVehiclesAndEnrollmentStatus() {

        $url = sprintf("https://ola.prod.code.seat.cloud.vwgroup.com/v1/users/%s/garage/vehicles", self::$userId);
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url ), 0); }

        $res = $this->client->request('GET', $url, [
                'headers' => [
                    'authorization' => 'Bearer ' . $this->GetAccessToken()
                ]
            ]
        );

        $responseData = strval($res->getBody());
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Vehicles and Enrollment Status: %s", $responseData), 0); }

        $responseJson = json_decode($responseData);
        
        return $responseJson;

    }

    public function FetchVehicleData() {

        $vin = $this->ReadPropertyString("tbVIN");	
        $url = sprintf("https://ola.prod.code.seat.cloud.vwgroup.com/v2/users/%s/vehicles/%s/mycar", self::$userId, $vin);

        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("API URL: %s", $url ), 0); }

        $res = $this->client->request('GET', $url, [
                'headers' => [
                    'authorization' => 'Bearer ' . $this->GetAccessToken()
                ]
            ]
        );

        $responseData = strval($res->getBody());
        if($this->logLevel >= LogLevel::COMMUNICATION) { $this->AddLog(__FUNCTION__, sprintf("Vehicles Data: %s", $responseData), 0); }

        $responseJson = json_decode($responseData);
        
        return $responseJson;

    }

    public function GetAccessToken(): string
    {
        $this->oAuth_accessToken = GetValue($this->GetIDForIdent("oAuth_accessToken"));
        if (!$this->oAuth_accessToken)
            throw new \Exception("There is no accessToken set yet.");

        $this->oAuth_accessTokenExpiresAt = GetValue($this->GetIDForIdent("oAuth_accessTokenExpiresAt"));
        if (time() >= $this->oAuth_accessTokenExpiresAt)
            $this->fetchRefreshedAccessTokens();

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