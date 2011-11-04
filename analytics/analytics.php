<?php
    class Analytics extends Modules {
        static function __install() {
            Group::add_permission("ga_exclude_group", "Exclude from Google Analytics");
            $config = Config::current();
            $config->set("ga_tracking_number", null);
            $config->set("ga_script_position", "head");
        }

        static function __uninstall($confirm) {
			if ($confirm)
                Group::remove_permission("ga_exclude_group");

		    $config = Config::current();
            $config->remove("ga_tracking_number");
            $config->remove("ga_script_position");
        }

        static function admin_analytics_settings($admin) {
            if (!Visitor::current()->group->can("change_settings"))
                show_403(__("Access Denied"), __("You do not have sufficient privileges to change settings."));

            if (empty($_POST))
                return $admin->display("analytics_settings");

            $config = Config::current();
            if (!isset($_POST['hash']) or $_POST['hash'] != $config->secure_hashkey)
                show_403(__("Access Denied"), __("Invalid security key."));

            if (!empty($_POST['ga_tracking_number'])) {
                $config->set("ga_tracking_number", $_POST['ga_tracking_number']);
                $config->set("ga_script_position", $_POST['ga_script_position']);

                Flash::notice(__("Settings updated."), "/admin/?action=analytics_settings");
            } else
                Flash::warning(__("Please enter your Analytics Tracking number."), "/admin/?action=analytics_settings");
        }

        static function settings_nav($navs) {
            if (Visitor::current()->group->can("change_settings"))
                $navs["analytics_settings"] = array("title" => __("Analytics", "analytics"));

            return $navs;
        }

		public function head() {
            $config = Config::current();
            $visitor = Visitor::current();
			if (!$config->ga_tracking_number or $config->ga_script_position != "head" or $visitor->group()->can("ga_exclude_group"))
                return;

            self::ga_code($config->ga_tracking_number);
        }

		public function end_content() {
            $config = Config::current();
			$visitor = Visitor::current();
            if (!$config->ga_tracking_number or $config->ga_script_position != "body" or $visitor->group()->can("ga_exclude_group"))
                return;

            self::ga_code($config->ga_tracking_number);
        }

        public function ga_code($uanum) {
?>
            <script type="text/javascript">

              var _gaq = _gaq || [];
              _gaq.push(['_setAccount', '<?php echo $uanum; ?>']);
              _gaq.push(['_trackPageview']);
            
              (function() {
                var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
                ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
                var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
              })();

            </script>
<?php
        }
    }
