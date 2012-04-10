<?php

        # By Richard West for v.gd
        # http://v.gd/apiexample.php.txt        
        function isgd_shorten($url, $shorturl = null) {                
            $url = urlencode($url);
            $basepath = "http://is.gd/create.php?format=simple";
            # if you want to use is.gd instead, just swap the above line for the commented out one below
            # $basepath = "http://v.gd/create.php?format=simple";
            $result = array();
            $result["errorCode"] = -1;
            $result["shortURL"] = null;
            $result["errorMessage"] = null;
        
            $opts = array("http" => array("ignore_errors" => true));
            $context = stream_context_create($opts);
        
            if($shorturl)
                $path = $basepath."&shorturl=$shorturl&url=$url";
            else
                $path = $basepath."&url=$url";
        
            $response = @file_get_contents($path, false, $context);
            
            if (!isset($http_response_header)) {
                $result["errorMessage"] = "Local error: Failed to fetch API page";
                return($result);
            }
        
            # Hacky way of getting the HTTP status code from the response headers
            if (!preg_match("{[0-9]{3}}", $http_response_header[0], $httpStatus)) {
                $result["errorMessage"] = "Local error: Failed to extract HTTP status from result request";
                return($result);
            }
        
            $errorCode = -1;
            switch($httpStatus[0]) {
                case 200:
                    $errorCode = 0;
                    break;
                case 400:
                    $errorCode = 1;
                    break;
                case 406:
                    $errorCode = 2;
                    break;
                case 502:
                    $errorCode = 3;
                    break;
                case 503:
                    $errorCode = 4;
                    break;
            }
        
            if($errorCode == -1) {
                $result["errorMessage"] = "Local error: Unexpected response code received from server";
                return($result);
            }
        
            $result["errorCode"] = $errorCode;
            if($errorCode == 0)
                $result = $response;
            else
                $result["errorMessage"] = $response;
        
            return($result);
        }

        # By Greg Winiarski
        # http://ditio.net/2010/08/15/using-bit-ly-php-api/
        # Updated for BitLy API v3 by me
        function bitly_shorten($url) {
            $config = Config::current();
            $login = $config->chweet_bitly_login;
            $apikey = $config->chweet_bitly_apikey;
            $format = "json";
            $query = array("login"   => $login,
                           "apiKey"  => $apikey,
                           "longUrl" => urlencode($url));

            $query = http_build_query($query);
            $final_url = "http://api.bitly.com/v3/shorten?".$query;

            if (function_exists("file_get_contents"))
                $response = file_get_contents($final_url);
            else {
                $ch = curl_init();
                $timeout = 5;
                curl_setopt($ch, CURLOPT_URL, $final_url);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                $response = curl_exec($ch);
                curl_close($ch);
            }

            $response = json_decode($response);

            if($response->status_code == 0 && $response->status_txt == "OK")
                return $response->data->url;
            else
                return null;
        }

        # By David Walsh
        # http://davidwalsh.name/google-url
        class GoogleUrlApi {
        	// Constructor
        	function GoogleURLAPI($key,$apiURL = 'https://www.googleapis.com/urlshortener/v1/url') {
        		// Keep the API Url
        		$this->apiURL = $apiURL.'?key='.$key;
        	}

        	// Shorten a URL
        	function shorten($url) {
        		// Send information along
        		$response = $this->send($url);
        		// Return the result
        		return isset($response['id']) ? $response['id'] : false;
        	}

        	// Send information to Google
        	function send($url,$shorten = true) {
        		// Create cURL
        		$ch = curl_init();
        		// If we're shortening a URL...
        		if($shorten) {
        			curl_setopt($ch,CURLOPT_URL,$this->apiURL);
        			curl_setopt($ch,CURLOPT_POST,1);
        			curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(array("longUrl"=>$url)));
        			curl_setopt($ch,CURLOPT_HTTPHEADER,array("Content-Type: application/json"));
        		}
        		else {
        			curl_setopt($ch,CURLOPT_URL,$this->apiURL.'&amp;shortUrl='.$url);
        		}
        		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        		// Execute the post
        		$result = curl_exec($ch);
        		// Close the connection
        		curl_close($ch);
        		// Return the result
        		return json_decode($result,true);
        	}		
        }
