<?php 
    require_once "lib/twitteroauth.php";
    require_once "lib/urlshorten.php";

    class Chweet extends Modules {
        static function __install() {
            $config = Config::current();
            $config->set("chweet_format", "Blog post: %title% ~ %shorturl%");
            $config->set("chweet_url_shortener", "isgd");
            $config->set("chweet_oauth_token", null);
            $config->set("chweet_oauth_secret", null);
            $config->set("chweet_user_id", null);
            $config->set("chweet_username", null);
        }

        static function __uninstall($confirm) {
            $config = Config::current();
            $config->remove("chweet_format");
            $config->remove("chweet_url_shortener");
            $config->remove("chweet_oauth_token");
            $config->remove("chweet_oauth_secret");
            $config->remove("chweet_user_id");
            $config->remove("chweet_username");
        }

        public function admin_head() {
            $this->chweetJS();
        }

        static function admin_chweet_settings($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("chweet_settings");

            if (isset($_POST['authorize'])) {
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

            $config = Config::current();
            if (!isset($_POST['hash']) or $_POST['hash'] != $config->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            if (!empty($_POST['chweet_format'])) {
                $config->set("chweet_format", $_POST['chweet_format']);
                if ($config->chweet_url_shortener == "bitly") {
                    $config->set("chweet_url_shortener", $_POST['chweet_url_shortener']);
                    $config->set("chweet_bitly_login", $_POST['chweet_bitly_login']);
                    $config->set("chweet_bitly_apikey", $_POST['chweet_bitly_apikey']);
                } elseif ($config->chweet_url_shortener == "googl")
                    $config->set("chweet_googl_apikey", $_POST['chweet_googl_apikey']);
                else
                    $config->set("chweet_url_shortener", $_POST['chweet_url_shortener']);

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

            if (CHYRP_VERSION < 2.2) {
                $checked = !empty($post->tweeted) ? 'checked="checked"' : "" ;
                # Credit: Pascal N's post2twitter module
                $extra = '<input type="checkbox" name="chweet" id="chweet"';
                if (!$config->chweet_oauth_token and !$config->chweet_oauth_secret)
                    $extra .= 'disabled="disabled" /> &nbsp;<small>Please <a href="' . url("/admin/?action=chweet_settings") . '">authorize</a> with Twitter first!</small>';
                else
                    $extra .=  $checked . ' /> &nbsp;<small>Want to publish this post to Twitter?</small>';

                $fields[] = array("attr" => "chweet",
                                  "label" => __("Tweet it!", "chweet"),
                                  "extra" => $extra); # workaround for Chyrp version prior to 2.2.
            } else {
                $checked = !empty($post->tweeted) ? true : false ;
                if (!$config->chweet_oauth_token and !$config->chweet_oauth_secret)
                    $extra = "<small>Please <a href=" . url("/admin/?action=chweet_settings") . ">authorize</a> with Twitter first!</small>";
                else
                    $extra = __("<small>Want to publish this post to Twitter?</small>", "chweet");
                
                $fields[] = array("attr" => "chweet",
                                  "label" => __("Tweet it!", "chweet"),
                                  "type" => "checkbox",
                                  "checked" => $checked,
                                  "extra" => $extra);
            }
            return $fields;
        }

        public function add_post($post) {
            fallback($chweet, (int) !empty($_POST['chweet']));
            SQL::current()->insert("post_attributes",
                                    array("name" => "tweeted",
                                          "value" => $chweet,
                                          "post_id" => $post->id));

            if ($chweet and $post->status == "public")
                $this->tweet_post($post);
        }

        public function update_post($post) {
            fallback($chweet, (int) !empty($_POST['chweet']));

            $sql = SQL::current();
            if ($sql->count("post_attributes", array("post_id" => $post->id, "name" => "tweeted")))
                $sql->update("post_attributes",
                             array("post_id" => $post->id,
                                   "name" => "tweeted"),
                             array("value" => $chweet));
            else
                $sql->insert("post_attributes",
                             array("post_id" => $post->id,
                                   "name" => "tweeted",
                                   "value" => $chweet));

            if ($chweet and $post->status == "public")
                $this->tweet_post($post);
        }

        public function tweet_post($post) {
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

            # URL shortening
            if (strpos($status, "%shorturl%") !== false) {
                switch($config->chweet_url_shortener) {
                    case "bitly":
                        $shorturl = bitlyShorten($post->url());
                        break;
                    case "isgd":
                        $shorturl = isgdShorten($post->url());
                        break;
                    case "googl":
                        $googl = new GoogleUrlApi($config->chweet_googl_apikey);
                        $shorturl = $googl->shorten($post->url());
                        break;
                    case 503:
                        $errorCode = 4;
                        break;
                }

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

        public function chweetJS() { ?>
            <script type="text/javascript">
            $(function(){
                $("#chweet_url_shortener").change(function(){
                    if ($(this).val() == "bitly") {
                        $("#bitly_fields").animate({ opacity: "show" })
                    } else {
                        $("#bitly_fields").animate({ opacity: "hide" }, { duration: 10 })
                    }
                    if ($(this).val() == "googl") {
                        $("#googl_fields").animate({ opacity: "show" })
                    } else {
                        $("#googl_fields").animate({ opacity: "hide" }, { duration: 10 })
                    }
                })
            })
            </script><?php
        }
    }
