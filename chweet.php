<?php 
    require_once("lib/twitteroauth.php");

    class Chweet extends Modules {
        static function __install() {
            $config = Config::current();
            $config->set("chweet_format", "Blog post: %title% ~ %shorturl%");
            $config->set("chweet_oauth_token", null);
            $config->set("chweet_oauth_secret", null);
            $config->set("chweet_user_id", null);
            $config->set("chweet_username", null);
        }

        static function __uninstall($confirm) {
            $config = Config::current();
            $config->remove("chweet_format");
            $config->remove("chweet_oauth_token");
            $config->remove("chweet_oauth_secret");
            $config->remove("chweet_user_id");
            $config->remove("chweet_username");
        }

        static function admin_chweet_settings($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("chweet_settings");

            if ($_POST['authorize']) {
                $tOAuth = new TwitterOAuth(C_KEY, C_SECRET);
                $callback = url("/admin/?action=chweet_auth");
                $request_token = $tOAuth->getRequestToken($callback);

                $_SESSION['oauth_token'] = $token = $request_token["oauth_token"];
                $_SESSION['oauth_token_secret'] = $request_token["oauth_token_secret"];

                if ($tOAuth->http_code == 200) {
                    # Build authorize URL and redirect user to Twitter.
                    $url = $tOAuth->getAuthorizeURL($token, false);
                    redirect($url);
                } else
                    error(__("Error"), __("Could not connect to Twitter. Refresh the page or try again later.", "chweet"));
            }

            if (!isset($_POST['hash']) or $_POST['hash'] != Config::current()->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            if (!empty($_POST['chweet_format'])) {
                Config::current()->set("chweet_format", $_POST['chweet_format']);
                Flash::notice(__("Settings updated."), "/admin/?action=chweet_settings");
            } else
                Flash::warning(__("Please define a tweet format."), "/admin/?action=chweet_settings");
        }

        static function admin_chweet_auth($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            # If the oauth_token is old redirect
            if (isset($_REQUEST['oauth_token']) && $_SESSION['oauth_token'] !== $_REQUEST['oauth_token'])
                Flash::warning(__("Old token. Please refresh the page and try again."), "/admin/?action=chweet_settings");
            
            # New TwitteroAuth object with app key/secret and token key/secret from SESSION
            $tOAuth = new TwitterOAuth(C_KEY, C_SECRET, $_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
            $access_token = $tOAuth->getAccessToken($_REQUEST['oauth_verifier']);

            $config = Config::current();
            $config->set("chweet_oauth_token", $access_token["oauth_token"]);
            $config->set("chweet_oauth_secret", $access_token["oauth_token_secret"]);
            $config->set("chweet_user_id", $access_token["user_id"]);
            $config->set("chweet_username", $access_token["screen_name"]);

            unset($_SESSION['oauth_token']);
            unset($_SESSION['oauth_token_secret']);

            if (200 == $tOAuth->http_code)
                Flash::notice(__("Chweet was successfully Authorized to Twitter."), "/admin/?action=chweet_settings");
            else
                Flash::warning(__("Chweet couldn't be authorized."), "/admin/?action=chweet_settings");
        }

        static function settings_nav($navs) {
            if (Visitor::current()->group->can("change_settings"))
                $navs["chweet_settings"] = array("title" => __("Chweet", "chweet"));

            return $navs;
        }

        public function post_options($fields, $post = NULL) {
            $config = Config::current();
            if (!isset($config->chweet_oauth_token) and !isset($config->chweet_oauth_secret))
                $extra = "<small>Please <a href=" . url("/admin/?action=chweet_settings") . ">authorize</a> with Twitter first!</small>";
            else
                $extra = __("<small>Want to publish this post to Twitter?</small>", "chweet");
            
            $fields[] = array("attr" => "chweet",
                              "label" => __("Tweet it!", "chweet"),
                              "type" => "checkbox",
                              "extra" => $extra);
            return $fields;
        }

        public function add_post($post) {
            if (empty($_POST['chweet']) or $post->status != "public")
                return;    
            $this->tweet_post($post);
            SQL::current()->insert("post_attributes",
                                    array("name" => "tweeted",
                                          "value" => $_POST['chweet'],
                                          "post_id" => $post->id));
        }

        public function update_post($post) {
            if (empty($_POST['chweet']) or $post->status != "public")
                return;
            $this->tweet_post($post);
            SQL::current()->replace("post_attributes",
                                    array("name" => "tweeted",
                                          "value" => $_POST['chweet'],
                                          "post_id" => $post->id));
        }

        private function tweet_post($post) {
            $config = Config::current();
            if (!$config->chweet_oauth_token and !$config->chweet_oauth_secret)
                return;

            # replace tweet format accordingly
            $status = str_replace(array("%title%",
                                        "%url%",
                                        "%author%",
                                        "%feather%",
                                        "%feather-uc%"),
                                  array(oneof($post->title(), $post->title_from_excerpt()),
                                        $post->url(),
                                        oneof($post->user->full_name, $post->user->login),
                                        $post->feather,
                                        ucfirst($post->feather)),
                                  $config->chweet_format);

            # URL shortening with v.gd
            if (strpos($status, "%shorturl%") !== false) {
                $shorturl = $this->vgdShorten($post->url());
                if (!$shorturl["errorMessage"])
                    $status = str_replace("%shorturl%", $shorturl["shortURL"], $status);
            }

            if (!$status)
                return;

            $tOAuth = new TwitterOAuth(C_KEY, C_SECRET, $config->chweet_oauth_token, $config->chweet_oauth_secret);
            $user = $tOAuth->get("account/verify_credentials");
            $response = $tOAuth->post("statuses/update", array("status" => $status));
            return $response;
        }

        # By Richard West for v.gd
        # http://v.gd/apiexample.php.txt        
        private function vgdShorten($url, $shorturl = null) {                
            $url = urlencode($url);
            $basepath = "http://v.gd/create.php?format=simple";
            # if you want to use is.gd instead, just swap the above line for the commented out one below
            # $basepath = "http://is.gd/create.php?format=simple";
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
        
            $response = @file_get_contents($path,false,$context);
            
            if(!isset($http_response_header)) {
                $result["errorMessage"] = "Local error: Failed to fetch API page";
                return($result);
            }
        
            # Hacky way of getting the HTTP status code from the response headers
            if (!preg_match("{[0-9]{3}}",$http_response_header[0],$httpStatus)) {
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
        
            if($errorCode==-1) {
                $result["errorMessage"] = "Local error: Unexpected response code received from server";
                return($result);
            }
        
            $result["errorCode"] = $errorCode;
            if($errorCode==0)
                $result["shortURL"] = $response;
            else
                $result["errorMessage"] = $response;
        
            return($result);
        }
    }
