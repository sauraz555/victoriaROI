<?php
/**
 * Plugin Name: Interlace VPT Leads
 * Description: Receives leads from the Victorian Points Test checker and emails them to sales via Resend.
 * Version:     1.0.0
 * Author:      Interlace Studies
 *
 * Setup (wp-config.php, above "That's all, stop editing!"):
 *   define( 'INTERLACE_RESEND_API_KEY', 're_xxxxxxxxxxxxxxxx' );
 * Optional:
 *   define( 'INTERLACE_VPT_TO',   'sales@interlace.com.au' );                 // lead inbox
 *   define( 'INTERLACE_VPT_FROM', 'Interlace VPT Checker <leads@interlace.com.au>' );
 *   define( 'INTERLACE_VPT_GHL_WEBHOOK', 'https://services.leadconnectorhq.com/hooks/...' ); // also push to GoHighLevel
 *
 * Endpoint: POST /wp-json/interlace/v1/vpt-lead
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Interlace_VPT_Leads {

	const ROUTE_NS   = 'interlace/v1';
	const ROUTE      = '/vpt-lead';
	const RATE_LIMIT = 5;   // submissions per IP per hour
	const PROGRAM    = 'Victorian Points Test 2026–27 (published 13 Oct 2026)';

	/* ---- Victorian Points Test tables (Live in Melbourne, 13 Oct 2026) ---- */
	private static $age = array(
		18 => 265, 19 => 282, 20 => 300, 21 => 300, 22 => 300, 23 => 300, 24 => 300, 25 => 300, 26 => 300, 27 => 300,
		28 => 300, 29 => 300, 30 => 286, 31 => 270, 32 => 256, 33 => 240, 34 => 226, 35 => 210, 36 => 196, 37 => 180,
		38 => 166, 39 => 150, 40 => 136, 41 => 106, 42 => 76, 43 => 46, 44 => 16,
	);

	private static $earn = array(
		array( 'Under $80,000', 0 ), array( '$80,000 – $84,999', 8 ), array( '$85,000 – $89,999', 16 ),
		array( '$90,000 – $94,999', 24 ), array( '$95,000 – $99,999', 32 ), array( '$100,000 – $104,999', 39 ),
		array( '$105,000 – $109,999', 47 ), array( '$110,000 – $114,999', 55 ), array( '$115,000 – $119,999', 63 ),
		array( '$120,000 – $124,999', 71 ), array( '$125,000 – $129,999', 79 ), array( '$130,000 – $134,999', 87 ),
		array( '$135,000 – $139,999', 95 ), array( '$140,000 – $144,999', 103 ), array( '$145,000 – $149,999', 111 ),
		array( '$150,000 – $154,999', 118 ), array( '$155,000 – $159,999', 126 ), array( '$160,000 – $164,999', 134 ),
		array( '$165,000 – $169,999', 142 ), array( '$170,000 and over', 150 ),
	);

	/** value => [label, points] for the option-based questions. */
	private static function options() {
		return array(
			'visa'    => array(
				'190' => array( 'Subclass 190 (Skilled Nominated)', 0 ),
				'491' => array( 'Subclass 491 (Skilled Work Regional)', 0 ),
			),
			'eng'     => array(
				'c' => array( 'Competent', 0 ),
				'p' => array( 'Proficient', 100 ),
				's' => array( 'Superior', 200 ),
			),
			'exp'     => array(
				'0'   => array( '0 years', 0 ),
				'1'   => array( '1 year', 20 ),
				'1-3' => array( '1–3 years', 40 ),
				'3-5' => array( '3–5 years', 60 ),
				'6-8' => array( '6–8 years', 80 ),
				'8+'  => array( 'More than 8 years', 100 ),
			),
			'edu'     => array(
				'o' => array( 'Other (diploma / trade)', 25 ),
				'b' => array( 'Bachelor or Honours', 50 ),
				'm' => array( 'Masters', 75 ),
				'd' => array( 'PhD', 100 ),
			),
			'skill'   => array(
				'1'  => array( 'Skill level 1', 50 ),
				'23' => array( 'Skill level 2 or 3', 0 ),
				'ns' => array( 'Not sure', 0 ),
			),
			'ind'     => array(
				'health' => array( 'Healthcare', 0 ),
				'social' => array( 'Social assistance', 0 ),
				'edu'    => array( 'Education – Teachers', 0 ),
				'ece'    => array( 'Education – Early Childhood', 0 ),
				'cons'   => array( 'Construction', 0 ),
				'none'   => array( 'Not a priority industry / not sure', 0 ),
			),
			'partner' => array(
				'single' => array( 'Single, or partner is an Australian citizen/PR', 0 ),
				'yes'    => array( 'Partner qualifies and is in a priority occupation', 50 ),
				'nopri'  => array( 'Partner qualifies but NOT in a priority occupation', 0 ),
				'no'     => array( 'Partner does not qualify for partner points', 0 ),
			),
		);
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // public form; protected by honeypot + rate limit.
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public static function handle( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		if ( ! is_array( $p ) ) {
			return self::fail( 'Invalid request.', 400 );
		}

		// Honeypot: real visitors never fill this hidden field. Pretend success for bots.
		if ( ! empty( $p['website'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		if ( ! self::rate_ok() ) {
			return self::fail( 'Too many submissions. Please try again later or message us on WhatsApp.', 429 );
		}

		// unlock = score-reveal form (name + mobile, optional email); callback / notify kept for other forms.
		$type = isset( $p['type'] ) ? sanitize_key( $p['type'] ) : '';
		if ( ! in_array( $type, array( 'unlock', 'callback', 'notify' ), true ) ) {
			return self::fail( 'Invalid request.', 400 );
		}

		$name   = isset( $p['first_name'] ) ? sanitize_text_field( wp_unslash( $p['first_name'] ) ) : '';
		$phone  = isset( $p['phone'] ) ? sanitize_text_field( wp_unslash( $p['phone'] ) ) : '';
		$email  = isset( $p['email'] ) ? sanitize_email( wp_unslash( $p['email'] ) ) : '';
		$notify = ! empty( $p['notify'] );
		$name   = mb_substr( $name, 0, 60 );

		if ( 'notify' === $type ) {
			if ( ! is_email( $email ) ) {
				return self::fail( 'Please enter a valid email address.', 422 );
			}
		} else {
			if ( '' === $name ) {
				return self::fail( 'Please enter your first name.', 422 );
			}
			if ( ! preg_match( '/^\+?[0-9 ()\-]{8,20}$/', $phone ) ) {
				return self::fail( 'Please enter a valid mobile number, including country code.', 422 );
			}
			if ( '' !== $email && ! is_email( $email ) ) {
				return self::fail( 'Please check your email address, or leave it blank.', 422 );
			}
		}

		$result = self::score( isset( $p['answers'] ) && is_array( $p['answers'] ) ? $p['answers'] : array() );
		$page   = isset( $p['page'] ) ? esc_url_raw( $p['page'] ) : '';

		$lead = array(
			'notify' => $notify || 'notify' === $type,
			'type'   => $type,
			'name'   => $name,
			'phone'  => $phone,
			'email'  => $email,
			'page'   => $page,
			'result' => $result,
			'tags'   => array_merge( self::tags( $result ), ( $notify || 'notify' === $type ) ? array( 'vic-opening-alert' ) : array() ),
			'time'   => wp_date( 'D j M Y, g:i a T', null, new DateTimeZone( 'Australia/Brisbane' ) ),
		);

		// 1. Notify sales.
		$sent = self::send_email(
			defined( 'INTERLACE_VPT_TO' ) ? INTERLACE_VPT_TO : 'sales@interlace.com.au',
			self::sales_subject( $lead ),
			self::sales_html( $lead ),
			self::sales_text( $lead ),
			$email ? $email : null
		);

		if ( ! $sent ) {
			return self::fail( 'Sorry, something went wrong. Please message us on WhatsApp instead.', 502 );
		}

		// 2. Send the lead their score (only when we have their email).
		if ( $email ) {
			self::send_email( $email, 'Your Victorian Points Test score: ' . $result['total'] . '/950', self::lead_html( $lead ), self::lead_text( $lead ), null );
		}

		// 3. Optional: push to GoHighLevel inbound webhook (non-blocking).
		if ( defined( 'INTERLACE_VPT_GHL_WEBHOOK' ) && INTERLACE_VPT_GHL_WEBHOOK ) {
			wp_remote_post(
				INTERLACE_VPT_GHL_WEBHOOK,
				array(
					'blocking' => false,
					'timeout'  => 5,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( self::ghl_payload( $lead ) ),
				)
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'total' => $result['total'] ), 200 );
	}

	/* ---- Scoring (server side, never trusts the browser's total) ---- */

	private static function score( array $a ) {
		$opts  = self::options();
		$rows  = array();
		$total = 0;

		$pick = function ( $key ) use ( $a, $opts ) {
			$v = isset( $a[ $key ] ) ? (string) $a[ $key ] : '';
			return isset( $opts[ $key ][ $v ] ) ? array( $v, $opts[ $key ][ $v ][0], $opts[ $key ][ $v ][1] ) : array( '', 'Not answered', 0 );
		};

		// Age.
		$age     = isset( $a['age'] ) ? (int) $a['age'] : 0;
		$age_pts = isset( self::$age[ $age ] ) ? self::$age[ $age ] : 0;
		$age_lbl = $age >= 45 ? '45 or older (not eligible)' : ( $age ? $age . ' years' : 'Not answered' );

		// Earnings.
		$earn_key = isset( $a['earn'] ) ? (string) $a['earn'] : '';
		if ( 'none' === $earn_key ) {
			$earn_lbl = 'Not working in skilled employment in Victoria';
			$earn_pts = 0;
		} elseif ( preg_match( '/^e(\d{1,2})$/', $earn_key, $m ) && isset( self::$earn[ (int) $m[1] ] ) ) {
			$earn_lbl = self::$earn[ (int) $m[1] ][0];
			$earn_pts = self::$earn[ (int) $m[1] ][1];
		} else {
			$earn_lbl = 'Not answered';
			$earn_pts = 0;
		}

		$visa    = $pick( 'visa' );
		$eng     = $pick( 'eng' );
		$exp     = $pick( 'exp' );
		$edu     = $pick( 'edu' );
		$skill   = $pick( 'skill' );
		$ind     = $pick( 'ind' );
		$partner = $pick( 'partner' );

		$rows[] = array( 'Visa subclass', $visa[1], null, null );
		$rows[] = array( 'Age', $age_lbl, $age_pts, 300 );
		$rows[] = array( 'English', $eng[1], $eng[2], 200 );
		$rows[] = array( 'Experience', $exp[1], $exp[2], 100 );
		$rows[] = array( 'Education', $edu[1], $edu[2], 100 );
		$rows[] = array( 'Occupation skill level', $skill[1], $skill[2], 50 );
		$rows[] = array( 'Priority industry', $ind[1], null, null );
		$rows[] = array( 'Partner', $partner[1], $partner[2], 50 );
		$rows[] = array( 'Victorian earnings', $earn_lbl, $earn_pts, 150 );

		foreach ( $rows as $r ) {
			$total += (int) $r[2];
		}

		return array(
			'total'    => $total,
			'rows'     => $rows,
			'visa'     => $visa[0],
			'age'      => $age,
			'eng'      => $eng[0],
			'ind'      => $ind[0],
			'industry' => $ind[1],
			'onshore'  => ( $earn_pts > 0 || preg_match( '/^e\d/', $earn_key ) ),
		);
	}

	private static function tags( array $r ) {
		$t   = array( 'vpt-checker' );
		$t[] = $r['total'] >= 700 ? 'vpt-700plus' : ( $r['total'] >= 550 ? 'vpt-550-699' : ( $r['total'] >= 400 ? 'vpt-400-549' : 'vpt-under-400' ) );
		$t[] = ( $r['ind'] && 'none' !== $r['ind'] ) ? 'vic-priority-' . $r['ind'] : 'vic-non-priority';
		$t[] = $r['onshore'] ? 'vic-onshore' : 'vic-offshore-or-interstate';
		if ( $r['visa'] ) {
			$t[] = 'visa-' . $r['visa'];
		}
		if ( $r['age'] >= 45 ) {
			$t[] = 'age-ineligible';
		}
		return $t;
	}

	/** Hot = worth a same-day call. */
	private static function is_hot( array $r ) {
		return $r['age'] < 45 && ( $r['total'] >= 600 || ( $r['ind'] && 'none' !== $r['ind'] && $r['onshore'] ) );
	}

	/* ---- Email content ---- */

	private static function kind( array $l ) {
		$k = array( 'unlock' => 'Unlocked score', 'callback' => 'Callback request', 'notify' => 'Notify when Victoria opens' );
		return $k[ $l['type'] ];
	}

	private static function sales_subject( array $l ) {
		$who  = $l['name'] ? $l['name'] : $l['email'];
		$kind = self::kind( $l );
		$hot  = self::is_hot( $l['result'] ) ? '🔥 ' : '';
		return sprintf( '%sVPT lead: %s – %d/950 (%s)', $hot, $who, $l['result']['total'], $kind );
	}

	private static function breakdown_html( array $r ) {
		$h = '';
		foreach ( $r['rows'] as $row ) {
			$pts = null === $row[2] ? '—' : (int) $row[2] . ' / ' . (int) $row[3];
			$h  .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #DCE6F2;color:#212529">' . esc_html( $row[0] ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #DCE6F2;color:#666">' . esc_html( $row[1] ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #DCE6F2;color:#3267AD;font-weight:600;text-align:right;white-space:nowrap">' . esc_html( $pts ) . '</td></tr>';
		}
		$h .= '<tr><td colspan="2" style="padding:10px 12px;font-weight:700;color:#212529">Total VPT score</td>'
			. '<td style="padding:10px 12px;font-weight:700;color:#3267AD;text-align:right">' . (int) $r['total'] . ' / 950</td></tr>';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;border:1px solid #DCE6F2;border-radius:8px">' . $h . '</table>';
	}

	private static function wrap( $inner ) {
		return '<!doctype html><html><body style="margin:0;background:#EAF2FA;font-family:Arial,Helvetica,sans-serif">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px">'
			. '<tr><td style="background:#3267AD;color:#fff;padding:18px 24px;border-radius:12px 12px 0 0;font-size:18px;font-weight:700">Interlace Studies</td></tr>'
			. '<tr><td style="padding:24px">' . $inner . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	private static function sales_html( array $l ) {
		$r       = $l['result'];
		$hot     = self::is_hot( $r );
		$kind    = self::kind( $l ) . ( $l['notify'] ? ' · wants opening alert' : '' );
		$contact = '';
		if ( $l['name'] ) {
			$contact .= '<tr><td style="padding:4px 0;color:#666;width:120px">Name</td><td style="padding:4px 0;color:#212529;font-weight:600">' . esc_html( $l['name'] ) . '</td></tr>';
		}
		if ( $l['phone'] ) {
			$wa       = preg_replace( '/[^0-9]/', '', $l['phone'] );
			$contact .= '<tr><td style="padding:4px 0;color:#666">Mobile</td><td style="padding:4px 0;color:#212529;font-weight:600"><a href="tel:' . esc_attr( $l['phone'] ) . '">' . esc_html( $l['phone'] ) . '</a> · <a href="https://wa.me/' . esc_attr( $wa ) . '">WhatsApp</a></td></tr>';
		}
		if ( $l['email'] ) {
			$contact .= '<tr><td style="padding:4px 0;color:#666">Email</td><td style="padding:4px 0;color:#212529;font-weight:600"><a href="mailto:' . esc_attr( $l['email'] ) . '">' . esc_html( $l['email'] ) . '</a></td></tr>';
		}
		$contact .= '<tr><td style="padding:4px 0;color:#666">Request</td><td style="padding:4px 0;color:#212529">' . esc_html( $kind ) . '</td></tr>';
		$contact .= '<tr><td style="padding:4px 0;color:#666">Received</td><td style="padding:4px 0;color:#212529">' . esc_html( $l['time'] ) . '</td></tr>';

		$banner = $hot
			? '<p style="background:#FFF4E5;border-left:4px solid #F0A030;padding:10px 12px;margin:0 0 16px;color:#212529;font-size:14px"><strong>Priority lead:</strong> high score or onshore in a priority industry. Call today.</p>'
			: '';

		$inner = '<h1 style="font-family:Georgia,serif;color:#3267AD;font-size:22px;margin:0 0 6px">New Victorian Points Test lead</h1>'
			. '<p style="font-size:34px;font-weight:700;color:#3267AD;margin:0 0 16px">' . (int) $r['total'] . '<span style="font-size:16px;color:#666"> / 950</span></p>'
			. $banner
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:20px">' . $contact . '</table>'
			. '<h2 style="font-size:16px;color:#212529;margin:0 0 8px">Answers and points</h2>'
			. self::breakdown_html( $r )
			. '<p style="font-size:12px;color:#888;margin:16px 0 0">Tags: ' . esc_html( implode( ', ', $l['tags'] ) ) . '<br>Page: ' . esc_html( $l['page'] ) . '<br>Score recalculated on the server. ' . esc_html( self::PROGRAM ) . '.</p>';

		return self::wrap( $inner );
	}

	private static function sales_text( array $l ) {
		$r = $l['result'];
		$t = "New Victorian Points Test lead\n\nScore: {$r['total']}/950\n";
		$t .= $l['name'] ? "Name: {$l['name']}\n" : '';
		$t .= $l['phone'] ? "Mobile: {$l['phone']}\n" : '';
		$t .= $l['email'] ? "Email: {$l['email']}\n" : '';
		$t .= 'Request: ' . self::kind( $l ) . ( $l['notify'] ? ' (wants opening alert)' : '' ) . "\n";
		$t .= "Received: {$l['time']}\n\n";
		foreach ( $r['rows'] as $row ) {
			$t .= $row[0] . ': ' . $row[1] . ( null === $row[2] ? '' : " ({$row[2]}/{$row[3]})" ) . "\n";
		}
		$t .= "\nTags: " . implode( ', ', $l['tags'] ) . "\nPage: {$l['page']}\n";
		return $t;
	}

	private static function lead_html( array $l ) {
		$r     = $l['result'];
		$inner = '<h1 style="font-family:Georgia,serif;color:#3267AD;font-size:22px;margin:0 0 6px">Your Victorian Points Test score</h1>'
			. '<p style="font-size:34px;font-weight:700;color:#3267AD;margin:0 0 12px">' . (int) $r['total'] . '<span style="font-size:16px;color:#666"> / 950</span></p>'
			. '<p style="font-size:14px;color:#666;line-height:1.6;margin:0 0 16px">Hi' . ( $l['name'] ? ' ' . esc_html( $l['name'] ) : '' ) . ', thanks for using our checker. Here is your full breakdown. Victoria has no pass mark: Registrations of Interest are ranked against each other, and the highest scores are invited in each round. Everyone must lodge a new ROI when the 2026–27 program opens.'
			. ( $l['notify'] ? ' We\'ll email you the day it opens.' : '' ) . '</p>'
			. self::breakdown_html( $r )
			. '<p style="margin:24px 0 8px"><a href="https://interlace.com.au/book-an-appointment" style="display:inline-block;background:#3267AD;color:#fff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:999px">Book An Appointment</a></p>'
			. '<p style="font-size:14px;color:#666;margin:0">Or message us on <a href="https://wa.me/61481697403" style="color:#3267AD">WhatsApp</a> · 1300 365 423</p>'
			. '<p style="font-size:11px;color:#999;line-height:1.5;margin:20px 0 0">This is an estimate based on the Victorian Points Test published by the Victorian Government on 13 October 2026. It is general information, not migration advice, and an invitation or nomination is not guaranteed. Interlace Studies · MARN 2117623 · 2418363 · 2519108 · 1802688. Reply to this email if you no longer want to hear from us.</p>';
		return self::wrap( $inner );
	}

	private static function lead_text( array $l ) {
		$r = $l['result'];
		$t = "Your Victorian Points Test score: {$r['total']}/950\n\n";
		foreach ( $r['rows'] as $row ) {
			$t .= $row[0] . ': ' . $row[1] . ( null === $row[2] ? '' : " ({$row[2]}/{$row[3]})" ) . "\n";
		}
		$t .= "\nWe'll email you when Victoria's 2026-27 program opens.\nBook an appointment: https://interlace.com.au/book-an-appointment\nWhatsApp: https://wa.me/61481697403\n\nEstimate only, not migration advice. Reply to this email to opt out.";
		return $t;
	}

	private static function ghl_payload( array $l ) {
		$r       = $l['result'];
		$payload = array(
			'first_name'   => $l['name'],
			'phone'        => $l['phone'],
			'email'        => $l['email'],
			'request_type' => $l['type'],
			'opening_alert' => $l['notify'] ? 'yes' : 'no',
			'vpt_score'    => $r['total'],
			'tags'         => implode( ',', $l['tags'] ),
			'hot_lead'     => self::is_hot( $r ) ? 'yes' : 'no',
			'source_page'  => $l['page'],
		);
		foreach ( $r['rows'] as $row ) {
			$payload[ 'vpt_' . sanitize_key( str_replace( ' ', '_', $row[0] ) ) ] = $row[1];
		}
		return $payload;
	}

	/* ---- Plumbing ---- */

	private static function send_email( $to, $subject, $html, $text, $reply_to ) {
		if ( ! defined( 'INTERLACE_RESEND_API_KEY' ) || ! INTERLACE_RESEND_API_KEY ) {
			error_log( 'Interlace VPT Leads: INTERLACE_RESEND_API_KEY is not defined in wp-config.php' );
			return false;
		}
		$body = array(
			'from'    => defined( 'INTERLACE_VPT_FROM' ) ? INTERLACE_VPT_FROM : 'Interlace VPT Checker <leads@interlace.com.au>',
			'to'      => array( $to ),
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		);
		if ( $reply_to ) {
			$body['reply_to'] = $reply_to;
		}
		$res = wp_remote_post(
			'https://api.resend.com/emails',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . INTERLACE_RESEND_API_KEY,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			error_log( 'Interlace VPT Leads: Resend request failed: ' . $res->get_error_message() );
			return false;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'Interlace VPT Leads: Resend returned ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
			return false;
		}
		return true;
	}

	private static function rate_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'ivpt_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function fail( $msg, $status ) {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $msg ), $status );
	}
}

Interlace_VPT_Leads::init();
