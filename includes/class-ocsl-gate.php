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
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'account_block' ), 5 );
		add_shortcode( 'oc_sms_login', array( __CLASS__, 'shortcode' ) );
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
		return function_exists( 'is_account_page' ) && is_account_page() && OCSL_Settings::get( 'myaccount_form' );
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

		$brand = (string) OCSL_Settings::get( 'brand_color' );
		if ( '' !== $brand ) {
			wp_add_inline_style( 'ocsl', ':root{--ocsl-brand:' . $brand . ';}' );
		}

		$selectors = trim( (string) OCSL_Settings::get( 'open_selectors' ) );
		wp_localize_script(
			'ocsl',
			'ocslData',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( OCSL_Auth::NONCE ),
				'mode'      => self::mode(),
				'checkout'  => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
				'cart'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
				'onCheckout' => self::on_checkout() && 'off' !== self::mode(),
				'selectors' => $selectors,
				'test'      => 'test' === OCSL_Sender::transport(),
				'expiry'    => (int) OCSL_Settings::get( 'code_expiry' ),
				'i18n'      => array(
					'sending'   => 'שולחים...',
					'checking'  => 'בודקים...',
					'error'     => 'משהו השתבש. נסו שוב.',
					'resendIn'  => 'אפשר לבקש קוד חדש בעוד %s שניות',
					'resend'    => 'שלחו לי קוד חדש',
					'signedIn'  => 'נכנסתם! ממשיכים...',
					'testCode'  => 'מצב בדיקה: הקוד הוא %s',
				),
			)
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
			self::assets_data_fallback();
		}
		self::$needed = true;
		ob_start();
		echo '<div class="ocsl-inline" data-ocsl-inline data-ocsl-to="' . esc_url( $atts['to'] ) . '">';
		self::steps( false );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/** The script's settings when the shortcode loaded it on a page the gate does not cover. */
	private static function assets_data_fallback() {
		wp_localize_script(
			'ocsl',
			'ocslData',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( OCSL_Auth::NONCE ),
				'mode'       => 'off',
				'checkout'   => '',
				'cart'       => home_url( '/' ),
				'onCheckout' => false,
				'selectors'  => '',
				'test'       => 'test' === OCSL_Sender::transport(),
				'expiry'     => (int) OCSL_Settings::get( 'code_expiry' ),
				'i18n'       => array(
					'sending'  => 'שולחים...',
					'checking' => 'בודקים...',
					'error'    => 'משהו השתבש. נסו שוב.',
					'resendIn' => 'אפשר לבקש קוד חדש בעוד %s שניות',
					'resend'   => 'שלחו לי קוד חדש',
					'signedIn' => 'נכנסתם! ממשיכים...',
					'testCode' => 'מצב בדיקה: הקוד הוא %s',
				),
			)
		);
	}

	/** The popup, once per page, for guests. */
	public static function popup() {
		if ( ! self::$needed || is_user_logged_in() || 'off' === self::mode() ) {
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
