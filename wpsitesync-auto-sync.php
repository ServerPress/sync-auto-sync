<?php
/*
Plugin Name: WPSiteSync for Auto Sync
Plugin URI: https://wpsitesync.com
Description: Works with <a href="/wp-admin/plugin-install.php?tab=search&s=wpsitesync">WPSiteSync for Content</a> to automatically synchronizes Content to Target site whenever it's edited.
Author: WPSiteSync
Author URI: https://wpsitesync.com
Version: 1.0
Text Domain: wpsitesync-auto-sync
Domain path: /language

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

// this is only needed for systems that the .htaccess won't work on
defined('ABSPATH') or (header('Forbidden', TRUE, 403) || die('Restricted'));

if (!class_exists('WPSiteSync_Auto_Sync', FALSE)) {
	class WPSiteSync_Auto_Sync
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for Auto Sync';
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = '3d84bae3c42eb72b5e1597af3c2e7ce9';

		const META_KEY = 'spectrom_sync_auto_sync_msg_';

		private $_license = NULL;
		private $_pushed = FALSE;

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/**
		 * Creates a single instance of the plugin
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Initializes the plugin, adding the required action hooks.
		 */
		public function init()
		{
//SyncDebug::log(__METHOD__.'()');
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

			$this->_license = new SyncLicensing();
#			if (!$this->_license->check_license('sync_auto_sync', self::PLUGIN_KEY, self::PLUGIN_NAME))
#				return;

			if (1 === SyncOptions::get_int('auth', 0)) {
				add_action('transition_post_status', array($this, 'transition_post'), 10, 3);
				add_action('save_post', array($this, 'save_post'), 10, 1);
			}
			add_action('admin_notices', array($this, 'admin_notice'));
		}

		/**
		 * Called when WP is loaded so we can check if parent plugin is active.
		 */
		public function wp_loaded()
		{
			if (is_admin() && !class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_requires_wpss'));
			}
		}

		/**
		 * Displays the warning message stating the WPSiteSync is not present.
		 */
		public function notice_requires_wpss()
		{
			$install = admin_url('plugin-install.php?tab=search&s=wpsitesync');
			$activate = admin_url('plugins.php');
			echo '<div class="notice notice-warning">';
			echo	'<p>', sprintf(__('The <em>WPSiteSync for Auto Publish</em> plugin requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please %1$sclick here</a> or %2$sclick here</a> to activate.', 'wpsitesync-auto-sync'),
						'<a href="' . $install . '">',
						'<a href="' . $activate . '">'), '</p>';
			echo '</div>';
		}

		/**
		 * Called when a post's status is changed. We use this action in favor of 'publish_post' because it's used for all post types.
		 * @param string $new_status The new post_status value for the content.
		 * @param string $old_status The old post_status value for the content.
		 * @param WP_Post $post The post that is transitioning
		 */
		public function transition_post($new_status, $old_status, $post)
		{
			if ('publish' === $new_status) {
				$this->synchronize_content($post);
			} // 'publish' === $new_status
		}

		/**
		 * Callback for when post Content is saved.
		 * @param int $post_id The post ID being saved
		 */
		public function save_post($post_id)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' saving post ' . $post_id);
			// check for post revisions; don't need to send those
			if (wp_is_post_revision($post_id))
				return;
			$post = get_post($post_id);
			$this->synchronize_content($post);
		}

		/**
		 * Helper method use to push content to Target site
		 * @param object $post The post information
		 */
		private function synchronize_content($post)
		{
			if ($this->_pushed)
				return;
			$this->_pushed = TRUE;

			$controller = SyncApiController::get_instance();
			if (NULL !== $controller && !empty($controller->source_site_key)) {
				// Controller has been instantiated.
				// This means that post is being updated via WPSS API call and we don't need to do anything
				return;
			}

			$post_id = abs($post->ID);
			$post_type = $post->post_type;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id: ' . $post_id . ' post type: ' . $post_type);
			if (0 !== $post_id && in_array($post_type, apply_filters('spectrom_sync_allowed_post_types', array('post', 'page')))) {
				$sync_model = new SyncModel();
				$sync_data = $sync_model->get_sync_data($post_id, NULL, $post->post_type);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sync data: ' . var_export($sync_data, TRUE));
				if (NULL === $sync_data) {
					// post has not yet been pushed - push it
					$api = new SyncApiRequest();
					$api_response = $api->api('push', array('post_id' => $post_id));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' api response: ' . var_export($api_response, TRUE));
					$user_id = get_current_user_id();
					$key = self::META_KEY . $user_id;
					$code = $api_response->get_error_code();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error code: ' . $code);
					add_user_meta($user_id, $key, $code, TRUE);
				}
			} // post_id && post_type
		}

		/**
		 * Callback for the 'admin_notices' action. Used to dispaly error messages on post editor page.
		 */
		public function admin_notice()
		{
			$user_id = get_current_user_id();
			$key = self::META_KEY . $user_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $key);
			$data = get_user_meta($user_id, $key, TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data: ' . var_export($data, TRUE));
			delete_user_meta($user_id, $key);
			if ('' !== $data) {
				$code = abs($data);
				if (0 === $code) {
					$class = 'notice-success';
					$msg = __('<em>WPSiteSync for Auto Sync</em> has automatically Pushed this Content to your Target site.', 'wpsitesync-auto-sync');
				} else {
					$class = 'notice-error';
					$err_msg = SyncApiRequest::error_code_to_string($code);
					$msg = sprintf(__('<em>WPSiteSync</em> encountered an error while Pushing this post to Target: %1$s', 'wpsitesync-auto-sync'),
						$err_msg);
				}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' msg: ' . $msg);
				echo '<div class="notice ', $class, ' is-dismissible">';
				echo '<p>', $msg, '</p>';
				echo '</div>';
			}
		}

		/**
		 * Add the CPT add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list to add to
		 * @param boolean $set
		 * @return array The list of extensions, with the WPSiteSync CPT add-on included
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
//SyncDebug::log(__METHOD__.'()');
			if ($set || $this->_license->check_license('sync_auto_sync', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_auto_sync'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
} // class exists

WPSiteSync_Auto_Sync::get_instance();

// EOF
