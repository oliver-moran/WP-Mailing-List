<?php
/*
Plugin Name: WP Mailing List
Plugin URI: http://code.nth-iteration.ie/wp-mailing-list
Description: Adds the ability to send email and manage a mailing list from WordPress.
Version: 0.1
Author: Oliver Moran
Author URI: http://www.nth-iteration.ie/
License: GPL2
*/

require_once("class.html2text.inc");

add_action( 'admin_menu', 'register_my_custom_menu_page' );
function register_my_custom_menu_page(){
    add_options_page( 'Email Settings', 'Email', 'manage_options', 'options-email', 'email_options' );
	add_users_page( "Mailing List Options", "Mailing List", "read", "mailing-list-options", 'mailing_list_options');
}

function add_email_metabox() {
	if ( current_user_can('publish_posts') ) {
		add_meta_box('send_email', 'Sender and Recipients', 'send_email_meta', 'email', 'side', 'default');
	}
}

add_action( 'init', 'create_mail_type' );
function create_mail_type() {
	register_post_type( 'email',
		array(
			'label' => 'Emails',
			'labels' => array(
				'name' => 'Emails',
				'singular_name' => 'Email',
				'menu_name' => 'Email',
				'all_items' => 'All Emails',
				'add_new' => 'Create New',
				'add_new_item' => 'Create New Email',
				'edit_item' => 'Edit Email',
				'new_item' => 'New Email',
				'view_item' => 'View Email',
				'search_items' => 'Search Email',
				'not_found' => 'No emails found',
				'not_found_in_trash' => 'No emails found in trash',
				'parent_item_colon' => 'Parent Email'
			),
			'description' => 'An email used in a mail out.',
			'public' => true,
			'exclude_from_search' => true,
			'show_ui' => true,
			'show_in_nav_menus' => false,
			'show_in_menu' => true,
			'menu_position' => 20,
			'show_in_admin_bar' => true,
			'has_archive' => true,
			'capability_type' => 'post',
			'rewrite' => array('slug' => 'email'),
			'register_meta_box_cb' => 'add_email_metabox',
			'menu_icon' => plugins_url('wp-mailing-list/internet_mail_16.png')
		)
	);
}

add_action('admin_head', 'modify_email_screen_icon');
function modify_email_screen_icon() {
	global $post_type;
	if ($_GET['post_type'] == 'email' || $post_type == 'email') :
	?>
	<style type="text/css">
	#icon-edit { background:transparent url('<?php echo plugins_url('wp-mailing-list/internet_mail_32.png'); ?>') no-repeat; }     
	</style>
	<?php
	endif;
}

function send_email_meta(){
	?>
	<p>
		<strong>From:</strong><br/>
		<input type="text" name="from-name" style="width:100%;" placeholder="Sender Name" value="<?php echo from_name(); ?>" /><br/>
		<input type="text" name="from-email" style="width:100%;" placeholder="sender@example.com" value="<?php echo from_email(); ?>" /><br/>
	</p>
	<p>
		<strong>To:</strong><br/>
		<textarea style="width:100%" name="to-other" placeholder="joe@example.com, jane@example.com; ..."></textarea>
		<br/>
		<label>
			<input type="checkbox" name="to-subscribers">&nbsp;All subscribers
		</label>
	</p>
	<?php
}

add_action('save_post', 'send_email');
function send_email(){

	global $post_type;
	if ($post_type == 'email') {

		session_start();
		$_SESSION["wp_mailing_list_send_count"] = count(explode(",", $html_recipients))
												+ count(explode(",", $text_recipients))
												+ count(explode(",", $other_recipients));

		$html_recipients = '';
		$text_recipients = '';

		if ($_POST['to-subscribers'] == 'on') {
		    $subscribers = get_users();
		    foreach ($subscribers as $subscriber) {
				$subscribed_pref = get_user_meta($subscriber->ID, 'mailing_list_subscribed', true);
				$subscribed = (isset($subscribed_pref)) ? $subscribed_pref : (subscribe_method() == 'opt_out');

				$send_html_pref = get_user_meta($subscriber->ID, 'mailing_list_send_html', true);
				$send_html = (isset($send_html_pref)) ? $send_html_pref : true;

				if ($subscribed && $send_html) {
					$html_recipients .= $subscriber->user_email . ', ';
				} else if ($subscribed) {
					$text_recipients .= $subscriber->user_email . ', ';
				}
		    }
		}

		$other_recipients = $_POST['to-other']; // NB: Other recipients are always sent as HTML

		if (send_mail_method() == 'bcc') {
			if ($html_recipients !== '') {
				send_content_to_recipients('', $html_recipients, true, subscriber_footer_content());
			}

			if ($text_recipients !== '') {
				send_content_to_recipients('', $text_recipients, false, subscriber_footer_content());
			}

			if ($other_recipients !== '') {
				send_content_to_recipients('', $other_recipients, true, other_footer_content());
			}

		} else {
			$html_recipients_arr = explode(",", $html_recipients);
			$text_recipients_arr = explode(",", $text_recipients);
			$other_recipients_arr = explode(",", $other_recipients);

			if ($html_recipients !== '') {
				foreach ($html_recipients_arr as $html_recipient) {
					send_content_to_recipients(trim($html_recipient), '', true, subscriber_footer_content());
				}
			}

			if ($text_recipients !== '') {
				foreach ($text_recipients_arr as $text_recipient) {
					send_content_to_recipients($text_recipient, '', false, subscriber_footer_content());
				}
			}

			if ($other_recipients !== '') {
				foreach ($other_recipients_arr as $other_recipient) {
					send_content_to_recipients($other_recipient, '', true, other_footer_content());
				}
			}
		}
	}
}

function send_content_to_recipients($to = '', $bcc = '', $isHTML = true, $footer = '') {
	$subject = get_the_title(); // FIXME: this is not the latest
	$message = apply_filters('the_content', get_post(get_the_ID())->post_content);
	$message = str_replace("\n.", "\n..", $message); // NB: fix for Windows: http://php.net/manual/en/function.mail.php

	$message .= "<hr/>" . $footer;

	$headers = 	'From: ' . $_POST['from-name'] . ' <' . $_POST['from-email'] . ">\r\n";
	$headers .= 'Reply-To: ' . reply_name() . ' <' . reply_email() . ">\r\n";
	$headers .= 'X-Mailer: WordPress/' . get_bloginfo('version') . ' using PHP/' . phpversion() . "\r\n";
	if ($isHTML) {
		// NB: To send HTML mail, the Content-type header must be set: http://php.net/manual/en/function.mail.php
		$headers .= 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
	} else {
		// html2text class converts message to html
		$h2t =& new html2text($message);
		$message = $h2t->get_text();
	}

	$headers .= 'Bcc: ' . $bcc . "\r\n"; // NB: BCC to protect emails and identities

	//mail($to, $subject, $message, $headers);
}

add_filter('post_updated_messages', 'my_updated_messages');
function my_updated_messages( $messages ) {
	global $post, $post_type;
	if ($post_type == "email") {
		session_start();
		if (!isset($_SESSION["wp_mailing_list_send_count"])) {
			$_SESSION["wp_mailing_list_send_count"] = 0;
		}
		switch ($_SESSION["wp_mailing_list_send_count"]) {
			case 0:
				$msg = "Email updated.";
				break;
			case 1:
				$msg = "Email updated. " . $_SESSION["wp_mailing_list_send_count"] . " email sent.";
				break;
			default:
				$msg = "Email updated. " . $_SESSION["wp_mailing_list_send_count"] . " emails sent.";
				break;
		}

		$messages["post"][1] = $msg;
		unset($_SESSION["wp_mailing_list_send_count"]);
	}
	return $messages;
}

function register_email_setting() {
	register_setting( 'email_settings', 'from_name' );
	register_setting( 'email_settings', 'from_email' );
	register_setting( 'email_settings', 'reply_name' );
	register_setting( 'email_settings', 'reply_email' );
	register_setting( 'email_settings', 'use_from_for_reply' );
	register_setting( 'email_settings', 'send_mail_method' );
	register_setting( 'email_settings', 'mailing_list_description' );
	register_setting( 'email_settings', 'subscribe_method' );
	register_setting( 'email_settings', 'subscriber_footer_content' );
	register_setting( 'email_settings', 'other_footer_content' );
}
add_action('admin_init', 'register_email_setting');

function from_name(){
	return get_option('from_name', get_bloginfo('name'));
}

function from_email(){
	return get_option('from_email', get_bloginfo('admin_email'));
}

function reply_name(){
	return get_option('reply_name', get_bloginfo('name'));
}

function reply_email(){
	return get_option('reply_email', get_bloginfo('admin_email'));
}

function use_from_for_reply(){
	return get_option('use_from_for_reply', true);
}

function send_mail_method(){
	return get_option('send_mail_method', 'bcc');
}

function mailing_list_description(){
	return get_option('mailing_list_description', '');
}

function subscribe_method(){
	return get_option('subscribe_method', 'out_out');
}

function subscriber_footer_content(){
	return get_option('subscriber_footer_content', '<p><small>You received this email because you are subscribed to ' . get_bloginfo('name') . '\'s mailing list. <a href="' . get_bloginfo('url') . '/wp-admin/users.php?page=mailing-list-options">Click here</a> to change your subscription options.</small></p>');
}

function other_footer_content(){
	return get_option('other_footer_content', '<p><small>You received this email from <a href="' . get_bloginfo('url') . '">' . get_bloginfo('name') . '</a>. If you do not want to receive any more correspondences like this from ' . get_bloginfo('name') . ', please contact <a href="mailto:' . get_bloginfo( 'admin_email' ) . '">' . get_bloginfo( 'admin_email' ) . '</a>.</small></p>');
}

function email_options() {
	?>

	<script type="text/javascript">
	function enable_reply_to_fields(){
		var val = document.getElementById("same-as-from-checkbox").checked;
		document.getElementById("reply-name").readOnly = val;
		document.getElementById("reply-email").readOnly = val;
		match_from_and_reply_fields();
	}

	function match_from_and_reply_fields(){
		if (document.getElementById("same-as-from-checkbox").checked) {
			document.getElementById("reply-name").value = document.getElementById("from-name").value;
			document.getElementById("reply-email").value = document.getElementById("from-email").value;
		}
	}

	</script>
	<div class='wrap'>
	<?php screen_icon(); ?>
	<h2>Email Settings</h2>

	<form method="post" action="options.php">
	<?php settings_fields( 'email_settings' ); ?>
	<table class="form-table">
	<tr valign="top">
	<th scope="row">From address (default)</th>
	<td>
		<input type="text" name="from_name" id="from-name" onchange="javascript:match_from_and_reply_fields();" style="width:100%;" placeholder="Sender Name" value="<?php echo from_name(); ?>" /><br/>
		<input type="text" name="from_email" id="from-email" onchange="javascript:match_from_and_reply_fields();" style="width:100%;" placeholder="sender@example.com" value="<?php echo from_email(); ?>" />
	</td>
	</tr>
	<th scope="row">Reply-to address</th>
	<td>
		<input <?php if (use_from_for_reply()) echo 'readonly="true"'; ?> type="text" name="reply_name" id="reply-name" style="width:100%;" placeholder="Reply Name" value="<?php echo reply_name(); ?>" /><br/>
		<input <?php if (use_from_for_reply()) echo 'readonly="true"'; ?> type="text" name="reply_email" id="reply-email" style="width:100%;" placeholder="reply@example.com" value="<?php echo reply_email(); ?>" /><br/>
		<label><input type="checkbox" name='use_from_for_reply' id="same-as-from-checkbox" <?php if (use_from_for_reply()) echo 'checked="checked"'; ?> onchange="javascript:enable_reply_to_fields();" />&nbsp;Same as From address</label>
	</td>
	</tr>
	</table>

	<table class="form-table">
	<tr valign="top">
	<th scope="row">Send method</th>
	<td>
		<label><input type="radio" name="send_mail_method" value="bcc" <?php if (send_mail_method() == 'bcc') echo 'checked="checked"'; ?> />&nbsp;Send emails in bulk using BCC (recommened)</label><br/>
		<label><input type="radio" name="send_mail_method" value="to" <?php if (send_mail_method() == 'to') echo 'checked="checked"'; ?> />&nbsp;Send each email individually</label>
	</td>
	</tr>
	</table>

	<h3>Mailing List</h3>

	<table class="form-table">
	<tr valign="top">
	<th scope="row">Description</th>
	<td>
	<?php

	$content = mailing_list_description();
	$editor_id = 'mailing_list_description';
	$args = array('textarea_rows' => 3, 'teeny' => true); // Optional arguments.
	wp_editor( $content, $editor_id, $args );

	?>

	<p>This text will appear on the <a href="users.php?page=mailing-list-options">Mailing List</a> page under each user's Profile menu item.</p>

	</td>
	</tr>
	<tr valign="top">
	<th scope="row">Subscription method</th>
	<td>
		<label><input type="radio" name="subscribe_method" value="opt_out" <?php if (subscribe_method() == 'opt_out') echo 'checked="checked"'; ?> />&nbsp;Users are subscribed by default</label><br/>
		<label><input type="radio" name="subscribe_method" value="opt_in" <?php if (subscribe_method() == 'opt_in') echo 'checked="checked"'; ?> />&nbsp;Users need to manually subscribe</label>
	</td>
	</tr>
	</table>

	<h3>Footer</h3>

	<p>A footer can be added to every out-going email. This footer can be used to include information and links required by law. For example, the footer can include an unsubscribe link required by the <a href="http://eur-lex.europa.eu/LexUriServ/LexUriServ.do?uri=CELEX:32002L0058:EN:NOT" target="_blank">E-Privacy Directive</a> in the European Union or the <a href="http://www.gpo.gov/fdsys/pkg/PLAW-108publ187/pdf/PLAW-108publ187.pdf" target="_blank">CAN-SPAM Act</a> in the United States.</p>
	<p>Two types of footer are available. One type is for emails sent to subscribers to <?php bloginfo( 'name' ); ?>. Anoter type is for emails sent to people who are not subscribers to this website. If you do not need to include a footer in your emails, these fields can be left blank.</p>

	<table class="form-table">
	<tr valign="top">
	<th scope="row">Subscribers</th>
	<td>
	<?php

	$content = subscriber_footer_content();
	$editor_id = 'subscriber_footer_content';
	$args = array('textarea_rows' => 3, 'teeny' => true); // Optional arguments.
	wp_editor( $content, $editor_id, $args );

	?>
	</td>
	</tr>
	<tr valign="top">
	<th scope="row">Non-subscribers</th>
	<td>
	<?php

	$content = other_footer_content();
	$editor_id = 'other_footer_content';
	$args = array('textarea_rows' => 3, 'teeny' => true); // Optional arguments.
	wp_editor( $content, $editor_id, $args );

	?>
	</td>
	</tr>
	</table>

	<p>
	<?php submit_button(); ?>
	</p>

	</form>
	</div>

	<?php
}

function mailing_list_options(){
	// defaults (either what was submitted or what is set by admin)
	$subscribed = (isset($_POST['mailing_list_subscribed'])) ? $_POST['mailing_list_subscribed'] == 'true' : (subscribe_method() == 'opt_out');
	$send_html = (isset($_POST['mailing_list_send_html'])) ? $_POST['mailing_list_send_html'] == 'true' : true;

	// set to user preferences if they exist and there is no POST data
	$subscribed_pref = get_user_meta(wp_get_current_user()->ID, 'mailing_list_subscribed', true);
	$send_html_pref = get_user_meta(wp_get_current_user()->ID, 'mailing_list_send_html', true);

	if (!isset($_POST['mailing_list_subscribed']) && isset($subscribed_pref)) {
		$subscribed = $subscribed_pref;
	}
	
	if (!isset($_POST['mailing_list_send_html']) && isset($send_html_pref)) {
		$send_html = $send_html_pref;
	}
	
	// if there is POST data then update the user prefs from that
	if (isset($_POST['mailing_list_subscribed'])) {
		update_user_meta(wp_get_current_user()->ID, 'mailing_list_subscribed', $subscribed);
	}
	
	if (isset($_POST['mailing_list_send_html'])) {
		update_user_meta(wp_get_current_user()->ID, 'mailing_list_send_html', $send_html);
	}
	?>

	<form method="POST">
	<div class='wrap'>
	<?php screen_icon(); ?>
	<h2>Mailing List</h2>

	<p><?php bloginfo( 'name' ); ?> has a mailing list, which authors of the site can use to send mail-outs to subscribers.</p>

	<?php echo mailing_list_description(); ?>

	<table class="form-table">
	<tr valign="top">
	<th scope="row">Subscribe</th>
	<td>
		<label><input type="radio" name="mailing_list_subscribed" value="true" <?php if ($subscribed) echo 'checked="checked"'; ?> />&nbsp;Yes</label><br/>
		<label><input type="radio" name="mailing_list_subscribed" value="false" <?php if (!$subscribed) echo 'checked="checked"'; ?> />&nbsp;No</label>
	</td>
	</tr>
	<tr valign="top">
	<th scope="row">Preferred format</th>
	<td>
		<label><input type="radio" name="mailing_list_send_html" value="true" <?php if ($send_html) echo 'checked="checked"'; ?> />&nbsp;HTML (recommended)</label><br/>
		<label><input type="radio" name="mailing_list_send_html" value="false" <?php if (!$send_html) echo 'checked="checked"'; ?> />&nbsp;Plain text</label>
	</td>
	</tr>
	</table>

	<p>
	<?php submit_button(); ?>
	</p>
	</div>
	</form>

	<?php
}