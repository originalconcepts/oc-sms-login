<?php
/**
 * The front: the phone popup, the checkout gate, and the sign-in block.
 *
 * The gate works like the Deliz checkout: a guest who heads to the checkout
 * (the side cart's button, the cart page, a checkout link, or the checkout URL
 * itself) gets the phone popup first. Signed in, the checkout opens with every
 * detail already there (WooCommerce fills the fields from the account, and the
 * account was filled from the customer's last order).
 *
 *   required  a guest cannot place an order without signing in — enforced on
 *             the server too, not only in the popup.
 *   optional  the popup offers the sign-in; "continue as a guest" closes it.
 *   off       no gate; the sign-in block and the shortcode still work.
 *
 * The gate only stands while a code can actually go out (OCSL_Sender::
 * available()): a lapsed licence or an empty balance falls back to the shop's
 * own guest checkout, so the plugin never blocks a sale.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Gate {

	/** @var bool Whether the popup markup is needed on this page. */
	private static $needed = false;

	public static function boot() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'popup' ), 5 ); // Before the footer scripts (priority 20) run.
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'enforce' ), 1 );
		// The checkout block places its orders through the Store API, which never fires the hook above.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'enforce_store_api' ), 1 );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'account_block' ), 5 );
		add_shortcode( 'oc_sms_login', array( __CLASS__, 'shortcode' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'no_prefetch' ) );
	}

	/**
	 * WordPress (6.8+) has the browser prefetch a link the moment it is pressed. A guest pressing
	 * "checkout" gets the checkout prefetched as a guest; our popup stops that navigation, the guest
	 * signs in, and the browser then shows the stale guest copy, popup and all. Pages that differ
	 * for a signed-in visitor are never prefetched.
	 *
	 * @param string[] $paths Paths WordPress already leaves out.
	 * @return string[]
	 */
	public static function no_prefetch( $paths ) {
		$paths = is_array( $paths ) ? $paths : array();
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return $paths;
		}
		foreach ( array( 'checkout', 'cart', 'myaccount' ) as $page ) {
			$path = (string) wp_parse_url( (string) wc_get_page_permalink( $page ), PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = trailingslashit( $path ) . '*';
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * An asset's version: the plugin's, plus the file's time, so no browser keeps an old copy.
	 *
	 * @param string $rel Path under the plugin folder.
	 * @return string
	 */
	private static function ver( $rel ) {
		$path = plugin_dir_path( OCSL_FILE ) . $rel;
		return OCSL_VERSION . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );
	}

	/** The mode in force right now: required | optional | off. */
	public static function mode() {
		$mode = (string) OCSL_Settings::get( 'gate' );
		if ( 'off' === $mode || ! function_exists( 'WC' ) || ! OCSL_Sender::available() ) {
			return 'off';
		}
		return in_array( $mode, array( 'required', 'optional' ), true ) ? $mode : 'required';
	}

	/** A guest on a page that may need the popup. */
	private static function wanted() {
		if ( is_user_logged_in() || is_admin() || ! OCSL_Sender::available() ) {
			return false;
		}
		if ( 'off' !== self::mode() ) {
			return true; // The side cart's checkout button lives on every page.
		}
		// The sign-in block also sits in themes' header login drawers, on every page; so do the shop's own triggers.
		return OCSL_Settings::get( 'myaccount_form' ) || '' !== trim( (string) OCSL_Settings::get( 'open_selectors' ) );
	}

	/** The checkout page itself, before an order exists. */
	private static function on_checkout() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
	}

	public static function body_class( $classes ) {
		if ( ! is_user_logged_in() && 'off' !== self::mode() && self::on_checkout() ) {
			$classes[] = 'ocsl-gated';
			$classes[] = 'ocsl-gated-' . self::mode();
		}
		return $classes;
	}

	public static function assets() {
		if ( ! self::wanted() ) {
			return;
		}
		self::$needed = true;
		$url = plugin_dir_url( OCSL_FILE );
		wp_enqueue_style( 'ocsl', $url . 'assets/css/ocsl.css', array(), self::ver( 'assets/css/ocsl.css' ) );
		wp_enqueue_script( 'ocsl', $url . 'assets/js/ocsl.js', array(), self::ver( 'assets/js/ocsl.js' ), true );

		wp_add_inline_style( 'ocsl', ':root{' . self::css_vars() . '}' );

		wp_localize_script( 'ocsl', 'ocslData', self::script_data( true ) );
	}

	/**
	 * What the script needs to know.
	 *
	 * @param bool $gate The gate's own page load (true), or a page only the shortcode put the script on (false).
	 * @return array
	 */
	private static function script_data( $gate ) {
		$s    = OCSL_Settings::all();
		$mode = $gate ? self::mode() : 'off';
		return array(
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( OCSL_Auth::NONCE ),
			'mode'       => $mode,
			'checkout'   => ( $gate && function_exists( 'wc_get_checkout_url' ) ) ? wc_get_checkout_url() : '',
			'cart'       => ( $gate && function_exists( 'wc_get_cart_url' ) ) ? wc_get_cart_url() : home_url( '/' ),
			'home'       => home_url( '/' ),
			'onCheckout' => $gate && 'off' !== $mode && self::on_checkout(),
			'selectors'  => $gate ? trim( (string) $s['open_selectors'] ) : '',
			'test'       => 'test' === OCSL_Sender::transport(),
			'expiry'     => (int) $s['code_expiry'],
			'i18n'       => array(
				'sending'   => 'שולחים...',
				'checking'  => 'בודקים...',
				'error'     => 'משהו השתבש. נסו שוב.',
				'resendIn'  => 'אפשר לבקש קוד חדש בעוד %s שניות',
				'resend'    => 'שלחו לי קוד חדש',
				'signedIn'  => 'נכנסתם! ממשיכים...',
				'testCode'  => 'מצב בדיקה: הקוד הוא %s',
				'gateTitle' => (string) $s['gate_title'],
				'title'     => (string) $s['title'],
			) + self::drawer_words(),
		);
	}

	/**
	 * The popup's look from the settings, as CSS variables (also drives the preview in the settings).
	 *
	 * @param array|null $s Settings, or the saved ones.
	 * @return string
	 */
	public static function css_vars( $s = null ) {
		$s      = is_array( $s ) ? $s : OCSL_Settings::all();
		$shapes = array(
			'soft'   => array( '10px', '10px' ),
			'round'  => array( '999px', '24px' ),
			'square' => array( '0px', '0px' ),
		);
		$shape  = isset( $shapes[ $s['btn_shape'] ] ) ? $shapes[ $s['btn_shape'] ] : $shapes['soft'];
		$vars   = array(
			'--ocsl-radius'       => (int) $s['radius'] . 'px',
			'--ocsl-btn-radius'   => $shape[0],
			'--ocsl-field-radius' => $shape[1],
			'--ocsl-overlay'      => (string) round( (int) $s['overlay'] / 100, 2 ),
		);
		foreach ( array( 'brand_color' => '--ocsl-brand', 'btn_text_color' => '--ocsl-btn-ink', 'popup_bg' => '--ocsl-bg', 'text_color' => '--ocsl-ink' ) as $k => $var ) {
			if ( '' !== (string) $s[ $k ] ) {
				$vars[ $var ] = (string) $s[ $k ];
			}
		}
		$out = '';
		foreach ( $vars as $k => $v ) {
			$out .= $k . ':' . $v . ';';
		}
		return $out;
	}

	/** The words of the theme's sign-in drawer, for the script that dresses it. */
	private static function drawer_words() {
		$s = OCSL_Settings::all();
		return array(
			'drawerHead'      => (string) $s['drawer_head'],
			'drawerTitle'     => (string) $s['drawer_title'],
			'drawerEmail'     => (string) $s['drawer_email'],
			'drawerJoinTitle' => (string) $s['drawer_join_title'],
			'drawerJoinText'  => (string) $s['drawer_join_text'],
			'drawerJoinBtn'   => (string) $s['drawer_join_btn'],
			'drawerBack'      => 'חזרה לכניסה עם הטלפון',
		);
	}

	/**
	 * Refuse an order from a guest while the gate is required: the popup can be bypassed in the
	 * browser, the server cannot.
	 */
	public static function enforce() {
		if ( is_user_logged_in() || 'required' !== self::mode() ) {
			return;
		}
		wc_add_notice( 'כדי להשלים את ההזמנה צריך להתחבר עם מספר הטלפון. רעננו את העמוד והזינו את המספר שלכם.', 'error' );
	}

	/**
	 * The same refusal for the checkout block, and for anything else that orders through the Store
	 * API (express payment buttons included).
	 *
	 * @param WC_Order $order The order being built from the request.
	 * @throws \Exception Refuses a guest's order while the gate is required.
	 */
	public static function enforce_store_api( $order ) {
		if ( is_user_logged_in() || 'required' !== self::mode() ) {
			return;
		}
		if ( $order instanceof WC_Order && $order->get_customer_id() > 0 ) {
			return; // The order already belongs to an account: never stand in the way of a signed-in customer.
		}
		$msg   = 'כדי להשלים את ההזמנה צריך להתחבר עם מספר הטלפון. רעננו את העמוד והזינו את המספר שלכם.';
		$class = 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( class_exists( $class ) ) {
			throw new $class( 'ocsl_login_required', $msg, 401 );
		}
		throw new \Exception( $msg );
	}

	/** The sign-in block above the My Account login forms. */
	public static function account_block() {
		if ( is_user_logged_in() || ! OCSL_Settings::get( 'myaccount_form' ) || ! OCSL_Sender::available() ) {
			return;
		}
		self::$needed = true;
		echo '<div class="ocsl-inline" data-ocsl-inline data-ocsl-to="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">';
		self::steps( false );
		echo '<p class="ocsl-or"><span>או כניסה עם אימייל וסיסמה</span></p></div>';
	}

	/**
	 * [oc_sms_login to="https://…"]: the sign-in, anywhere.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( is_user_logged_in() || ! OCSL_Sender::available() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'to' => '' ), $atts, 'oc_sms_login' );
		if ( ! wp_script_is( 'ocsl', 'enqueued' ) ) {
			$url = plugin_dir_url( OCSL_FILE );
			wp_enqueue_style( 'ocsl', $url . 'assets/css/ocsl.css', array(), self::ver( 'assets/css/ocsl.css' ) );
			wp_enqueue_script( 'ocsl', $url . 'assets/js/ocsl.js', array(), self::ver( 'assets/js/ocsl.js' ), true );
			wp_localize_script( 'ocsl', 'ocslData', self::script_data( false ) );
		}
		self::$needed = true;
		ob_start();
		echo '<div class="ocsl-inline" data-ocsl-inline data-ocsl-to="' . esc_url( $atts['to'] ) . '">';
		self::steps( false );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * The popup, once per page, for guests. With the gate off it still opens from the shop's own
	 * triggers (the header's account icon, window.ocslOpen()).
	 */
	public static function popup() {
		if ( ! self::$needed || is_user_logged_in() || ! OCSL_Sender::available() ) {
			return;
		}
		$locked = 'required' === self::mode();
		?>
		<div class="ocsl-modal" id="ocsl-modal" hidden data-ocsl-modal<?php echo $locked ? ' data-ocsl-locked' : ''; ?>>
			<?php // Required: a stray click beside the box must not throw the typed number away. ?>
			<div class="ocsl-backdrop"<?php echo $locked ? '' : ' data-ocsl-close'; ?>></div>
			<div class="ocsl-dialog" role="dialog" aria-modal="true" aria-labelledby="ocsl-title" data-ocsl-to="">
				<button type="button" class="ocsl-x" data-ocsl-close aria-label="סגירה">&times;</button>
				<?php self::steps( true ); ?>
				<?php if ( 'optional' === self::mode() ) : ?>
					<p class="ocsl-guest"><button type="button" class="ocsl-link" data-ocsl-guest>המשך כאורח/ת</button></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The three steps (phone, details, code) and the done screen.
	 *
	 * @param bool $in_popup The popup (gate title) or an inline block.
	 */
	private static function steps( $in_popup ) {
		$s       = OCSL_Settings::all();
		$title   = $in_popup ? $s['gate_title'] : $s['title'];
		$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		$consent = esc_html( (string) $s['consent_text'] );
		$link    = '' !== $privacy
			? '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">מדיניות הפרטיות</a>'
			: 'מדיניות הפרטיות';
		$consent = str_replace( '{privacy}', $link, $consent );
		$uid     = wp_unique_id( 'ocsl-' );
		?>
		<div class="ocsl-steps" data-ocsl-steps>
			<form class="ocsl-step" data-ocsl-step="phone" novalidate>
				<h2 class="ocsl-h"<?php echo $in_popup ? ' id="ocsl-title"' : ''; ?>><?php echo esc_html( $title ); ?></h2>
				<p class="ocsl-sub"><?php echo esc_html( $s['subtitle'] ); ?></p>
				<label class="ocsl-label" for="<?php echo esc_attr( $uid ); ?>-phone">מספר טלפון נייד</label>
				<div class="ocsl-row">
					<input class="ocsl-input ocsl-tel" id="<?php echo esc_attr( $uid ); ?>-phone" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="05X-XXXXXXX" dir="ltr" required>
					<button type="submit" class="ocsl-btn">שליחת קוד</button>
				</div>
				<p class="ocsl-err" role="alert" hidden></p>
			</form>

			<form class="ocsl-step" data-ocsl-step="register" hidden novalidate>
				<h2 class="ocsl-h"><?php echo esc_html( $s['register_title'] ); ?></h2>
				<p class="ocsl-sub"><?php echo esc_html( $s['register_text'] ); ?></p>
				<div class="ocsl-grid">
					<p><label class="ocsl-label" for="<?php echo esc_attr( $uid ); ?>-first">שם פרטי</label>
						<input class="ocsl-input" id="<?php echo esc_attr( $uid ); ?>-first" name="first" autocomplete="given-name" required></p>
					<p><label class="ocsl-label" for="<?php echo esc_attr( $uid ); ?>-last">שם משפחה</label>
						<input class="ocsl-input" id="<?php echo esc_attr( $uid ); ?>-last" name="last" autocomplete="family-name" required></p>
				</div>
				<p><label class="ocsl-label" for="<?php echo esc_attr( $uid ); ?>-email">אימייל</label>
					<input class="ocsl-input" id="<?php echo esc_attr( $uid ); ?>-email" name="email" type="email" autocomplete="email" dir="ltr" required></p>
				<p class="ocsl-hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></p>
				<input type="hidden" name="ts" value="">
				<label class="ocsl-consent"><input type="checkbox" name="consent" value="1" required> <span><?php echo wp_kses( $consent, array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></span></label>
				<button type="submit" class="ocsl-btn ocsl-btn-wide">שליחת קוד אימות</button>
				<p class="ocsl-err" role="alert" hidden></p>
				<p class="ocsl-back"><button type="button" class="ocsl-link" data-ocsl-change>זה לא המספר שלי</button></p>
			</form>

			<form class="ocsl-step" data-ocsl-step="code" hidden novalidate>
				<h2 class="ocsl-h">הזינו את הקוד</h2>
				<p class="ocsl-sub">שלחנו קוד בן 6 ספרות אל <bdi class="ocsl-pretty" dir="ltr"></bdi>
					<button type="button" class="ocsl-link" data-ocsl-change>שינוי מספר</button></p>
				<p class="ocsl-test" hidden></p>
				<div class="ocsl-boxes" dir="ltr">
					<?php for ( $i = 0; $i < 6; $i++ ) : ?>
						<input class="ocsl-box" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="<?php echo 0 === $i ? 'one-time-code' : 'off'; ?>" aria-label="<?php echo esc_attr( 'ספרה ' . ( $i + 1 ) ); ?>">
					<?php endfor; ?>
				</div>
				<button type="submit" class="ocsl-btn ocsl-btn-wide">כניסה</button>
				<p class="ocsl-err" role="alert" hidden></p>
				<p class="ocsl-resend"><span class="ocsl-wait"></span><button type="button" class="ocsl-link" data-ocsl-resend hidden>שלחו לי קוד חדש</button></p>
			</form>

			<div class="ocsl-step ocsl-done" data-ocsl-step="done" hidden>
				<span class="ocsl-spin" aria-hidden="true"></span>
				<p class="ocsl-h" role="status">נכנסתם! ממשיכים...</p>
			</div>
		</div>
		<?php
	}
}
