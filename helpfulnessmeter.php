<?php
/*
Plugin Name: HelpfulnessMeter
Description: Improve your WordPress content by easily collecting feedback from your visitors.
Version: 1.3
Author: Ludovic S. Clain
Author URI: https://ludovicclain.com
Text Domain: hfnm
Domain Path: /languages
*/
defined('ABSPATH') || exit;
class HelpfulnessMeter
{
	public function __construct()
	{
		// Activation hook
		register_activation_hook(__FILE__, array(get_called_class(), 'hfnm_activate'));
		// Uninstall hook
		register_uninstall_hook(__FILE__, array(get_called_class(), 'hfnm_uninstall'));
		// Add actions and filters
		add_action("admin_notices", array($this, "hfnm_activation_notice"));
		add_filter('plugin_action_links', array($this, 'hfnm_custom_action_links'), 10, 5);
		add_action('plugins_loaded', array($this, 'hfnm_load_textdomain'));
		add_filter("the_content", array($this, "hfnm_after_post_content"), 10000);
		add_action('wp_enqueue_scripts', array($this, 'hfnm_style_scripts'));
		add_action("wp_ajax_hfnm_ajax", array($this, "hfnm_ajax_callback"));
		add_action("wp_ajax_nopriv_hfnm_ajax", array($this, "hfnm_ajax_callback"));
		add_action("init", array($this, "hfnm_post_type_support"));
		add_action('admin_menu', array($this, 'hfnm_register_options_page'));
		// Shortcodes
		add_shortcode('helpfulness_meter', array($this, 'hfnm_shortcode'));
		add_shortcode('hfnm_shortcode_list', array($this, 'hfnm_shortcode_list'));
	}

	// Load translation files
	public function hfnm_load_textdomain()
	{
		load_plugin_textdomain('hfnm', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	// Activating the plugin
	public static function hfnm_activate()
	{
		// Add default options
		add_option('hfnm_types', '[]');
		add_option('hfnm_question_text', __('Was this helpful?', 'hfnm'));
		add_option('hfnm_yes_text', __('Yes', 'hfnm'));
		add_option('hfnm_no_text', __('No', 'hfnm'));
		add_option('hfnm_thank_yes_text', __('Thanks for your positive feedback!', 'hfnm'));
		add_option('hfnm_thank_no_text', __('Thanks for your negative feedback!', 'hfnm'));
		// Add activation option
		add_option('hfnm_activated', '1');
	}

	// Activation notice
	public function hfnm_activation_notice()
	{
		if (get_option('hfnm_activated')) {
			delete_option('hfnm_activated');
			$options_page_url = admin_url('options-general.php?page=hfnm');
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php echo __("Please", "hfnm"); ?> <a href="<?php echo $options_page_url; ?>"><?php echo __("fill in the options", "hfnm"); ?></a>
					<?php echo __("in order to use HelpfulnessMeter.", "hfnm"); ?>
				</p>
			</div>
			<?php
		}
	}

	// Custom action links
	public function hfnm_custom_action_links($actions, $plugin_file)
	{
		static $plugin;
		if (!isset($plugin)) {
			$plugin = plugin_basename(__FILE__);
		}
		if ($plugin == $plugin_file) {
			$settings = array('settings' => '<a href="options-general.php?page=hfnm">' . __('Settings', 'hfnm') . '</a>');
			$actions = array_merge($settings, $actions);
		}
		return $actions;
	}

	// Uninstalling the plugin
	public static function hfnm_uninstall()
	{
		// delete options
		delete_option('hfnm_types');
		delete_option('hfnm_question_text');
		delete_option('hfnm_yes_text');
		delete_option('hfnm_no_text');
		delete_option('hfnm_thank_yes_text');
        delete_option('hfnm_thank_no_text');
		delete_option('hfnm_activated');
		// Delete custom fields
		global $wpdb;
		$table = $wpdb->prefix . 'postmeta';
		$wpdb->delete($table, array('meta_key' => '_hfnm_no'));
		$wpdb->delete($table, array('meta_key' => '_hfnm_yes'));
	}

	// Add the HelpfulnessMeter widget after the content
	public function hfnm_after_post_content($content)
	{
		// Read selected post types
		$selected_post_types = json_decode(get_option("hfnm_types"));
		// show on only selected post types
		if (is_singular() && (in_array(get_post_type(), $selected_post_types))) {
			// Get post id
			$post_id = get_the_ID();
			// Dont show if already voted
			if (!isset($_COOKIE["helpfulnessmeter_id_" . $post_id])) {
                $content .= sprintf(
                    '<div id="helpfulnessmeter" data-post-id="%s">
                        <div id="hfnm-title">%s</div>
                        <div id="hfnm-yes-no">
                            <span data-value="1">%s</span>
                            <span data-value="0">%s</span>
                        </div>
                        <div id="hfnm-thank-yes" style="display:none;">%s</div>
                        <div id="hfnm-thank-no" style="display:none;">%s</div>
                    </div>',
                    $post_id,
                    get_option("hfnm_question_text"),
                    get_option("hfnm_yes_text"),
                    get_option("hfnm_no_text"),
                    get_option("hfnm_thank_yes_text"),
                    get_option("hfnm_thank_no_text")
                );
            }
		}
		return $content;
	}

	// Add script and styles
	public function hfnm_style_scripts()
	{
		// Read selected post types
		$selected_post_types = json_decode(get_option("hfnm_types"));
		// show on only selected post types
		if (is_singular() && in_array(get_post_type(), $selected_post_types)) {
			wp_enqueue_style('hfnm-style', plugins_url('/css/style.css', __FILE__), array(), '1.0.0', 'all', 9999);
			wp_enqueue_script('hfnm-script', plugins_url('/js/script.js', __FILE__), array('jquery'), '1.0', TRUE);
			wp_add_inline_script('hfnm-script', 'var nonce_wthf = "' . wp_create_nonce("hfnm_nonce") . '";var ajaxurl = "' . admin_url('admin-ajax.php') . '";', TRUE);
		}
	}

	// Ajax callback for yes-no
	public function hfnm_ajax_callback()
	{
		// Check Nonce
		if (!wp_verify_nonce($_REQUEST['nonce'], "hfnm_nonce")) {
			exit("No naughty business please.");
		}
		// Get posts
		$post_id = intval($_REQUEST['id']);
		$value = intval($_REQUEST['val']);
		$value_name = "_hfnm_no";
		if ($value == "1") {
			$value_name = "_hfnm_yes";
		}
		// Cookie check
		if (isset($_COOKIE["helpfulnessmeter_id_" . $post_id])) {
			exit("No naughty business please.");
		}
		// Get 
		$current_post_value = get_post_meta($post_id, $value_name, true);
		// Make it zero if empty
		if (empty($current_post_value)) {
			$current_post_value = 0;
		}
		// Update value
		$new_value = $current_post_value + 1;
		// Update post meta
		update_post_meta($post_id, $value_name, $new_value);
		// Die WP
		wp_die();
	}

	// Add custom column to admin
	public function hfnm_admin_columns($columns)
	{
		return array_merge($columns, array('helpfulnessmeter' => 'HelpfulnessMeter'));
	}

	// Custom column content
	public function hfnm_realestate_column($column, $post_id)
	{
		// Variables
		$positive_value = intval(get_post_meta($post_id, "_hfnm_yes", true));
		$negative_value = intval(get_post_meta($post_id, "_hfnm_no", true));
		// Total
		$total = $positive_value + $negative_value;
		if ($total > 0) {
			$ratio = intval($positive_value * 100 / $total);
		}
		// helpfulnessmeter ratio
		if ($column == 'helpfulnessmeter') {
			if ($total > 0) {
				echo sprintf(
					'<strong style="display:block;">%1$s %%</strong><em style="display:block;color:rgba(0,0,0,.55);">%2$d %3$s / %4$d %5$s</em>',
					number_format($ratio, 0, ',', ' '),
					$positive_value,
					_n('helpful', 'helpful', $positive_value, 'hfnm'),
					$negative_value,
					_n('not helpful', 'not helpful', $negative_value, 'hfnm')
				);
				echo sprintf(
					'<div style="margin-top: 5px;width:100%%;max-width:100px;background:rgba(0,0,0,.12);line-height:0px;font-size:0px;border-radius:3px;"><span style="width:%d%%;background:rgba(0,0,0,.55);height:4px;display:inline-block;border-radius:3px;"></span></div>',
					$ratio
				);
			} else {
				echo "—";
			}
		}
	}

	// Add post type support
	public function hfnm_post_type_support()
	{
		// Get selected post types
		$selected_post_types = get_option("hfnm_types");
		// Read selected post types
		if (empty($selected_post_types)) {
			$selected_post_types = array();
		}
		$selected_type_array = json_decode($selected_post_types);
		// loop selected type
		if (!empty($selected_type_array)) {
			foreach ($selected_type_array as $selected_type) {
				add_filter('manage_' . $selected_type . '_posts_columns', array($this, 'hfnm_admin_columns'));
				add_action('manage_' . $selected_type . '_posts_custom_column', array($this, 'hfnm_realestate_column'), 10, 2);
			}
		}
	}

	// Register option page
	public function hfnm_register_options_page()
	{
		add_options_page('HelpfulnessMeter Plugin Options', 'HelpfulnessMeter', 'manage_options', 'hfnm', array($this, 'hfnm_options_page'));
	}

	// Option page settings
	public function hfnm_options_page()
	{
		// If isset
		if (isset($_POST['hfnm_options_nonce'])) {
			// Check Nonce
			if (wp_verify_nonce($_POST['hfnm_options_nonce'], "hfnm_options_nonce")) {
				// Reset statistics
				if (isset($_POST['hfnm_reset_stats'])) {
					$this->hfnm_reset_stats();
					echo '<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"><p><strong>' . __('Statistics have been reset.', 'hfnm') . '</strong></p></div>';
				}
				// Update options
				if (isset($_POST['hfnm_types'])) {
					$types = array_values($_POST['hfnm_types']);
				} else {
					$types = array();
				}
				update_option('hfnm_types', json_encode($types));
				update_option('hfnm_question_text', sanitize_text_field($_POST["hfnm_question_text"]));
				update_option('hfnm_yes_text', sanitize_text_field($_POST["hfnm_yes_text"]));
				update_option('hfnm_no_text', sanitize_text_field($_POST["hfnm_no_text"]));
				update_option('hfnm_thank_yes_text', sanitize_text_field($_POST["hfnm_thank_yes_text"]));
				update_option('hfnm_thank_no_text', sanitize_text_field($_POST["hfnm_thank_no_text"]));
				// Settings saved
				echo '<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"><p><strong>' . __('Settings saved.', 'hfnm') . '</strong></p></div>';
			}
		}
		?>
		<div class="wrap">
			<h2>
				<?php _e('HelpfulnessMeter Options', 'hfnm'); ?>
			</h2>
			<p>
				<?php _e('The HelpfulnessMeter widget will automatically appear at the end of the selected post types. Please choose the post types where you would like to display this widget.', 'hfnm'); ?>
			</p>
			<p>
				<?php _e('Alternatively, you can manually display the widget anywhere in your content using the following shortcode: ', 'hfnm'); ?><code>[helpfulness_meter]</code>
			</p>
			<p>
				<?php _e('You may also need to list all content stats where the above shortcode is manually added, in that case I invite you to create a private page and paste the following shortcode to do so: ', 'hfnm'); ?><code>[hfnm_shortcode_list]</code>
			</p>
			<form method="post" action="options-general.php?page=hfnm">
				<input type="hidden" value="<?php echo wp_create_nonce("hfnm_options_nonce"); ?>" name="hfnm_options_nonce" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="hfnm_post_types">
								<?php _e('Post Types', 'hfnm'); ?>
							</label></th>
						<td>
							<?php
							// Post Types
							$post_types = get_post_types(array('public' => true), 'names');
							$selected_post_types = get_option("hfnm_types");
							// Read selected post types
							$selected_type_array = json_decode($selected_post_types);
							// Foreach
							foreach ($post_types as $post_type) {
								// Skip Attachment
								if ($post_type == 'attachment') {
									continue;
								}
								// Get value
								$checkbox = '';
								if (!empty($selected_type_array)) {
									if (in_array($post_type, $selected_type_array)) {
										$checkbox = ' checked';
									}
								}
								// print inputs
								echo '<label for="' . $post_type . '" style="margin-right:18px;"><input' . $checkbox . ' name="hfnm_types[]" type="checkbox" id="' . $post_type . '" value="' . $post_type . '">' . $post_type . '</label>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hfnm_question_text">
								<?php _e('Question', 'hfnm'); ?>
							</label></th>
						<td><input type="text" placeholder="Was this helpful?" class="regular-text" id="hfnm_question_text"
								name="hfnm_question_text" value="<?php echo get_option('hfnm_question_text'); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hfnm_yes_text">
								<?php _e('Positive Answer', 'hfnm'); ?>
							</label></th>
						<td><input type="text" placeholder="Yes" class="regular-text" id="hfnm_yes_text" name="hfnm_yes_text"
								value="<?php echo get_option('hfnm_yes_text'); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="hfnm_no_text">
								<?php _e('Negative Answer', 'hfnm'); ?>
							</label></th>
						<td><input type="text" placeholder="No" class="regular-text" id="hfnm_no_text" name="hfnm_no_text"
								value="<?php echo get_option('hfnm_no_text'); ?>" /></td>
					</tr>
					<tr>
                        <th scope="row"><label for="hfnm_thank_yes_text">
                                <?php _e('Thank You Message for Positive Answer', 'hfnm'); ?>
                            </label></th>
                        <td><textarea type="text" placeholder="Thanks for your positive feedback!" class="regular-text" id="hfnm_thank_yes_text" name="hfnm_thank_yes_text"><?php echo esc_textarea(get_option('hfnm_thank_yes_text')); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hfnm_thank_no_text">
                                <?php _e('Thank You Message for Negative Answer', 'hfnm'); ?>
                            </label></th>
                        <td><textarea type="text" placeholder="Thanks for your negative feedback!" class="regular-text" id="hfnm_thank_no_text" name="hfnm_thank_no_text"><?php echo esc_textarea(get_option('hfnm_thank_no_text')); ?></textarea>
                        </td>
                    </tr>
					<tr>
						<th scope="row"><label for="hfnm_reset_stats">
								<?php _e('Reset Statistics', 'hfnm'); ?>
							</label></th>
						<td><input type="checkbox" id="hfnm_reset_stats" name="hfnm_reset_stats" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// Reset statistics
	public function hfnm_reset_stats()
	{
		global $wpdb;
		$wpdb->query("UPDATE $wpdb->postmeta SET meta_value = 0 WHERE meta_key = '_hfnm_no' OR meta_key = '_hfnm_yes'");
	}

	// Add HelpfulnessMeter shortcode
	public function hfnm_shortcode()
	{
		// Get post id
		$post_id = get_the_ID();
		$content = "";
		// Dont show if already voted
		if (!isset($_COOKIE["helpfulnessmeter_id_" . $post_id])) {
			// Enqueue style and scripts
			wp_enqueue_style('hfnm-style', plugins_url('/css/style.css', __FILE__), array(), '1.0.0', 'all', 9999);
			wp_enqueue_script('hfnm-script', plugins_url('/js/script.js', __FILE__), array('jquery'), '1.0', TRUE);
			wp_add_inline_script('hfnm-script', 'var nonce_wthf = "' . wp_create_nonce("hfnm_nonce") . '";var ajaxurl = "' . admin_url('admin-ajax.php') . '";', TRUE);
			// The widget markup
			$content = sprintf(
                '<div id="helpfulnessmeter" data-post-id="%s">
                    <div id="hfnm-title">%s</div>
                    <div id="hfnm-yes-no">
                        <span data-value="1">%s</span>
                        <span data-value="0">%s</span>
                    </div>
                    <div id="hfnm-thank-yes" style="display:none;">%s</div>
                    <div id="hfnm-thank-no" style="display:none;">%s</div>
                </div>',
                $post_id,
                get_option("hfnm_question_text"),
                get_option("hfnm_yes_text"),
                get_option("hfnm_no_text"),
                get_option("hfnm_thank_yes_text"),
                get_option("hfnm_thank_no_text")
            );
		}
		return $content;
	}

	// List posts with HelpfulnessMeter shortcode
	public function hfnm_get_helpfulnessmeter_posts()
	{
		$helpfulnessmeter_posts = array();
		$posts = get_posts(array('post_type' => 'any', 'posts_per_page' => -1));
		foreach ($posts as $post) {
			if (has_shortcode($post->post_content, 'helpfulness_meter')) {
				$helpfulnessmeter_posts[] = $post;
			}
		}
		return $helpfulnessmeter_posts;
	}

	// Create shortcode to list posts with [helpfulness_meter] shortcode and HelpfulnessMeter statistics
	public function hfnm_shortcode_list()
	{
		// Retrieve posts with [helpfulness_meter] shortcode
		$helpfulnessmeter_posts = $this->hfnm_get_helpfulnessmeter_posts();
		// Enqueue the CSS file
		wp_enqueue_style('hfnm-style', plugins_url('/css/style.css', __FILE__), array(), '1.0.0', 'all', 9999);
		// Create output string
		$output = '<div class="hfnm-table-wrapper"><table class="hfnm-table"><thead><tr><th class="hfnm-col-title">' . __('Content Title', 'hfnm') . '</th><th class="hfnm-col-helpful">' . __('HelpfulnessMeter Statistics', 'hfnm') . '</th></tr></thead><tbody>';
		// Loop through posts and output row for each
		foreach ($helpfulnessmeter_posts as $post) {
			setup_postdata($post);
			$output .= '<tr><td class="hfnm-col-title"><a href="' . esc_url(get_permalink($post->ID)) . '" target="_blank" rel="noopener">' . esc_html($post->post_title) . '</a></td><td class="hfnm-col-helpful">';
			ob_start();
			$this->hfnm_realestate_column('helpfulnessmeter', $post->ID);
			$output .= ob_get_clean();
			$output .= '</td></tr>';
		}
		wp_reset_postdata();
		// Close table and return output
		$output .= '</tbody></table></div>';
		return $output;
	}
}
// Instantiate the class
$helpfulness_meter = new HelpfulnessMeter();