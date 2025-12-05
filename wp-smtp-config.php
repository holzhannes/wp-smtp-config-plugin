<?php
/*
Plugin Name: WP SMTP Config
Plugin URI:  https://github.com/holzhannes/wp-smtp-config-plugin
Description: Configure an external SMTP server via wp-config.php constants and send a test email. Uses WP_SMTP_* constants.
Version:     1.3.0
Author:      Daniel Schröder, holzhannes
License:     GPL-2.0-or-later
*/

/**
 * Helper: Parse a "Name <email@example.com>" or "email@example.com" string.
 *
 * @param string $raw Raw address string.
 * @return array{email:string,name:string}
 */
function wp_smtp_config_parse_address( $raw ) {
	if ( ! is_string( $raw ) || $raw === '' ) {
		return array(
			'email' => '',
			'name'  => '',
		);
	}

	$raw   = trim( $raw );
	$email = '';
	$name  = '';

	// Format: "Name <email@example.com>"
	if ( preg_match( '/^(.*)<(.+)>$/', $raw, $matches ) ) {
		$name_candidate  = trim( $matches[1] );
		$email_candidate = trim( $matches[2] );
	} else {
		// Only email address.
		$name_candidate  = '';
		$email_candidate = $raw;
	}

	if ( is_email( $email_candidate ) ) {
		$email = $email_candidate;
	}

	if ( $name_candidate !== '' ) {
		$name = sanitize_text_field( $name_candidate );
	}

	return array(
		'email' => $email,
		'name'  => $name,
	);
}

/**
 * Helper: Get FROM address parts from WP_SMTP_FROM.
 *
 * @return array{email:string,name:string}
 */
function wp_smtp_config_get_from_parts() {
	if ( ! defined( 'WP_SMTP_FROM' ) ) {
		return array(
			'email' => '',
			'name'  => '',
		);
	}

	return wp_smtp_config_parse_address( WP_SMTP_FROM );
}

/**
 * Configure PHPMailer using WP_SMTP_* constants.
 *
 * @param PHPMailer $phpmailer PHPMailer instance (passed by reference).
 */
function wp_smtp_config_phpmailer_init( $phpmailer ) {

	if ( ! defined( 'WP_SMTP_HOST' ) || ! is_string( WP_SMTP_HOST ) || WP_SMTP_HOST === '' ) {
		// Nothing to do if no host is defined.
		return;
	}

	// Basic SMTP setup.
	$phpmailer->isSMTP();
	$phpmailer->Host = WP_SMTP_HOST;

	// Port.
	if ( defined( 'WP_SMTP_PORT' ) && WP_SMTP_PORT ) {
		$phpmailer->Port = (int) WP_SMTP_PORT;
	}

	// Encryption: 'ssl' or 'tls'.
	if ( defined( 'WP_SMTP_ENCRYPTION' ) && is_string( WP_SMTP_ENCRYPTION ) ) {
		$encryption = strtolower( WP_SMTP_ENCRYPTION );
		if ( in_array( $encryption, array( 'ssl', 'tls' ), true ) ) {
			$phpmailer->SMTPSecure = $encryption;
		}
	}

	// Auth / credentials.
	if ( defined( 'WP_SMTP_USER' ) && is_string( WP_SMTP_USER ) && WP_SMTP_USER !== '' ) {
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = WP_SMTP_USER;

		if ( defined( 'WP_SMTP_PASSWORD' ) && is_string( WP_SMTP_PASSWORD ) ) {
			$phpmailer->Password = WP_SMTP_PASSWORD;
		}
	}

	// Reply-To.
	if ( defined( 'WP_SMTP_REPLYTO' ) && is_string( WP_SMTP_REPLYTO ) && WP_SMTP_REPLYTO !== '' ) {
		$reply_parts = wp_smtp_config_parse_address( WP_SMTP_REPLYTO );

		if ( ! empty( $reply_parts['email'] ) ) {
			if ( $reply_parts['name'] !== '' ) {
				$phpmailer->addReplyTo( $reply_parts['email'], $reply_parts['name'] );
			} else {
				$phpmailer->addReplyTo( $reply_parts['email'] );
			}
		}
	}

	// FROM (Adress- und ggf. Name) aus WP_SMTP_FROM – nutzt denselben Helper wie die Filter unten.
	$from_parts = wp_smtp_config_get_from_parts();

	if ( ! empty( $from_parts['email'] ) ) {
		$from_name = $from_parts['name'] !== '' ? $from_parts['name'] : $phpmailer->FromName;
		// Drittes Argument: do not auto-rewrite if already set.
		$phpmailer->setFrom( $from_parts['email'], $from_name, false );
	}
}

/**
 * Filter: Override wp_mail() "from" address using WP_SMTP_FROM.
 *
 * @param string $from Current from email.
 * @return string
 */
function wp_smtp_config_mail_from( $from ) {
	$parts = wp_smtp_config_get_from_parts();

	if ( ! empty( $parts['email'] ) ) {
		return $parts['email'];
	}

	return $from;
}

/**
 * Filter: Override wp_mail() "from name" using WP_SMTP_FROM (if a name is present).
 *
 * @param string $from_name Current from name.
 * @return string
 */
function wp_smtp_config_mail_from_name( $from_name ) {
	$parts = wp_smtp_config_get_from_parts();

	// Only override when a readable name is configured.
	if ( ! empty( $parts['name'] ) ) {
		return $parts['name'];
	}

	return $from_name;
}

/**
 * Add SMTP Test menu to network admin.
 */
function wp_smtp_config_network_admin_menu() {
	add_submenu_page(
		'settings.php',
		'SMTP Settings',
		'SMTP Test',
		'manage_network_options',
		'wp-smtp-config',
		'wp_smtp_config_options_page'
	);
}

/**
 * Add SMTP Test menu to normal admin.
 */
function wp_smtp_config_admin_menu() {
	add_options_page(
		'SMTP Settings',
		'SMTP Test',
		'manage_options',
		'wp-smtp-config',
		'wp_smtp_config_options_page'
	);
}

/**
 * Handle form submission on the SMTP test page.
 *
 * @return stdClass|null
 */
function wp_smtp_config_options_page_save() {
	global $phpmailer;

	if ( isset( $_POST['smtp_submit'], $_POST['smtp_recipient'] ) && $_POST['smtp_submit'] === 'Send' ) {

		check_admin_referer( 'wp_smtp_config_test_email' );

		$message          = new stdClass();
		$message->error   = true;
		$message->title   = 'Test Email Failure';
		$message->content = array(
			'There was an error while trying to send the test email.',
		);

		$recipient_raw = isset( $_POST['smtp_recipient'] ) ? wp_unslash( $_POST['smtp_recipient'] ) : '';
        $recipient = sanitize_email( $recipient_raw);

		if ( is_email( $recipient ) ) {

			if ( wp_mail(
				$recipient,
				'SMTP Test',
				'If you received this email it means you have configured SMTP correctly on your WordPress website.'
			) ) {
				$message->error   = false;
				$message->title   = 'Test Email Success';
				$message->content = array(
					'The test email was sent successfully.',
				);

				return $message;
			}

			$error = ( is_object( $phpmailer ) && is_a( $phpmailer, 'PHPMailer' ) ) ? $phpmailer->ErrorInfo : '';
			if ( ! empty( $error ) ) {
				$message->content[] = $error;
			}
		} elseif ( $recipient === '' ) {
			$message->content[] = 'Please enter a valid email address.';
		} else {
			$message->content[] = sprintf(
				'%s is no valid email address.',
				esc_html( $recipient )
			);
		}

		return $message;
	}

	return null;
}

/**
 * Render SMTP Test settings page.
 */
function wp_smtp_config_options_page() {
	$message = wp_smtp_config_options_page_save();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SMTP Settings', 'wp-smtp-config' ); ?></h1>

		<?php if ( $message instanceof stdClass ) : ?>
			<div class="<?php echo $message->error ? 'notice notice-error' : 'notice notice-success'; ?>">
				<p><strong><?php echo esc_html( $message->title ); ?></strong></p>
				<?php foreach ( $message->content as $content ) : ?>
					<p><?php echo esc_html( $content ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Send a Test Email', 'wp-smtp-config' ); ?></h2>
		<p><?php esc_html_e( 'Enter a valid email address below to send a test message.', 'wp-smtp-config' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'wp_smtp_config_test_email' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="smtp_recipient"><?php esc_html_e( 'Recipient', 'wp-smtp-config' ); ?></label>
					</th>
					<td>
						<input type="email" class="regular-text" name="smtp_recipient" id="smtp_recipient"
						       value="<?php echo isset( $_POST['smtp_recipient'] ) ? esc_attr( wp_unslash( $_POST['smtp_recipient'] ) ) : ''; ?>">
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Send', 'wp-smtp-config' ), 'primary', 'smtp_submit' ); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Configuration via wp-config.php', 'wp-smtp-config' ); ?></h2>
		<p><?php esc_html_e( 'This plugin reads the following constants from wp-config.php:', 'wp-smtp-config' ); ?></p>
		<?php
		$config_snippet = "/**
 * WordPress SMTP server settings
 */
define('WP_SMTP_HOST',       'mail.example.com');
define('WP_SMTP_PORT',        465);                              // obligatory - default: 25
define('WP_SMTP_ENCRYPTION', 'ssl');                             // obligatory ('tls' or 'ssl') - default: no encryption
define('WP_SMTP_USER',       'username');                        // obligatory - default: no user
define('WP_SMTP_PASSWORD',   'password');                        // obligatory - default: no password
define('WP_SMTP_FROM',       'John Doe <john.doe@example.com>'); // obligatory - default: no custom from address also set as default wordpress sending address
define('WP_SMTP_REPLYTO',    'Jane Doe <jane.doe@example.com>'); // obligatory - default: no custom reply to address";
		?>
		<textarea id="wp-smtp-config-snippet"
		          class="large-text code"
		          rows="11"
		          readonly="readonly"><?php echo esc_textarea( $config_snippet ); ?></textarea>

		<p style="margin-top:8px;">
			<button type="button" class="button" id="wp-smtp-config-copy">
				<?php esc_html_e( 'Copy to clipboard', 'wp-smtp-config' ); ?>
			</button>
			<span id="wp-smtp-config-copy-feedback" style="margin-left:8px;"></span>
		</p>

		<script>
		(function() {
			const btn = document.getElementById('wp-smtp-config-copy');
			const ta  = document.getElementById('wp-smtp-config-snippet');
			const fb  = document.getElementById('wp-smtp-config-copy-feedback');

			if (!btn || !ta) {
				return;
			}

			btn.addEventListener('click', function (event) {
				event.preventDefault();

				ta.focus();
				ta.select();

				// Versuche Clipboard API, fallback auf execCommand.
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(ta.value).then(function () {
						if (fb) {
							fb.textContent = '<?php echo esc_js( __( 'Copied!', 'wp-smtp-config' ) ); ?>';
							setTimeout(function () { fb.textContent = ''; }, 2000);
						}
					}).catch(function () {
						document.execCommand('copy');
						if (fb) {
							fb.textContent = '<?php echo esc_js( __( 'Copied (fallback).', 'wp-smtp-config' ) ); ?>';
							setTimeout(function () { fb.textContent = ''; }, 2000);
						}
					});
				} else {
					// Fallback für ältere Browser.
					const success = document.execCommand('copy');
					if (fb) {
						fb.textContent = success
							? '<?php echo esc_js( __( 'Copied!', 'wp-smtp-config' ) ); ?>'
							: '<?php echo esc_js( __( 'Copy failed – please copy manually.', 'wp-smtp-config' ) ); ?>';
						setTimeout(function () { fb.textContent = ''; }, 2000);
					}
				}
			});
		})();
		</script>
	</div>
	<?php
}

// Hooks.
add_action( 'phpmailer_init', 'wp_smtp_config_phpmailer_init' );
add_filter( 'wp_mail_from', 'wp_smtp_config_mail_from' );
add_filter( 'wp_mail_from_name', 'wp_smtp_config_mail_from_name' );
add_action( 'network_admin_menu', 'wp_smtp_config_network_admin_menu' );
add_action( 'admin_menu', 'wp_smtp_config_admin_menu' );
