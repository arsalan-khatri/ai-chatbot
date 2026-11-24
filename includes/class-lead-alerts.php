<?php
/**
 * Lead alerts and email sending.
 *
 * - New lead alert: an email to the site owner (and anyone else listed) the
 *   moment a chat form is submitted, with the visitor's details, one-tap call
 *   and WhatsApp links and the chat that led to it.
 * - Reply to the visitor (optional): a confirmation email to the address the
 *   visitor typed in the form.
 * - How emails are sent: WordPress default (works with any SMTP plugin the
 *   site already has) or this plugin's own SMTP settings. The plugin's SMTP is
 *   used for the chatbot's own emails only; the rest of the site is untouched.
 *
 * Settings page: AI Chatbot > Lead Alerts & Email.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Chatbot_Lead_Alerts {

	const OPTION = 'wp_ai_chatbot_email';
	const PAGE   = 'wp-ai-chatbot-email';

	const DEFAULT_ALERT_SUBJECT   = 'New lead: {name} ({form})';
	const DEFAULT_VISITOR_SUBJECT = 'Thank you for contacting {site}';
	const DEFAULT_VISITOR_MESSAGE = "Hi {name},\n\nThank you for reaching out to {site}. We have received your details and our team will get back to you shortly.\n\nIf your question is urgent, simply reply to this email.\n\nBest regards,\n{site}";

	/** Common email providers: settings filled in with one click. */
	const PRESETS = array(
		'gmail'   => array( 'label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'enc' => 'tls', 'tip' => 'Use an App Password (Google Account > Security > 2-Step Verification > App passwords), not your normal Gmail password.' ),
		'outlook' => array( 'label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'enc' => 'tls', 'tip' => 'Use your full email address as the username. Your Microsoft 365 admin may need to allow "Authenticated SMTP" for the mailbox.' ),
		'zoho'    => array( 'label' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 465, 'enc' => 'ssl', 'tip' => 'Use your full Zoho email address and password (or an app-specific password if 2FA is on).' ),
		'hostinger' => array( 'label' => 'Hostinger email', 'host' => 'smtp.hostinger.com', 'port' => 465, 'enc' => 'ssl', 'tip' => 'Use the email account you created in Hostinger (hPanel > Emails) and its password.' ),
		'cpanel'  => array( 'label' => 'cPanel hosting email', 'host' => 'mail.yourdomain.com', 'port' => 465, 'enc' => 'ssl', 'tip' => 'Replace yourdomain.com with your domain. Use the email account and password created in cPanel > Email Accounts.' ),
		'brevo'   => array( 'label' => 'Brevo (Sendinblue)', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'enc' => 'tls', 'tip' => 'Username is your Brevo login email; password is an SMTP key from Brevo > SMTP & API.' ),
	);

	/** Known SMTP plugins, so the page can say the site already sends email properly. */
	const SMTP_PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php'          => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php'      => 'WP Mail SMTP Pro',
		'fluent-smtp/fluent-smtp.php'            => 'FluentSMTP',
		'post-smtp/postman-smtp.php'             => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'          => 'Easy WP SMTP',
		'wp-smtp/wp-smtp.php'                    => 'WP SMTP',
		'smtp-mailer/main.php'                   => 'SMTP Mailer',
		'gmail-smtp/main.php'                    => 'Gmail SMTP',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		'mailgun/mailgun.php'                    => 'Mailgun',
	);

	private static $last_error = '';

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_wp_ai_chatbot_test_email', array( $this, 'ajax_test_email' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'alert_on'        => '1',
			'alert_to'        => get_option( 'admin_email' ),
			'alert_subject'   => self::DEFAULT_ALERT_SUBJECT,
			'alert_chat'      => '1',
			'country_code'    => '',
			'visitor_on'      => '0',
			'visitor_subject' => self::DEFAULT_VISITOR_SUBJECT,
			'visitor_message' => self::DEFAULT_VISITOR_MESSAGE,
			'mode'            => 'wordpress',
			'smtp_host'       => '',
			'smtp_port'       => 587,
			'smtp_enc'        => 'tls',
			'smtp_auth'       => '1',
			'smtp_user'       => '',
			'smtp_pass'       => '',
			'from_email'      => '',
			'from_name'       => '',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function smtp_on( $o = null ) {
		$o = $o ? $o : self::get();
		return 'smtp' === $o['mode'] && '' !== trim( (string) $o['smtp_host'] );
	}

	public function add_submenu() {
		add_submenu_page( 'wp-ai-chatbot', 'Lead Alerts & Email', 'Lead Alerts & Email', 'manage_options', self::PAGE, array( $this, 'display_page' ) );
	}

	public function register_settings() {
		register_setting( 'wp_ai_chatbot_email_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	/** Comma or line separated addresses; invalid ones are dropped. */
	private static function clean_emails( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $e ) {
			$e = sanitize_email( $e );
			if ( '' !== $e && is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return implode( ', ', array_unique( $out ) );
	}

	public function sanitize( $in ) {
		$old = self::get();
		$in  = is_array( $in ) ? $in : array();
		$out = $old;

		$out['alert_on']        = empty( $in['alert_on'] ) ? '0' : '1';
		$out['alert_chat']      = empty( $in['alert_chat'] ) ? '0' : '1';
		$out['visitor_on']      = empty( $in['visitor_on'] ) ? '0' : '1';
		$out['alert_to']        = self::clean_emails( $in['alert_to'] ?? '' );
		$out['alert_subject']   = sanitize_text_field( $in['alert_subject'] ?? '' );
		$out['country_code']    = substr( preg_replace( '/\D/', '', (string) ( $in['country_code'] ?? '' ) ), 0, 4 );
		$out['visitor_subject'] = sanitize_text_field( $in['visitor_subject'] ?? '' );
		$out['visitor_message'] = sanitize_textarea_field( $in['visitor_message'] ?? '' );
		$out['mode']            = ( isset( $in['mode'] ) && 'smtp' === $in['mode'] ) ? 'smtp' : 'wordpress';
		$out['smtp_host']       = preg_replace( '/[^a-zA-Z0-9.\-]/', '', (string) ( $in['smtp_host'] ?? '' ) );
		$out['smtp_port']       = max( 1, min( 65535, (int) ( $in['smtp_port'] ?? 587 ) ) );
		$out['smtp_enc']        = in_array( $in['smtp_enc'] ?? '', array( 'tls', 'ssl', 'none' ), true ) ? $in['smtp_enc'] : 'tls';
		$out['smtp_auth']       = empty( $in['smtp_auth'] ) ? '0' : '1';
		$out['smtp_user']       = sanitize_text_field( $in['smtp_user'] ?? '' );
		// The password box is left empty on the page; empty means "keep the saved one".
		if ( isset( $in['smtp_pass'] ) && '' !== $in['smtp_pass'] ) {
			$out['smtp_pass'] = (string) $in['smtp_pass'];
		}
		if ( ! empty( $in['smtp_pass_clear'] ) ) {
			$out['smtp_pass'] = '';
		}
		$from               = sanitize_email( $in['from_email'] ?? '' );
		$out['from_email']  = is_email( $from ) ? $from : '';
		$out['from_name']   = sanitize_text_field( $in['from_name'] ?? '' );

		if ( '1' === $out['alert_on'] && '' === $out['alert_to'] ) {
			add_settings_error( self::OPTION, 'no_to', 'Add at least one valid email address for lead alerts.', 'error' );
		}
		if ( 'smtp' === $out['mode'] && '' === $out['smtp_host'] ) {
			add_settings_error( self::OPTION, 'no_host', 'Custom SMTP needs a server (host). Until it is filled in, emails are sent the WordPress way.', 'warning' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/** Applies the plugin's SMTP settings to this one email (hooked only while it is sent). */
	public static function configure_phpmailer( $mailer ) {
		$o = self::get();
		$mailer->isSMTP();
		$mailer->Host       = $o['smtp_host'];
		$mailer->Port       = (int) $o['smtp_port'];
		$mailer->SMTPSecure = 'none' === $o['smtp_enc'] ? '' : $o['smtp_enc'];
		$mailer->SMTPAutoTLS = 'none' !== $o['smtp_enc'];
		$mailer->SMTPAuth   = '1' === $o['smtp_auth'];
		if ( $mailer->SMTPAuth ) {
			$mailer->Username = $o['smtp_user'];
			$mailer->Password = $o['smtp_pass'];
		}
		$mailer->Timeout = 15;
		$from = self::from_address( $o );
		if ( '' !== $from ) {
			try {
				$mailer->setFrom( $from, '' !== $o['from_name'] ? $o['from_name'] : get_bloginfo( 'name' ), false );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}
	}

	public static function capture_error( $error ) {
		self::$last_error = is_wp_error( $error ) ? $error->get_error_message() : 'Unknown error';
	}

	/** Sender address for the chatbot's emails ('' = keep WordPress's own). */
	public static function from_address( $o = null ) {
		$o = $o ? $o : self::get();
		if ( '' !== $o['from_email'] ) {
			return $o['from_email'];
		}
		// With the plugin's SMTP the login address is the safest sender (servers often refuse others).
		return self::smtp_on( $o ) && is_email( $o['smtp_user'] ) ? $o['smtp_user'] : '';
	}

	public static function from_email_filter( $email ) {
		$from = self::from_address();
		return '' !== $from ? $from : $email;
	}

	public static function from_name_filter( $name ) {
		$o = self::get();
		return '' !== $o['from_name'] ? $o['from_name'] : $name;
	}

	/**
	 * Sends one HTML email with the chosen method.
	 *
	 * @return true|WP_Error
	 */
	public static function send( $to, $subject, $html, $reply_to = '' ) {
		$o    = self::get();
		$smtp = self::smtp_on( $o );
		self::$last_error = '';
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		if ( $smtp ) {
			add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 999 );
		}
		// WordPress checks the sender before phpmailer_init runs, so it is set here for both methods
		// (only when one is filled in; otherwise WordPress or the SMTP plugin decides).
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email_filter' ), 20 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name_filter' ), 20 );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$ok = wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $html, $headers );

		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		remove_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), 999 );
		remove_filter( 'wp_mail_from', array( __CLASS__, 'from_email_filter' ), 20 );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name_filter' ), 20 );

		if ( $ok ) {
			update_option( 'wp_ai_chatbot_email_last', array( 'ok' => 1, 'at' => current_time( 'mysql' ), 'error' => '' ), false );
			return true;
		}
		$msg = '' !== self::$last_error ? self::$last_error : 'The email could not be sent.';
		update_option( 'wp_ai_chatbot_email_last', array( 'ok' => 0, 'at' => current_time( 'mysql' ), 'error' => $msg ), false );
		return new WP_Error( 'mail_failed', $msg );
	}

	/** Plain-language next step for common email errors. */
	public static function explain_error( $msg ) {
		$m = strtolower( (string) $msg );
		if ( false !== strpos( $m, 'authenticate' ) || false !== strpos( $m, 'username and password' ) || false !== strpos( $m, '535' ) ) {
			return 'The server rejected the username or password. For Gmail use an App Password, not your normal password, and check the username is the full email address.';
		}
		if ( false !== strpos( $m, 'certificate' ) || false !== strpos( $m, 'peer' ) || false !== strpos( $m, 'ssl' ) || false !== strpos( $m, 'tls' ) || false !== strpos( $m, 'crypto' ) ) {
			return 'The secure connection failed, usually because the server name does not match its SSL certificate. On a VPS (HestiaCP, cPanel) use the server\'s own mail name (for example mail.yourserver.com) as the SMTP server, or turn on SSL / Let\'s Encrypt for this mail domain in the panel.';
		}
		if ( false !== strpos( $m, 'connect' ) || false !== strpos( $m, 'timed out' ) || false !== strpos( $m, 'timeout' ) ) {
			return 'Could not reach the email server. Check the host and port. Some hosting companies block ports 465/587; try the other port with the matching encryption (465 = SSL, 587 = TLS) or ask your host.';
		}
		if ( false !== strpos( $m, 'could not instantiate mail function' ) || false !== strpos( $m, 'mail()' ) ) {
			return 'This server cannot send email by itself. Choose "Custom SMTP" below (or install an SMTP plugin) to send through a real email account.';
		}
		if ( false !== strpos( $m, 'sender' ) || false !== strpos( $m, 'from' ) ) {
			return 'The server did not accept the sender address. Set "From email" to the same address you log in with.';
		}
		return 'Check the settings below and try again.';
	}

	/* ------------------------------------------------------------------
	 * New lead
	 * ------------------------------------------------------------------ */

	/**
	 * Called after a chat form is saved. The emails go out after the visitor
	 * has their answer, so a slow email server never makes the form wait.
	 *
	 * @param array  $form    Form definition (title, fields).
	 * @param array  $values  Saved values: column => value.
	 * @param string $session Chat session id ('' if unknown).
	 * @param string $page    Page the visitor was on.
	 */
	public static function new_lead( $form, $values, $session = '', $page = '' ) {
		$o = self::get();
		if ( '1' !== $o['alert_on'] && '1' !== $o['visitor_on'] ) {
			return;
		}
		$job = function () use ( $form, $values, $session, $page ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			self::send_lead_emails( $form, $values, $session, $page );
		};
		if ( did_action( 'shutdown' ) ) {
			$job();
		} else {
			add_action( 'shutdown', $job );
		}
	}

	/** Field label => value, in the form's order. */
	public static function lead_fields( $form, $values ) {
		$pairs = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			if ( empty( $field['label'] ) ) {
				continue;
			}
			$col = sanitize_key( str_replace( ' ', '_', strtolower( $field['label'] ) ) );
			if ( isset( $values[ $col ] ) && '' !== trim( (string) $values[ $col ] ) ) {
				$pairs[ $field['label'] ] = (string) $values[ $col ];
			}
		}
		return $pairs;
	}

	/** Visitor's email, name and phone, found by value and label. */
	public static function contact_of( $pairs ) {
		$c = array( 'email' => '', 'name' => '', 'phone' => '' );
		foreach ( $pairs as $label => $value ) {
			$l = strtolower( $label );
			if ( '' === $c['email'] && is_email( trim( $value ) ) ) {
				$c['email'] = trim( $value );
			} elseif ( '' === $c['name'] && preg_match( '/\bname\b|naam/', $l ) ) {
				$c['name'] = $value;
			} elseif ( '' === $c['phone'] && ( preg_match( '/phone|mobile|whatsapp|contact|number|cell/', $l ) || preg_match( '/^\+?[\d\s\-()]{8,}$/', $value ) ) && preg_match( '/\d{7,}/', preg_replace( '/\D/', '', $value ) ) ) {
				$c['phone'] = $value;
			}
		}
		return $c;
	}

	private static function fill( $text, $vars ) {
		return strtr( (string) $text, $vars );
	}

	/** Last messages of the visitor's chat, oldest first. */
	public static function recent_chat( $session, $limit = 10 ) {
		global $wpdb;
		if ( '' === $session ) {
			return array();
		}
		$table = $wpdb->prefix . 'ai_chat_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_message, bot_reply FROM {$table} WHERE session_id = %s AND user_message NOT LIKE %s AND user_message NOT LIKE %s AND user_message NOT LIKE %s AND user_message NOT LIKE %s ORDER BY id DESC LIMIT %d", $session, $wpdb->esc_like( '[FORM_SHOWN]:' ) . '%', $wpdb->esc_like( '[SYSTEM_ACTION]:' ) . '%', $wpdb->esc_like( '[AGENT]:' ) . '%', $wpdb->esc_like( '[LIVE]:' ) . '%', $limit ) );
		return array_reverse( (array) $rows );
	}

	public static function send_lead_emails( $form, $values, $session = '', $page = '' ) {
		$o       = self::get();
		$pairs   = self::lead_fields( $form, $values );
		$contact = self::contact_of( $pairs );
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$vars    = array(
			'{name}' => '' !== $contact['name'] ? $contact['name'] : 'there',
			'{site}' => $site,
			'{form}' => (string) ( $form['title'] ?? 'Chat form' ),
		);

		if ( '1' === $o['alert_on'] && '' !== $o['alert_to'] ) {
			$subject_vars            = $vars;
			$subject_vars['{name}']  = '' !== $contact['name'] ? $contact['name'] : ( '' !== $contact['email'] ? $contact['email'] : 'New visitor' );
			$subject = self::fill( '' !== $o['alert_subject'] ? $o['alert_subject'] : self::DEFAULT_ALERT_SUBJECT, $subject_vars );
			$html    = self::alert_html( $form, $pairs, $contact, '1' === $o['alert_chat'] ? self::recent_chat( $session ) : array(), $page );
			$result  = self::send( $o['alert_to'], $subject, $html, $contact['email'] );
			if ( is_wp_error( $result ) && class_exists( 'WP_AI_Chatbot_AI' ) ) {
				WP_AI_Chatbot_AI::record_error( 'Lead alert email', $result );
			}
		}

		if ( '1' === $o['visitor_on'] && '' !== $contact['email'] ) {
			$subject = self::fill( '' !== $o['visitor_subject'] ? $o['visitor_subject'] : self::DEFAULT_VISITOR_SUBJECT, $vars );
			$body    = self::fill( '' !== $o['visitor_message'] ? $o['visitor_message'] : self::DEFAULT_VISITOR_MESSAGE, $vars );
			$html    = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111;">' . nl2br( esc_html( $body ) ) . '</div>';
			$reply   = explode( ',', (string) $o['alert_to'] )[0];
			$result  = self::send( $contact['email'], $subject, $html, trim( $reply ) );
			if ( is_wp_error( $result ) && class_exists( 'WP_AI_Chatbot_AI' ) ) {
				WP_AI_Chatbot_AI::record_error( 'Visitor confirmation email', $result );
			}
		}
	}

	/** The lead alert email (simple inline-styled HTML that works in every mail app). */
	public static function alert_html( $form, $pairs, $contact, $chat, $page = '' ) {
		$accent = '#fd9400';
		$rows   = '';
		foreach ( $pairs as $label => $value ) {
			$rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#666;width:35%;vertical-align:top;">' . esc_html( $label ) . '</td><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html( $value ) . '</td></tr>';
		}
		$buttons = '';
		if ( '' !== $contact['phone'] ) {
			$tel      = preg_replace( '/[^\d+]/', '', $contact['phone'] );
			$digits   = preg_replace( '/^00/', '', preg_replace( '/\D/', '', $contact['phone'] ) );
			// A local number ("0300 1234567") needs the country code for WhatsApp and calls from abroad.
			$cc = self::get()['country_code'];
			if ( '' !== $cc && '+' !== substr( trim( $contact['phone'] ), 0, 1 ) && preg_match( '/^0[1-9]/', $digits ) ) {
				$digits = $cc . substr( $digits, 1 );
				$tel    = '+' . $digits;
			}
			$buttons .= '<a href="tel:' . esc_attr( $tel ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 16px;background:#111;color:#fff;border-radius:6px;text-decoration:none;">Call</a>';
			$buttons .= '<a href="https://wa.me/' . esc_attr( $digits ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 16px;background:#25D366;color:#fff;border-radius:6px;text-decoration:none;">WhatsApp</a>';
		}
		if ( '' !== $contact['email'] ) {
			$buttons .= '<a href="mailto:' . esc_attr( $contact['email'] ) . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 16px;background:' . $accent . ';color:#fff;border-radius:6px;text-decoration:none;">Reply by email</a>';
		}
		$chat_html = '';
		foreach ( $chat as $row ) {
			$chat_html .= '<p style="margin:0 0 6px;"><strong>Visitor:</strong> ' . esc_html( wp_trim_words( (string) $row->user_message, 60 ) ) . '</p>';
			$chat_html .= '<p style="margin:0 0 12px;color:#555;"><strong>Bot:</strong> ' . esc_html( wp_trim_words( (string) $row->bot_reply, 60 ) ) . '</p>';
		}
		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;max-width:600px;">';
		$html .= '<div style="border-top:4px solid ' . $accent . ';padding:16px 0 8px;"><h2 style="margin:0 0 4px;font-size:20px;">New lead from your chatbot</h2>';
		$html .= '<p style="margin:0;color:#666;">' . esc_html( (string) ( $form['title'] ?? 'Chat form' ) ) . ' &middot; ' . esc_html( wp_date( 'j M Y, g:i a' ) ) . '</p></div>';
		$html .= '<p style="margin:12px 0;color:#b45309;"><strong>Reply soon:</strong> leads contacted within the first hour are far more likely to buy.</p>';
		$html .= '<table style="width:100%;border-collapse:collapse;margin:8px 0 16px;">' . $rows . '</table>';
		$html .= '' !== $buttons ? '<div style="margin:0 0 16px;">' . $buttons . '</div>' : '';
		if ( '' !== $chat_html ) {
			$html .= '<h3 style="font-size:15px;margin:16px 0 8px;">What they asked the chatbot</h3><div style="background:#f7f7f8;border-radius:8px;padding:12px 14px;">' . $chat_html . '</div>';
		}
		if ( '' !== $page ) {
			$html .= '<p style="margin:16px 0 0;color:#666;">Page: <a href="' . esc_url( $page ) . '">' . esc_html( $page ) . '</a></p>';
		}
		$html .= '<p style="margin:16px 0 0;"><a href="' . esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-leads' ) ) . '">See all leads</a></p>';
		$html .= '<p style="margin:24px 0 0;color:#999;font-size:12px;">Sent by AK Deep Knowledge AI Chatbot. Change these alerts under AI Chatbot &gt; Lead Alerts &amp; Email.</p></div>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------ */

	/** Name of an active SMTP plugin, or ''. */
	public static function detected_smtp_plugin() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::SMTP_PLUGINS as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				return $name;
			}
		}
		return '';
	}

	public function ajax_test_email() {
		check_ajax_referer( 'wp_ai_chatbot_test_email', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$o  = self::get();
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$to = explode( ',', (string) $o['alert_to'] )[0];
		}
		$to = trim( $to );
		if ( ! is_email( $to ) ) {
			wp_send_json_error( 'Enter an email address to send the test to.' );
		}
		$sample = array(
			'title'  => 'Test form',
			'fields' => array( array( 'label' => 'Name' ), array( 'label' => 'Phone' ), array( 'label' => 'Email' ) ),
		);
		$html = self::alert_html( $sample, array( 'Name' => 'Test Visitor', 'Phone' => '+92 300 1234567', 'Email' => 'visitor@example.com' ), array( 'name' => 'Test Visitor', 'phone' => '+92 300 1234567', 'email' => 'visitor@example.com' ), array() );
		$html = '<p style="font-family:Arial,sans-serif;background:#ecfdf5;padding:10px 12px;border-radius:6px;">This is a test. If you can read this, lead alerts will reach this inbox. Below is what a real alert looks like.</p>' . $html;
		$res  = self::send( $to, 'Test: chatbot lead alerts work', $html );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( 'Not sent: ' . $res->get_error_message() . ' → ' . self::explain_error( $res->get_error_message() ) );
		}
		wp_send_json_success( 'Sent to ' . $to . ' using ' . ( self::smtp_on( $o ) ? 'the plugin\'s SMTP (' . $o['smtp_host'] . ')' : 'WordPress default sending' ) . '. Check the inbox and the spam folder.' );
	}

	public function display_page() {
		$o        = self::get();
		$detected = self::detected_smtp_plugin();
		$last     = get_option( 'wp_ai_chatbot_email_last' );
		$name     = self::OPTION;
		?>
		<div class="wrap akdk-wrap">
			<h1 class="akdk-page-title">Lead Alerts &amp; Email</h1>
			<p class="akdk-page-intro">Get an email the moment a visitor fills in a chat form, so you can reply while they are still interested.</p>
			<?php settings_errors(); ?>

			<div class="akdk-two-col">
				<form method="post" action="options.php" class="akdk-settings-form">
					<?php settings_fields( 'wp_ai_chatbot_email_group' ); ?>

					<h2>1. New lead alert</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Send alerts</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_on]" value="1" <?php checked( $o['alert_on'], '1' ); ?>> Email me when a visitor submits a chat form</label>
						</td></tr>
						<tr><th scope="row"><label for="akdk-alert-to">Send to</label></th><td>
							<input type="text" id="akdk-alert-to" class="large-text" name="<?php echo esc_attr( $name ); ?>[alert_to]" value="<?php echo esc_attr( $o['alert_to'] ); ?>" placeholder="you@example.com, sales@example.com">
							<p class="description">One or more addresses, separated by commas. Add your client's email here too.</p>
						</td></tr>
						<tr><th scope="row"><label for="akdk-alert-subject">Subject</label></th><td>
							<input type="text" id="akdk-alert-subject" class="large-text" name="<?php echo esc_attr( $name ); ?>[alert_subject]" value="<?php echo esc_attr( $o['alert_subject'] ); ?>">
							<p class="description">You can use {name}, {form} and {site}.</p>
						</td></tr>
						<tr><th scope="row"><label for="akdk-cc">Country code</label></th><td>
							+<input type="text" id="akdk-cc" style="width:70px;" name="<?php echo esc_attr( $name ); ?>[country_code]" value="<?php echo esc_attr( $o['country_code'] ); ?>" placeholder="92">
							<p class="description">For numbers visitors type without it (0300 1234567), so the WhatsApp and Call buttons in the alert work. 92 = Pakistan, 971 = UAE, 966 = Saudi Arabia, 44 = UK, 1 = USA/Canada.</p>
						</td></tr>
						<tr><th scope="row">Include the chat</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[alert_chat]" value="1" <?php checked( $o['alert_chat'], '1' ); ?>> Add the visitor's last questions to the bot, so you know what they want before you call</label>
						</td></tr>
					</table>

					<h2>2. Thank-you email to the visitor (optional)</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Send it</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[visitor_on]" value="1" <?php checked( $o['visitor_on'], '1' ); ?>> Email the visitor a confirmation (only when the form has an email field)</label>
						</td></tr>
						<tr><th scope="row"><label for="akdk-v-subject">Subject</label></th><td>
							<input type="text" id="akdk-v-subject" class="large-text" name="<?php echo esc_attr( $name ); ?>[visitor_subject]" value="<?php echo esc_attr( $o['visitor_subject'] ); ?>">
						</td></tr>
						<tr><th scope="row"><label for="akdk-v-message">Message</label></th><td>
							<textarea id="akdk-v-message" class="large-text" rows="7" name="<?php echo esc_attr( $name ); ?>[visitor_message]"><?php echo esc_textarea( $o['visitor_message'] ); ?></textarea>
							<p class="description">You can use {name}, {form} and {site}. When the visitor replies, the reply goes to the first "Send to" address.</p>
						</td></tr>
					</table>

					<h2>3. How emails are sent</h2>
					<?php if ( '' !== $detected ) : ?>
						<div class="notice notice-success inline"><p><strong><?php echo esc_html( $detected ); ?></strong> is active on this site, so WordPress already sends email through it. Keep <em>WordPress default</em> below.</p></div>
					<?php else : ?>
						<div class="notice notice-info inline"><p>No SMTP plugin found. WordPress will try to send email straight from your hosting server, which often ends up in spam or is not delivered at all. Send a test below; if it does not arrive, choose <em>Custom SMTP</em> and enter your email account's details.</p></div>
					<?php endif; ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Sending method</th><td>
							<label style="display:block;margin-bottom:6px;"><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="wordpress" <?php checked( $o['mode'], 'wordpress' ); ?>> <strong>WordPress default</strong> (uses your SMTP plugin if you have one)</label>
							<label style="display:block;"><input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="smtp" <?php checked( $o['mode'], 'smtp' ); ?>> <strong>Custom SMTP</strong> (this plugin sends through your email account; only the chatbot's emails, the rest of the site is not changed)</label>
						</td></tr>
						<tr class="akdk-smtp-row"><th scope="row"><label for="akdk-preset">Email provider</label></th><td>
							<select id="akdk-preset">
								<option value="">Choose to fill in the settings...</option>
								<?php foreach ( self::PRESETS as $key => $p ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" data-host="<?php echo esc_attr( $p['host'] ); ?>" data-port="<?php echo esc_attr( $p['port'] ); ?>" data-enc="<?php echo esc_attr( $p['enc'] ); ?>" data-tip="<?php echo esc_attr( $p['tip'] ); ?>"><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
								<option value="other">Other (enter the details yourself)</option>
							</select>
							<p class="description" id="akdk-preset-tip"></p>
						</td></tr>
						<tr class="akdk-smtp-row"><th scope="row"><label for="akdk-host">SMTP server</label></th><td>
							<input type="text" id="akdk-host" class="regular-text" name="<?php echo esc_attr( $name ); ?>[smtp_host]" value="<?php echo esc_attr( $o['smtp_host'] ); ?>" placeholder="smtp.gmail.com">
						</td></tr>
						<tr class="akdk-smtp-row"><th scope="row"><label for="akdk-port">Port and encryption</label></th><td>
							<input type="number" id="akdk-port" style="width:100px;" name="<?php echo esc_attr( $name ); ?>[smtp_port]" value="<?php echo esc_attr( $o['smtp_port'] ); ?>">
							<select id="akdk-enc" name="<?php echo esc_attr( $name ); ?>[smtp_enc]">
								<option value="tls" <?php selected( $o['smtp_enc'], 'tls' ); ?>>TLS (usually port 587)</option>
								<option value="ssl" <?php selected( $o['smtp_enc'], 'ssl' ); ?>>SSL (usually port 465)</option>
								<option value="none" <?php selected( $o['smtp_enc'], 'none' ); ?>>None (not recommended)</option>
							</select>
						</td></tr>
						<tr class="akdk-smtp-row"><th scope="row">Login</th><td>
							<label style="display:block;margin-bottom:8px;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[smtp_auth]" value="1" <?php checked( $o['smtp_auth'], '1' ); ?>> The server needs a username and password (almost always)</label>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[smtp_user]" value="<?php echo esc_attr( $o['smtp_user'] ); ?>" placeholder="Username (usually your full email address)" autocomplete="off"><br><br>
							<input type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>[smtp_pass]" value="" placeholder="<?php echo '' !== $o['smtp_pass'] ? esc_attr( 'Saved. Leave empty to keep it' ) : esc_attr( 'Password or app password' ); ?>" autocomplete="new-password">
							<?php if ( '' !== $o['smtp_pass'] ) : ?>
								<label style="margin-left:8px;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[smtp_pass_clear]" value="1"> Remove saved password</label>
							<?php endif; ?>
							<p class="description">The password is stored in your WordPress database, like any SMTP plugin does. Use an app password where your provider offers one.</p>
						</td></tr>
						<tr><th scope="row"><label for="akdk-from">Sender</label></th><td>
							<input type="email" id="akdk-from" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from_email]" value="<?php echo esc_attr( $o['from_email'] ); ?>" placeholder="From email (optional)"><br><br>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from_name]" value="<?php echo esc_attr( $o['from_name'] ); ?>" placeholder="From name, e.g. <?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							<p class="description">With Custom SMTP, use the same address you log in with, or the server may refuse it. Leave empty to keep the default.</p>
						</td></tr>
					</table>
					<div class="akdk-savebar"><?php submit_button( 'Save settings', 'primary', 'submit', false ); ?></div>
				</form>

				<aside class="akdk-side">
					<div class="akdk-card">
						<h2>Send a test email</h2>
						<p>Save first, then send a sample lead alert to check that emails arrive.</p>
						<p><input type="email" id="akdk-test-to" class="regular-text" style="width:100%;" value="<?php echo esc_attr( trim( explode( ',', (string) $o['alert_to'] )[0] ) ); ?>"></p>
						<p><button type="button" class="button button-primary" id="akdk-test-email">Send test email</button> <span class="spinner" id="akdk-test-spinner" style="float:none;"></span></p>
						<div id="akdk-test-result"></div>
					</div>
					<div class="akdk-card akdk-card-muted">
						<h2>Status</h2>
						<p>Sending: <strong><?php echo self::smtp_on( $o ) ? esc_html( 'Custom SMTP (' . $o['smtp_host'] . ')' ) : esc_html( 'WordPress default' . ( '' !== $detected ? ' via ' . $detected : '' ) ); ?></strong></p>
						<?php if ( is_array( $last ) && ! empty( $last['at'] ) ) : ?>
							<p>Last email: <?php echo $last['ok'] ? '<span style="color:#15803d;">sent</span>' : '<span style="color:#b91c1c;">failed</span>'; ?> on <?php echo esc_html( $last['at'] ); ?></p>
							<?php if ( ! $last['ok'] ) : ?><p style="color:#b91c1c;"><?php echo esc_html( $last['error'] ); ?></p><?php endif; ?>
						<?php endif; ?>
					</div>
					<div class="akdk-card akdk-card-muted">
						<h2>Which method?</h2>
						<p><strong>Already have WP Mail SMTP, FluentSMTP or similar?</strong> Keep WordPress default.</p>
						<p><strong>No SMTP plugin?</strong> Use Custom SMTP with a Gmail app password or your hosting email. Email straight from the server often lands in spam.</p>
					</div>
				</aside>
			</div>

			<script>
			jQuery(function ($) {
				var $mode = $('input[name="<?php echo esc_js( $name ); ?>[mode]"]');
				function syncMode() { $('.akdk-smtp-row').toggle($mode.filter(':checked').val() === 'smtp'); }
				$mode.on('change', syncMode); syncMode();

				$('#akdk-preset').on('change', function () {
					var $o = $(this).find(':selected');
					$('#akdk-preset-tip').text($o.data('tip') || '');
					if ($o.data('host')) {
						$('#akdk-host').val($o.data('host'));
						$('#akdk-port').val($o.data('port'));
						$('#akdk-enc').val($o.data('enc'));
					}
				});
				$('#akdk-enc').on('change', function () {
					var v = $(this).val(), $p = $('#akdk-port');
					if (v === 'ssl' && $p.val() === '587') $p.val('465');
					if (v === 'tls' && $p.val() === '465') $p.val('587');
				});

				$('#akdk-test-email').on('click', function () {
					var $btn = $(this), $out = $('#akdk-test-result');
					$btn.prop('disabled', true); $('#akdk-test-spinner').addClass('is-active'); $out.empty();
					$.post(ajaxurl, {
						action: 'wp_ai_chatbot_test_email',
						security: '<?php echo esc_js( wp_create_nonce( 'wp_ai_chatbot_test_email' ) ); ?>',
						to: $('#akdk-test-to').val()
					}).done(function (res) {
						var ok = res && res.success, msg = (res && typeof res.data === 'string') ? res.data : 'Unexpected response.';
						$out.append($('<div class="notice inline"><p></p></div>').addClass(ok ? 'notice-success' : 'notice-error').find('p').text(msg).end());
					}).fail(function () {
						$out.append($('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>'));
					}).always(function () { $btn.prop('disabled', false); $('#akdk-test-spinner').removeClass('is-active'); });
				});
			});
			</script>
		</div>
		<?php
	}
}
