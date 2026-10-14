<?php
/**
 * Plugin Name: Interlace VPT Leads
 * Description: Receives leads from the Victorian ROI / Points Test checker and emails them to sales via Resend.
 * Version:     1.1.0
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

	const ROUTE_NS    = 'interlace/v1';
	const ROUTE       = '/vpt-lead';
	const RATE_LIMIT  = 5;    // submissions per IP per hour
	const REPEAT_DAYS = 90;   // how long a phone/email is remembered for "returning lead"
	const FORM_NAME   = 'Victorian ROI form (Victorian Points Test checker)';
	const PROGRAM     = 'Victorian Points Test 2026–27 (published 13 Oct 2026)';

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

	/** Calling codes for Interlace's main markets (longest prefixes first). */
	private static $dial = array(
		'977' => 'Nepal', '855' => 'Cambodia', '880' => 'Bangladesh', '679' => 'Fiji', '971' => 'UAE', '966' => 'Saudi Arabia',
		'974' => 'Qatar', '975' => 'Bhutan', '976' => 'Mongolia', '852' => 'Hong Kong', '886' => 'Taiwan', '61' => 'Australia',
		'62' => 'Indonesia', '81' => 'Japan', '91' => 'India', '92' => 'Pakistan', '94' => 'Sri Lanka', '63' => 'Philippines',
		'84' => 'Vietnam', '86' => 'China', '64' => 'New Zealand', '44' => 'United Kingdom', '60' => 'Malaysia',
		'65' => 'Singapore', '66' => 'Thailand', '82' => 'South Korea', '27' => 'South Africa', '33' => 'France',
		'49' => 'Germany', '1' => 'USA / Canada',
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

		$answers = isset( $p['answers'] ) && is_array( $p['answers'] ) ? $p['answers'] : array();
		$result  = self::score( $answers );
		$page    = isset( $p['page'] ) ? esc_url_raw( $p['page'] ) : '';
		$meta    = self::clean_meta( isset( $p['meta'] ) && is_array( $p['meta'] ) ? $p['meta'] : array() );
		$ctx     = self::context( $phone, $email, $meta );
		$now     = new DateTime( 'now', new DateTimeZone( 'Australia/Brisbane' ) );

		$lead = array(
			'ref'    => 'VIC-' . $now->format( 'ymd' ) . '-' . strtoupper( substr( md5( $phone . $email . microtime() ), 0, 4 ) ),
			'notify' => $notify || 'notify' === $type,
			'type'   => $type,
			'name'   => $name,
			'phone'  => $phone,
			'email'  => $email,
			'page'   => $page,
			'meta'   => $meta,
			'ctx'    => $ctx,
			'result' => $result,
			'gains'  => self::gains( $answers ),
			'time'   => $now->format( 'D j M Y, g:i a' ) . ' (Brisbane)',
		);
		$lead['tags'] = self::tags( $lead );

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

		return new WP_REST_Response( array( 'ok' => true, 'total' => $result['total'], 'ref' => $lead['ref'] ), 200 );
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

		$age     = isset( $a['age'] ) ? (int) $a['age'] : 0;
		$age_pts = isset( self::$age[ $age ] ) ? self::$age[ $age ] : 0;
		$age_lbl = $age >= 45 ? '45 or older (not eligible)' : ( $age ? $age . ' years' : 'Not answered' );

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
			'eng_lbl'  => $eng[1],
			'ind'      => $ind[0],
			'industry' => $ind[1],
			'partner'  => $partner[0],
			'skill'    => $skill[0],
			'earn_lbl' => $earn_lbl,
			'working_in_vic' => (bool) preg_match( '/^e\d/', $earn_key ),
		);
	}

	/** Points the person could still add, as [points, what]. Mirrors the page. */
	private static function gains( array $a ) {
		$g    = array();
		$eng  = isset( $a['eng'] ) ? $a['eng'] : '';
		$earn = isset( $a['earn'] ) ? (string) $a['earn'] : '';
		if ( 'c' === $eng ) {
			$g[] = array( 200, 'English: Competent → Superior (Proficient alone is +100)' );
		} elseif ( 'p' === $eng ) {
			$g[] = array( 100, 'English: Proficient → Superior' );
		}
		if ( 'none' === $earn || 'e0' === $earn ) {
			$g[] = array( 150, 'Skilled job in Victoria paying $80k+ (8–150 pts)' );
		} elseif ( preg_match( '/^e(\d{1,2})$/', $earn, $m ) && isset( self::$earn[ (int) $m[1] ] ) && self::$earn[ (int) $m[1] ][1] < 150 ) {
			$g[] = array( 150 - self::$earn[ (int) $m[1] ][1], 'Higher Victorian earnings (max at $170k+)' );
		}
		if ( isset( $a['skill'] ) && 'ns' === $a['skill'] ) {
			$g[] = array( 50, 'Unsure of skill level: +50 if their occupation is ANZSCO skill level 1' );
		}
		if ( isset( $a['partner'] ) && 'no' === $a['partner'] ) {
			$g[] = array( 50, 'Partner skills assessment in a priority occupation' );
		}
		if ( isset( $a['exp'] ) && in_array( $a['exp'], array( '0', '1', '1-3', '3-5', '6-8' ), true ) ) {
			$g[] = array( 20, 'Next experience band' );
		}
		return $g;
	}

	/* ---- Passive context (nothing the visitor had to type) ---- */

	private static function clean_meta( array $m ) {
		$out  = array();
		$keys = array(
			'tz' => 64, 'lang' => 20, 'referrer' => 300, 'landing' => 300, 'utm_source' => 80, 'utm_medium' => 80,
			'utm_campaign' => 120, 'utm_term' => 120, 'utm_content' => 120, 'click_id' => 20, 'seconds' => 6,
			'screen' => 20, 'visits' => 4,
		);
		foreach ( $keys as $k => $len ) {
			if ( isset( $m[ $k ] ) && is_scalar( $m[ $k ] ) ) {
				$out[ $k ] = mb_substr( sanitize_text_field( (string) $m[ $k ] ), 0, $len );
			}
		}
		if ( isset( $out['tz'] ) && ! in_array( $out['tz'], timezone_identifiers_list(), true ) ) {
			unset( $out['tz'] );
		}
		return $out;
	}

	private static function context( $phone, $email, array $meta ) {
		$c = array();

		// Phone country from the calling code.
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		$c['phone_country'] = '';
		if ( '' !== $phone ) {
			if ( 0 === strpos( trim( $phone ), '+' ) ) {
				foreach ( self::$dial as $code => $country ) {
					if ( 0 === strpos( $digits, (string) $code ) ) {
						$c['phone_country'] = $country;
						break;
					}
				}
				if ( '' === $c['phone_country'] ) {
					$c['phone_country'] = 'Other (+' . substr( $digits, 0, 3 ) . ')';
				}
			} elseif ( 0 === strpos( $digits, '04' ) ) {
				$c['phone_country'] = 'Australia (mobile, no country code)';
			} else {
				$c['phone_country'] = 'Unknown (no country code)';
			}
		}

		// Visitor country from Cloudflare if the site sits behind it.
		$c['ip_country'] = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) ) : '';

		// Location and local time from the browser's time zone.
		$c['tz']         = isset( $meta['tz'] ) ? $meta['tz'] : '';
		$c['local_time'] = '';
		$c['in_vic']     = in_array( $c['tz'], array( 'Australia/Melbourne', 'Australia/Victoria' ), true );
		$c['in_aus']     = 0 === strpos( $c['tz'], 'Australia/' );
		if ( $c['tz'] ) {
			$lt              = new DateTime( 'now', new DateTimeZone( $c['tz'] ) );
			$c['local_time'] = $lt->format( 'g:i a D' );
			$h               = (int) $lt->format( 'G' );
			$c['call_now']   = $h >= 9 && $h < 20;
		}

		// Device from the user agent.
		$ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$c['device'] = self::device( $ua );

		// Marketing source.
		$c['source'] = self::source( $meta );

		// Returning lead (same phone or email within REPEAT_DAYS).
		$c['returning'] = 0;
		$c['last_seen'] = '';
		foreach ( array_filter( array( $digits ? 'p' . substr( $digits, -9 ) : '', $email ? 'e' . strtolower( $email ) : '' ) ) as $id ) {
			$key  = 'ivpt_seen_' . md5( $id );
			$seen = get_transient( $key );
			if ( is_array( $seen ) && $seen['n'] > $c['returning'] ) {
				$c['returning'] = (int) $seen['n'];
				$c['last_seen'] = $seen['last'];
			}
			set_transient(
				$key,
				array( 'n' => ( is_array( $seen ) ? (int) $seen['n'] : 0 ) + 1, 'last' => wp_date( 'j M Y', null, new DateTimeZone( 'Australia/Brisbane' ) ) ),
				self::REPEAT_DAYS * DAY_IN_SECONDS
			);
		}

		return $c;
	}

	private static function device( $ua ) {
		if ( '' === $ua ) {
			return 'Unknown';
		}
		$type = preg_match( '/iPad|Tablet/i', $ua ) ? 'Tablet' : ( preg_match( '/Mobi|Android|iPhone/i', $ua ) ? 'Mobile' : 'Desktop' );
		$os   = preg_match( '/iPhone|iPad/i', $ua ) ? 'iOS' : ( preg_match( '/Android/i', $ua ) ? 'Android' : ( preg_match( '/Windows/i', $ua ) ? 'Windows' : ( preg_match( '/Mac OS/i', $ua ) ? 'Mac' : ( preg_match( '/Linux/i', $ua ) ? 'Linux' : '' ) ) ) );
		$br   = preg_match( '/Edg\//', $ua ) ? 'Edge' : ( preg_match( '/FBAN|FBAV|Instagram/i', $ua ) ? 'Facebook/Instagram app' : ( preg_match( '/Chrome|CriOS/', $ua ) ? 'Chrome' : ( preg_match( '/Safari/', $ua ) ? 'Safari' : ( preg_match( '/Firefox/', $ua ) ? 'Firefox' : '' ) ) ) );
		return trim( $type . ( $os ? ' · ' . $os : '' ) . ( $br ? ' · ' . $br : '' ) );
	}

	private static function source( array $m ) {
		if ( ! empty( $m['utm_source'] ) ) {
			$s = $m['utm_source'] . ( ! empty( $m['utm_medium'] ) ? ' / ' . $m['utm_medium'] : '' );
			return $s . ( ! empty( $m['utm_campaign'] ) ? ' · campaign: ' . $m['utm_campaign'] : '' );
		}
		if ( ! empty( $m['click_id'] ) ) {
			return 'fbclid' === $m['click_id'] ? 'Facebook / Instagram ad' : ( 'gclid' === $m['click_id'] ? 'Google Ads' : 'Paid ad (' . $m['click_id'] . ')' );
		}
		$ref = isset( $m['referrer'] ) ? $m['referrer'] : '';
		if ( '' === $ref ) {
			return 'Direct (typed URL, bookmark or app)';
		}
		$host = wp_parse_url( $ref, PHP_URL_HOST );
		$host = $host ? preg_replace( '/^www\./', '', $host ) : $ref;
		if ( preg_match( '/google\./', $host ) ) {
			return 'Google search';
		}
		if ( preg_match( '/bing\.|duckduckgo|yahoo/', $host ) ) {
			return 'Search (' . $host . ')';
		}
		if ( preg_match( '/facebook|fb\.|instagram/', $host ) ) {
			return 'Facebook / Instagram';
		}
		if ( preg_match( '/tiktok/', $host ) ) {
			return 'TikTok';
		}
		if ( preg_match( '/youtube|youtu\.be/', $host ) ) {
			return 'YouTube';
		}
		if ( preg_match( '/linkedin|lnkd/', $host ) ) {
			return 'LinkedIn';
		}
		if ( preg_match( '/interlace\.com\.au$/', $host ) ) {
			return 'Interlace website (' . wp_parse_url( $ref, PHP_URL_PATH ) . ')';
		}
		return 'Referral: ' . $host;
	}

	/* ---- Lead qualification ---- */

	private static function is_priority_ind( array $r ) {
		return $r['ind'] && 'none' !== $r['ind'];
	}

	/** Hot = worth a same-day call. */
	private static function is_hot( array $r ) {
		return $r['age'] && $r['age'] < 45 && ( $r['total'] >= 600 || ( self::is_priority_ind( $r ) && $r['working_in_vic'] ) );
	}

	private static function grade( array $l ) {
		$r = $l['result'];
		if ( $r['age'] >= 45 ) {
			return array( 'Not eligible (45+)', '#6B7280', 'Age 45+: not eligible for 190/491. Offer other pathways (e.g. employer sponsored).' );
		}
		if ( self::is_hot( $r ) ) {
			return array( 'HOT – call today', '#C2410C', 'High score or onshore in a priority industry. Call today.' );
		}
		if ( $r['total'] >= 450 || self::is_priority_ind( $r ) ) {
			return array( 'WARM – call within 24h', '#B45309', 'Reasonable profile with clear room to improve.' );
		}
		return array( 'NURTURE', '#2563EB', 'Lower score today. Focus on the biggest point gains and keep them warm until Victoria opens.' );
	}

	private static function tags( array $l ) {
		$r   = $l['result'];
		$t   = array( 'vpt-checker', 'victorian-roi-form' );
		$t[] = $r['total'] >= 700 ? 'vpt-700plus' : ( $r['total'] >= 550 ? 'vpt-550-699' : ( $r['total'] >= 400 ? 'vpt-400-549' : 'vpt-under-400' ) );
		$t[] = self::is_priority_ind( $r ) ? 'vic-priority-' . $r['ind'] : 'vic-non-priority';
		$t[] = $r['working_in_vic'] ? 'vic-working-in-vic' : 'vic-not-working-in-vic';
		if ( $r['visa'] ) {
			$t[] = 'visa-' . $r['visa'];
		}
		if ( $r['age'] >= 45 ) {
			$t[] = 'age-ineligible';
		}
		if ( $l['notify'] ) {
			$t[] = 'vic-opening-alert';
		}
		if ( $l['ctx']['returning'] ) {
			$t[] = 'returning-lead';
		}
		if ( self::is_hot( $r ) ) {
			$t[] = 'hot-lead';
		}
		return $t;
	}

	/** Things to bring up on the call, and services that fit this person. */
	private static function talking_points( array $l ) {
		$r  = $l['result'];
		$tp = array();
		$sv = array();

		$tp[] = self::is_priority_ind( $r )
			? 'Occupation is in the ' . $r['industry'] . ' priority industry, so they can be invited in that stream as well as the most competitive stream. Confirm the exact ANZSCO code is on Victoria\'s list.'
			: 'Not in a priority industry, so they compete on score alone in the most competitive stream.';

		if ( $l['gains'] ) {
			$best = $l['gains'][0];
			foreach ( $l['gains'] as $g ) {
				if ( $g[0] > $best[0] ) {
					$best = $g;
				}
			}
			$tp[] = 'Biggest lever: +' . $best[0] . ' pts – ' . $best[1] . '.';
		}
		if ( $r['age'] >= 40 && $r['age'] < 45 ) {
			$tp[] = 'Age ' . $r['age'] . ': loses about 30 VPT points a year from now. Timing matters.';
		}
		if ( 'nopri' === $r['partner'] ) {
			$tp[] = 'Partner gets 10 SkillSelect points but 0 VPT points (occupation not on the priority list).';
		}
		if ( 'ece' === $r['ind'] ) {
			$tp[] = 'Early childhood teacher: Victoria prioritises 1+ year experience AND Proficient/Superior English.';
		}
		$tp[] = 'Check they reach 65 SkillSelect points including ' . ( '491' === $r['visa'] ? '15' : '5' ) . ' nomination points.';
		$tp[] = 'Everyone must lodge a new ROI ($60 + GST) when Victoria opens 2026–27. Offer to prepare it now.';

		if ( in_array( $r['eng'], array( 'c', 'p' ), true ) ) {
			$sv[] = 'PTE / IELTS coaching (Test Preparation)';
		}
		if ( 'ns' === $r['skill'] || 'no' === $r['partner'] ) {
			$sv[] = 'Skills assessment review' . ( 'no' === $r['partner'] ? ' (incl. partner)' : '' );
		}
		if ( $r['age'] >= 45 ) {
			$sv[] = 'Employer sponsored options (482 / 186 / 494)';
		} else {
			$sv[] = 'Victorian ROI preparation and lodgement';
			$sv[] = 'SkillSelect EOI review';
		}
		return array( $tp, $sv );
	}

	/* ---- Email content ---- */

	private static function kind( array $l ) {
		$k = array( 'unlock' => 'Unlocked their score', 'callback' => 'Callback request', 'notify' => 'Notify when Victoria opens' );
		return $k[ $l['type'] ];
	}

	private static function sales_subject( array $l ) {
		$r     = $l['result'];
		$who   = $l['name'] ? $l['name'] : $l['email'];
		$hot   = self::is_hot( $r ) ? '🔥 ' : '';
		$bits  = array( $who, $r['total'] . '/950' );
		$bits[] = self::is_priority_ind( $r ) ? $r['industry'] : 'Non-priority';
		$bits[] = $l['ctx']['in_vic'] ? 'In Victoria' : ( $l['ctx']['phone_country'] ? preg_replace( '/ \(.*\)$/', '', $l['ctx']['phone_country'] ) : 'Location unknown' );
		if ( $r['age'] >= 45 ) {
			$bits[] = 'Age 45+ (not eligible)';
		}
		if ( $l['ctx']['returning'] ) {
			$bits[] = 'Returning';
		}
		return '[Victorian ROI Form] ' . $hot . implode( ' · ', $bits );
	}

	private static function row( $k, $v, $raw = false ) {
		if ( '' === $v || null === $v ) {
			return '';
		}
		return '<tr><td style="padding:5px 12px 5px 0;color:#666;width:150px;vertical-align:top">' . esc_html( $k ) . '</td>'
			. '<td style="padding:5px 0;color:#212529;vertical-align:top">' . ( $raw ? $v : esc_html( $v ) ) . '</td></tr>';
	}

	private static function btn( $href, $label, $bg ) {
		return '<a href="' . esc_attr( $href ) . '" style="display:inline-block;background:' . $bg . ';color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:10px 18px;border-radius:999px;margin:0 6px 8px 0">' . esc_html( $label ) . '</a>';
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
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;border:1px solid #DCE6F2">' . $h . '</table>';
	}

	private static function wrap( $inner, $header = 'Interlace Studies' ) {
		return '<!doctype html><html><body style="margin:0;background:#EAF2FA;font-family:Arial,Helvetica,sans-serif">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#fff;border-radius:12px">'
			. '<tr><td style="background:#3267AD;color:#fff;padding:16px 24px;border-radius:12px 12px 0 0;font-size:16px;font-weight:700">' . $header . '</td></tr>'
			. '<tr><td style="padding:24px">' . $inner . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	private static function h2( $t ) {
		return '<h2 style="font-size:15px;color:#3267AD;text-transform:uppercase;letter-spacing:.04em;margin:26px 0 8px">' . esc_html( $t ) . '</h2>';
	}

	private static function sales_html( array $l ) {
		$r     = $l['result'];
		$c     = $l['ctx'];
		$grade = self::grade( $l );
		list( $points, $services ) = self::talking_points( $l );

		// 1. Where this came from (always first, so nobody wonders).
		$h = '<p style="margin:0 0 4px;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.06em">New lead from</p>'
			. '<p style="margin:0 0 14px;font-size:18px;font-weight:700;color:#212529">' . esc_html( self::FORM_NAME ) . '</p>';

		// 2. At a glance.
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F9FD;border:1px solid #DCE6F2;border-radius:10px"><tr>'
			. '<td style="padding:16px 18px;vertical-align:top;width:40%">'
			. '<div style="font-size:12px;color:#666">VPT score</div>'
			. '<div style="font-family:Georgia,serif;font-size:40px;font-weight:700;color:#3267AD;line-height:1.1">' . (int) $r['total'] . '<span style="font-size:15px;color:#666"> / 950</span></div>'
			. '<div style="display:inline-block;margin-top:8px;background:' . $grade[1] . ';color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px">' . esc_html( $grade[0] ) . '</div>'
			. '</td><td style="padding:16px 18px 16px 0;vertical-align:top;font-size:14px;color:#212529;line-height:1.7">'
			. '<strong>' . esc_html( $l['name'] ? $l['name'] : $l['email'] ) . '</strong><br>'
			. esc_html( $r['visa'] ? 'Subclass ' . $r['visa'] : 'Visa not given' ) . ' · ' . esc_html( $r['age'] ? 'Age ' . $r['age'] : '' ) . ' · ' . esc_html( $r['eng_lbl'] ) . ' English<br>'
			. esc_html( self::is_priority_ind( $r ) ? $r['industry'] . ' (priority)' : 'Not a priority industry' ) . '<br>'
			. esc_html( $r['working_in_vic'] ? 'Working in Victoria: ' . $r['earn_lbl'] : 'Not working in Victoria' )
			. '</td></tr></table>';
		$h .= '<p style="font-size:13px;color:#212529;margin:10px 0 0">' . esc_html( $grade[2] ) . '</p>';

		if ( $c['returning'] ) {
			$h .= '<p style="background:#FFF4E5;border-left:4px solid #F0A030;padding:10px 12px;margin:12px 0 0;font-size:13px;color:#212529"><strong>Returning lead:</strong> this phone/email has used the checker before (last on ' . esc_html( $c['last_seen'] ) . '). Check the CRM before calling.</p>';
		}

		// 3. Contact actions.
		$h .= self::h2( 'Contact' );
		$btns = '';
		if ( $l['phone'] ) {
			$wa       = preg_replace( '/[^0-9]/', '', $l['phone'] );
			if ( 0 === strpos( $wa, '04' ) ) {
				$wa = '61' . substr( $wa, 1 );
			}
			$greeting = 'Hi ' . ( $l['name'] ? $l['name'] : 'there' ) . ', this is [your name] from Interlace Studies. Thanks for checking your Victorian Points Test score (' . $r['total'] . '/950). Is now a good time to talk through your result?';
			$btns    .= self::btn( 'tel:' . preg_replace( '/[^0-9+]/', '', $l['phone'] ), 'Call ' . $l['phone'], '#3267AD' );
			$btns    .= self::btn( 'https://wa.me/' . $wa . '?text=' . rawurlencode( $greeting ), 'WhatsApp (message ready)', '#25D366' );
		}
		if ( $l['email'] ) {
			$btns .= self::btn( 'mailto:' . $l['email'] . '?subject=' . rawurlencode( 'Your Victorian Points Test result (' . $r['total'] . '/950)' ), 'Email', '#1E96E0' );
		}
		$h .= $btns;
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;margin-top:6px">'
			. self::row( 'Phone', $l['phone'] )
			. self::row( 'Phone country', $c['phone_country'] )
			. self::row( 'Email', $l['email'] ? $l['email'] : 'Not given' )
			. self::row( 'Their local time', $c['local_time'] ? $c['local_time'] . ' (' . $c['tz'] . ')' . ( isset( $c['call_now'] ) ? ( $c['call_now'] ? ' – good time to call' : ' – outside 9am–8pm, message first' ) : '' ) : 'Unknown' )
			. self::row( 'Wants opening alert', $l['notify'] ? 'Yes – tell them the day Victoria opens' : 'No' )
			. '</table>';

		// 4. Talking points.
		$h .= self::h2( 'Suggested talking points' );
		$h .= '<ul style="margin:0;padding-left:18px;font-size:14px;color:#212529;line-height:1.6">';
		foreach ( $points as $pt ) {
			$h .= '<li style="margin-bottom:4px">' . esc_html( $pt ) . '</li>';
		}
		$h .= '</ul>';
		$h .= '<p style="font-size:14px;color:#212529;margin:10px 0 0"><strong>Services that fit:</strong> ' . esc_html( implode( ' · ', $services ) ) . '</p>';

		// 5. Point gains (what they saw on the page).
		if ( $l['gains'] ) {
			$h .= self::h2( 'Points they could still gain (shown to them)' );
			$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">';
			foreach ( $l['gains'] as $g ) {
				$h .= '<tr><td style="padding:4px 12px 4px 0;color:#3267AD;font-weight:700;white-space:nowrap">+' . (int) $g[0] . '</td><td style="padding:4px 0;color:#212529">' . esc_html( $g[1] ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		// 6. Full answers.
		$h .= self::h2( 'Their answers and points' );
		$h .= self::breakdown_html( $r );

		// 7. How they found us.
		$m  = $l['meta'];
		$h .= self::h2( 'How they found us' );
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">'
			. self::row( 'Form', self::FORM_NAME )
			. self::row( 'Request', self::kind( $l ) )
			. self::row( 'Source', $c['source'] )
			. self::row( 'Search term / ad', isset( $m['utm_term'] ) ? $m['utm_term'] : ( isset( $m['utm_content'] ) ? $m['utm_content'] : '' ) )
			. self::row( 'Device', $c['device'] )
			. self::row( 'Visitor country (IP)', $c['ip_country'] )
			. self::row( 'Browser language', isset( $m['lang'] ) ? $m['lang'] : '' )
			. self::row( 'Time on checker', isset( $m['seconds'] ) && (int) $m['seconds'] > 0 ? self::duration( (int) $m['seconds'] ) : '' )
			. self::row( 'Visits to the page', isset( $m['visits'] ) && (int) $m['visits'] > 1 ? (int) $m['visits'] . ' (came back to finish)' : '' )
			. self::row( 'Page', $l['page'] )
			. self::row( 'Received', $l['time'] )
			. self::row( 'Lead ref', $l['ref'] )
			. '</table>';

		$h .= '<p style="font-size:12px;color:#888;margin:18px 0 0">CRM tags: ' . esc_html( implode( ', ', $l['tags'] ) ) . '<br>Score recalculated on the server from their answers. ' . esc_html( self::PROGRAM ) . '. Reply to this email to reach the lead' . ( $l['email'] ? '' : ' (no email given – use phone/WhatsApp)' ) . '.</p>';

		return self::wrap( $h, 'Victorian ROI Form · New lead · ' . esc_html( $l['ref'] ) );
	}

	private static function duration( $s ) {
		return $s < 60 ? $s . ' seconds' : floor( $s / 60 ) . ' min ' . ( $s % 60 ) . ' s';
	}

	private static function sales_text( array $l ) {
		$r     = $l['result'];
		$c     = $l['ctx'];
		$grade = self::grade( $l );
		list( $points, $services ) = self::talking_points( $l );

		$t  = 'NEW LEAD FROM: ' . self::FORM_NAME . "\nRef: {$l['ref']}\n\n";
		$t .= "VPT score: {$r['total']}/950 – {$grade[0]}\n{$grade[2]}\n";
		$t .= $c['returning'] ? "RETURNING LEAD (last used the checker {$c['last_seen']})\n" : '';
		$t .= "\nCONTACT\n";
		$t .= $l['name'] ? "Name: {$l['name']}\n" : '';
		$t .= $l['phone'] ? "Mobile: {$l['phone']} ({$c['phone_country']})\n" : '';
		$t .= 'Email: ' . ( $l['email'] ? $l['email'] : 'not given' ) . "\n";
		$t .= $c['local_time'] ? "Their local time: {$c['local_time']} ({$c['tz']})\n" : '';
		$t .= 'Opening alert: ' . ( $l['notify'] ? 'yes' : 'no' ) . "\n";
		$t .= "\nTALKING POINTS\n- " . implode( "\n- ", $points ) . "\nServices that fit: " . implode( ', ', $services ) . "\n";
		$t .= "\nANSWERS\n";
		foreach ( $r['rows'] as $row ) {
			$t .= $row[0] . ': ' . $row[1] . ( null === $row[2] ? '' : " ({$row[2]}/{$row[3]})" ) . "\n";
		}
		$t .= "\nSOURCE\nSource: {$c['source']}\nDevice: {$c['device']}\nPage: {$l['page']}\nReceived: {$l['time']}\nTags: " . implode( ', ', $l['tags'] ) . "\n";
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
		$t .= "\nEveryone must lodge a new ROI when Victoria's 2026-27 program opens.\nBook an appointment: https://interlace.com.au/book-an-appointment\nWhatsApp: https://wa.me/61481697403\n\nEstimate only, not migration advice. Reply to this email to opt out.";
		return $t;
	}

	private static function ghl_payload( array $l ) {
		$r     = $l['result'];
		$c     = $l['ctx'];
		$grade = self::grade( $l );
		$payload = array(
			'first_name'      => $l['name'],
			'phone'           => $l['phone'],
			'email'           => $l['email'],
			'lead_source'     => self::FORM_NAME,
			'lead_ref'        => $l['ref'],
			'request_type'    => $l['type'],
			'opening_alert'   => $l['notify'] ? 'yes' : 'no',
			'vpt_score'       => $r['total'],
			'lead_grade'      => $grade[0],
			'hot_lead'        => self::is_hot( $r ) ? 'yes' : 'no',
			'returning_lead'  => $c['returning'] ? 'yes' : 'no',
			'phone_country'   => $c['phone_country'],
			'timezone'        => $c['tz'],
			'traffic_source'  => $c['source'],
			'device'          => $c['device'],
			'tags'            => implode( ',', $l['tags'] ),
			'source_page'     => $l['page'],
		);
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			if ( ! empty( $l['meta'][ $k ] ) ) {
				$payload[ $k ] = $l['meta'][ $k ];
			}
		}
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
