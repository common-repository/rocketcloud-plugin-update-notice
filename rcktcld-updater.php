<?php

/*
Plugin Name: RocketCloud - Plugin Update Notice
Plugin URI: http://www.rocketcloudmedia.com
Description: Get notifications sent to your email for outdated plugins.
Version: 1.0
Author: RocketCloudMedia Inc. - Ryan Hammond
Author URI: http://www.rocketcloudmedia.com
*/

// Only load class if it hasn't already been loaded
if ( !class_exists( 'sc_WPUpdatesNotifier' ) ) {

	class sc_WPUpdatesNotifier {
		const OPT_FIELD         = "sc_wpun_settings";
		const OPT_VERSION_FIELD = "sc_wpun_settings_ver";
		const OPT_VERSION       = "5.0";
		const CRON_NAME         = "sc_wpun_update_check";

		public static function init() {
			// Check settings are up to date
			self::settingsUpToDate();
			// Create Activation and Deactivation Hooks
			register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
			register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
			// Add Filters
			add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list
			add_filter( 'sc_wpun_plugins_need_update', array( __CLASS__, 'check_plugins_against_notified' ) ); // Filter out plugins that need update if already been notified
			// Add Actions
			add_action( 'admin_menu', array( __CLASS__, 'admin_settings_menu' ) ); // Add menu to options
			add_action( 'admin_init', array( __CLASS__, 'admin_settings_init' ) ); // Add admin init functions
			add_action( 'admin_init', array( __CLASS__, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins
			add_action( 'sc_wpun_enable_cron', array( __CLASS__, 'enable_cron' ) ); // action to enable cron
			add_action( 'sc_wpun_disable_cron', array( __CLASS__, 'disable_cron' ) ); // action to disable cron
			add_action( self::CRON_NAME, array( __CLASS__, 'do_update_check' ) ); // action to link cron task to actual task
		}

		/**
		 * Check if this plugin settings are up to date. Firstly check the version in
		 * the DB. If they don't match then load in defaults but don't override values
		 * already set. Also this will remove obsolete settings that are not needed.
		 *
		 * @return void
		 */
		private static function settingsUpToDate() {
			$current_ver = self::getSetOptions( self::OPT_VERSION_FIELD ); // Get current plugin version
			if ( self::OPT_VERSION != $current_ver ) { // is the version the same as this plugin?
				$options  = (array) get_option( self::OPT_FIELD ); // get current settings from DB
				$defaults = array( // Here are our default values for this plugin
					'frequency'        => 'hourly',
					'notify_to'        => get_option( 'admin_email' ),
					'notify_plugins'   => 1,
					'hide_updates'     => 1,
					'notified'         => array(
						'plugin' => array()
					),
					'last_check_time'  => false
				);
				// Intersect current options with defaults. Basically removing settings that are obsolete
				$options = array_intersect_key( $options, $defaults );
				// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
				$options = array_merge( $defaults, $options );
				self::getSetOptions( self::OPT_FIELD, $options ); // update settings
				self::getSetOptions( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version
			}
		}


		/**
		 * Filter for when getting or settings this plugins settings
		 *
		 * @param string     $field    Option field name of where we are getting or setting plugin settings
		 * @param bool|mixed $settings False if getting settings else an array with settings you are saving
		 *
		 * @return bool|mixed True or false if setting or an array of settings if getting
		 */
		private static function getSetOptions( $field, $settings = false ) {
			if ( $settings === false ) {
				return apply_filters( 'sc_wpun_get_options_filter', get_option( $field ), $field );
			}

			return update_option( $field, apply_filters( 'sc_wpun_put_options_filter', $settings, $field ) );
		}


		/**
		 * Function that deals with activation of this plugin
		 *
		 * @return void
		 */
		public static function activate() {
			do_action( "sc_wpun_enable_cron" ); // Enable cron
		}


		/**
		 * Function that deals with de-activation of this plugin
		 *
		 * @return void
		 */
		public static function deactivate() {
			do_action( "sc_wpun_disable_cron" ); // Disable cron
		}


		/**
		 * Enable cron for this plugin. Check if a cron should be scheduled.
		 *		 *
		 * @return void
		 */
		public function enable_cron( ) {
			$options         = self::getSetOptions( self::OPT_FIELD ); // Get settings
			$currentSchedule = wp_get_schedule( self::CRON_NAME ); // find if a schedule already exists

			if ( $currentSchedule == $options['frequency'] ) {
					return;
			}

			// check the cron setting is valid
			if ( !in_array( $options['frequency'], self::get_intervals() ) ) {
				return;
			}

			// Remove any cron's for this plugin first so we don't end up with multiple cron's doing the same thing.
			do_action( "sc_wpun_disable_cron" );

			// Schedule cron for this plugin.
			wp_schedule_event( time(), $options['frequency'], self::CRON_NAME );
		}


		/**
		 * Removes cron for this plugin.
		 *
		 * @return void
		 */
		public function disable_cron() {
			wp_clear_scheduled_hook( self::CRON_NAME ); // clear cron
		}


		/**
		 * Adds the settings link under the plugin on the plugin screen.
		 *
		 * @param array  $links
		 * @param string $file
		 *
		 * @return array $links
		 */
		public static function plugin_action_links( $links, $file ) {
			static $this_plugin;
			if ( !$this_plugin ) {
				$this_plugin = plugin_basename( __FILE__ );
			}
			if ( $file == $this_plugin ) {
				$settings_link = '<a href="' . admin_url( 'options-general.php?page=rcktcld-plugin-update' ) . '">' . __( "Settings", "rcktcld-plugin-update" ) . '</a>';
				array_unshift( $links, $settings_link );
			}
			return $links;
		}


		/**
		 * This is run by the cron. The update check checks the core always, the
		 * plugins and themes if asked. If updates found email notification sent.
		 *
		 * @return void
		 */
		public function do_update_check() {
			$options      = self::getSetOptions( self::OPT_FIELD ); // get settings
			$message      = ""; // start with a blank message
			if ( 0 != $options['notify_plugins'] ) { // are we to check for plugin updates?
				$plugins_updated = self::plugins_update_check( $message, $options['notify_plugins'] ); // check for plugin updates
			}
			else {
				$plugins_updated = false; // no plugin updates
			}
			if ( $plugins_updated ) { // Did anything come back as need updating?
				$exact_site = get_site_url();
				$message = __( "There are updates available for $exact_site", "rcktcld-plugin-update" ) . "\n" . $message . "\n";
				$message .= sprintf( __( "Update these plugins: %s", "rcktcld-plugin-update" ), admin_url( 'update-core.php' ) );
				self::send_notification_email( $message ); // send our notification email.
			}

			self::log_last_check_time();
		}


		/**
		 * Check to see if any plugin updates.
		 *
		 * @param string $message     holds message to be sent via notification
		 * @param int    $allOrActive should we look for all plugins or just active ones
		 *
		 * @return bool
		 */
		private static function plugins_update_check( &$message, $allOrActive ) {
			global $wp_version;
			$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );
			$settings       = self::getSetOptions( self::OPT_FIELD ); // get settings
			do_action( "wp_update_plugins" ); // force WP to check plugins for updates
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates
			if ( !empty( $update_plugins->response ) ) { // any plugin updates available?
				$plugins_need_update = $update_plugins->response; // plugins that need updating
				if ( 2 == $allOrActive ) { // are we to check just active plugins?
					$active_plugins      = array_flip( get_option( 'active_plugins' ) ); // find which plugins are active
					$plugins_need_update = array_intersect_key( $plugins_need_update, $active_plugins ); // only keep plugins that are active
				}
				$plugins_need_update = apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ); // additional filtering of plugins need update
				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); // Required for plugin API
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating
						$plugin_info = get_plugin_data( WP_PLUGIN_DIR . "/" . $key ); // get local plugin info
						$info        = plugins_api( 'plugin_information', array( 'slug' => $data->slug ) ); // get repository plugin info
						$message .= "\n" . sprintf( __( "Plugin: %s is out of date. Please update from version %s to %s", "rcktcld-plugin-update" ), $plugin_info['Name'], $plugin_info['Version'], $data->new_version ) . "\n";
						$message .= "\t" . sprintf( __( "Details: %s", "rcktcld-plugin-update" ), $data->url ) . "\n";
						$message .= "\t" . sprintf( __( "Changelog: %s%s", "rcktcld-plugin-update" ), $data->url, "changelog/" ) . "\n";
						if ( isset( $info->tested ) && version_compare( $info->tested, $wp_version, '>=' ) ) {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: 100%% (according to its author)' ), $cur_wp_version );
						}
						elseif ( isset( $info->compatibility[$wp_version][$data->new_version] ) ) {
							$compat = $info->compatibility[$wp_version][$data->new_version];
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: %2$d%% (%3$d "works" votes out of %4$d total)' ), $wp_version, $compat[0], $compat[2], $compat[1] );
						}
						else {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: Unknown' ), $wp_version );
						}
						$message .= "\t" . sprintf( __( "Compatibility: %s", "rcktcld-plugin-update" ), $compat ) . "\n";
						$settings['notified']['plugin'][$key] = $data->new_version; // set plugin version we are notifying about
					}
					self::getSetOptions( self::OPT_FIELD, $settings ); // save settings
					return true; // we have plugin updates return true
				}
			}
			else {
				if ( 0 != count( $settings['notified']['plugin'] ) ) { // is there any plugin notifications?
					$settings['notified']['plugin'] = array(); // set plugin notifications to empty as all plugins up-to-date
					self::getSetOptions( self::OPT_FIELD, $settings ); // save settings
				}
			}
			return false; // No plugin updates so return false
		}


		/**
		 * Filter for removing plugins from update list if already been notified about
		 *
		 * @param array $plugins_need_update
		 *
		 * @return array $plugins_need_update
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			$settings = self::getSetOptions( self::OPT_FIELD ); // get settings
			foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update
				if ( isset( $settings['notified']['plugin'][$key] ) ) { // has this plugin been notified before?
					if ( $data->new_version == $settings['notified']['plugin'][$key] ) { // does this plugin version match that of the one that's been notified?
						unset( $plugins_need_update[$key] ); // don't notify this plugin as has already been notified
					}
				}
			}
			return $plugins_need_update;
		}


		/**
		 * Sends email notification.
		 *
		 * @param string $message holds message to be sent in body of email
		 *
		 * @return void
		 */
		public function send_notification_email( $message ) {
			
			$settings = self::getSetOptions( self::OPT_FIELD ); // get settings
			$subject  = sprintf( __( "Plugin Updates For %s", "rcktcld-plugin-update" ), get_bloginfo( 'name' ) );
			add_filter( 'wp_mail_from', array( __CLASS__, 'sc_wpun_wp_mail_from' ) ); // add from filter
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter
			add_filter( 'wp_mail_content_type', array( __CLASS__, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter
			wp_mail( $settings['notify_to'], apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', $message ) ); // send email
			remove_filter( 'wp_mail_from', array( __CLASS__, 'sc_wpun_wp_mail_from' ) ); // remove from filter
			remove_filter( 'wp_mail_from_name', array( __CLASS__, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter
		}

		public static function sc_wpun_wp_mail_from_name() {
			$email_from = 'plugins@' . get_site_url();
			$to_remove = array( 'http://', 'https://', 'www.' );
			foreach ( $to_remove as $item ) {
				$email_from = str_replace($item, '', $email_from);
			}
			return __( "$email_from", "rcktcld-plugin-update" );
		}

		public static function sc_wpun_wp_mail_content_type() {
			return "text/plain";
		}


		private function log_last_check_time() {
			$options                    = self::getSetOptions( self::OPT_FIELD );
			$options['last_check_time'] = current_time( "timestamp" );
			self::getSetOptions( self::OPT_FIELD, $options );
		}


		/**
		 * Removes the update nag for non admin users.
		 *
		 * @return void
		 */
		public static function remove_update_nag_for_nonadmins() {
			$settings = self::getSetOptions( self::OPT_FIELD ); // get settings
			if ( 1 == $settings['hide_updates'] ) { // is this enabled?
				if ( !current_user_can( 'update_plugins' ) ) { // can the current user update plugins?
					remove_action( 'admin_notices', 'update_nag', 3 ); // no they cannot so remove the nag for them.
				}
			}
		}


		/**
		 * Adds JS to admin settings screen for this plugin
		 */

		private static function get_schedules() {
			$schedules = wp_get_schedules();
			uasort( $schedules, array( __CLASS__, 'sort_by_interval' ) );
			return $schedules;
		}

		private static function get_intervals() {
			$intervals   = array_keys( self::get_schedules() );
			return $intervals;
		}

		private static function sort_by_interval( $a, $b ) {
			return $a['interval'] - $b['interval'];
		}


		/*
		 * EVERYTHING SETTINGS
		 *
		 * I'm not going to comment any of this as its all pretty
		 * much straight forward use of the WordPress Settings API.
		 */
		 
		public static function admin_settings_menu() {
			$page = add_options_page( 'RocketCloud - Plugin Update Notice', 'RocketCloud - Plugin Update Notice', 'manage_options', 'rcktcld-plugin-update', array( __CLASS__, 'settings_page' ) );
			add_action( "admin_print_scripts-{$page}", array( __CLASS__, 'enqueue_plugin_script' ) );
		}

		public static function enqueue_plugin_script() {
			wp_enqueue_script( 'wp_updates_monitor_js_function' );
		}

		public static function settings_page() {
			$options     = self::getSetOptions( self::OPT_FIELD );
			$date_format = get_option( 'date_format' );
			$time_format = get_option( 'time_format' );
			?>
			<div class="wrap">
				<?php screen_icon(); ?>
				<h2><?php _e( "RocketCloud - Plugin Update Notice", "rcktcld-plugin-update" ); ?></h2>

				<p>
                    <span class="description">
                    <?php
					if ( false === $options["last_check_time"] ) {
						$scan_date = __( "Never", "rcktcld-plugin-update" );
					}
					else {
						$scan_date = sprintf(
							__( "%1s @ %2s", "rcktcld-plugin-update" ),
							date( $date_format, $options["last_check_time"] ),
							date( $time_format, $options['last_check_time'] )
						);
					}

					echo sprintf( __( "RocketCloud scanned your site on %s", "rcktcld-plugin-update" ), $scan_date );
					?>
                    </span>
				</p>

				<form action="<?php echo admin_url( "options.php" ); ?>" method="post">
					<?php
					settings_fields( "sc_wpun_settings" );
					do_settings_sections( "rcktcld-plugin-update" );
					?>
					<p>&nbsp;</p>
					<input class="button-primary" name="Submit" type="submit" value="<?php _e( "Save settings", "rcktcld-plugin-update" ); ?>" />
					<input class="button" name="submitwithemail" type="submit" value="<?php _e( "Save settings with test email", "rcktcld-plugin-update" ); ?>" />
				</form>
			</div>
		<?php
		}

		public static function admin_settings_init() {
			register_setting( self::OPT_FIELD, self::OPT_FIELD, array( __CLASS__, "sc_wpun_settings_validate" ) ); // Register Main Settings
			add_settings_section( "sc_wpun_settings_main", __( "Settings", "rcktcld-plugin-update" ), array( __CLASS__, "sc_wpun_settings_main_text" ), "rcktcld-plugin-update" ); // Make settings main section
			add_settings_field( "sc_wpun_settings_main_frequency", __( "Check for new plugins:", "rcktcld-plugin-update" ), array( __CLASS__, "sc_wpun_settings_main_field_frequency" ), "rcktcld-plugin-update", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_notify_to", __( "Send notification to:", "rcktcld-plugin-update" ), array( __CLASS__, "sc_wpun_settings_main_field_notify_to" ), "rcktcld-plugin-update", "sc_wpun_settings_main" );
		}

		public function sc_wpun_settings_validate( $input ) {
			$valid = self::getSetOptions( self::OPT_FIELD );

			if ( in_array( $input['frequency'], self::get_intervals() ) ) {
				$valid['frequency'] = $input['frequency'];
				do_action( "sc_wpun_enable_cron", $input['frequency'] );
			}
			else {
				add_settings_error( "sc_wpun_settings_main_frequency", "sc_wpun_settings_main_frequency_error", __( "Invalid frequency entered", "rcktcld-plugin-update" ), "error" );
			}

			$emails_to = explode( ",", $input['notify_to'] );
			if ( $emails_to ) {
				$sanitized_emails = array();
				$was_error        = false;
				foreach ( $emails_to as $email_to ) {
					$address = sanitize_email( trim( $email_to ) );
					if ( !is_email( $address ) ) {
						add_settings_error( "sc_wpun_settings_main_notify_to", "sc_wpun_settings_main_notify_to_error", __( "One or more email to addresses are invalid", "rcktcld-plugin-update" ), "error" );
						$was_error = true;
						break;
					}
					$sanitized_emails[] = $address;
				}
				if ( !$was_error ) {
					$valid['notify_to'] = implode( ',', $sanitized_emails );
				}
			}
			else {
				add_settings_error( "sc_wpun_settings_main_notify_to", "sc_wpun_settings_main_notify_to_error", __( "No email to address entered", "rcktcld-plugin-update" ), "error" );
			}

			$sanitized_hide_updates = absint( isset( $input['hide_updates'] ) ? $input['hide_updates'] : 0 );
			if ( $sanitized_hide_updates <= 1 ) {
				$valid['hide_updates'] = $sanitized_hide_updates;
			}

			if ( isset( $_POST['submitwithemail'] ) ) {
				add_filter( 'pre_set_transient_settings_errors', array( __CLASS__, "send_test_email" ) );
			}
			
			return $valid;
		}

		public static function send_test_email( $settings_errors ) {
			if ( isset( $settings_errors[0]['type'] ) && $settings_errors[0]['type'] == "updated" ) {
				self::send_notification_email( __( "Everything seems to be working just fine :)", "rcktcld-plugin-update" ) );
			}
		}

		public static function sc_wpun_settings_main_text() {
		}

		public static function sc_wpun_settings_main_field_frequency() {
			$options = self::getSetOptions( self::OPT_FIELD );
			?>
			<select id="sc_wpun_settings_main_frequency" name="<?php echo self::OPT_FIELD; ?>[frequency]">
			<?php foreach ( self::get_schedules() as $k => $v ): ?>
				<option value="<?php echo $k; ?>" <?php selected( $options['frequency'], $k ); ?>><?php echo $v['display']; ?></option>
			<?php endforeach; ?>
			</select>
		<?php
		}

		public static function sc_wpun_settings_main_field_notify_to() {
			$options = self::getSetOptions( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_main_notify_to" class="regular-text" name="<?php echo self::OPT_FIELD; ?>[notify_to]" value="<?php echo $options['notify_to']; ?>" />
			<span class="description"><?php _e( "Separate multiple email address with a comma (,)", "rcktcld-plugin-update" ); ?></span><?php
		}

		public static function sc_wpun_settings_main_field_notify_plugins() {
			$options = self::getSetOptions( self::OPT_FIELD );
			?>
		<?php
		}
	}
}

sc_WPUpdatesNotifier::init();
?>