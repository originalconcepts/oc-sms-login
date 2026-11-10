<?php
/**
 * The admin: three screens under the suite's one "OC Pluginz" menu — the
 * settings, the SMS packs (balance and buying), and the send log — plus the
 * OC Pluginz account screen (OCSL_Hub_Admin).
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Admin {

	const SLUG    = 'oc-sms-login';
	const CREDITS = 'oc-sms-login-credits';
	const LOG     = 'oc-sms-login-log';
	const CAP     = 'manage_woocommerce';

	/**
	 * The packs as OC Pluginz sells them (before VAT). The hub's own list wins when it sent one; this
	 * one shows until then, so the screen is never empty.
	 */
	const PACKS = array(
		array( 'key' => 'sms2500', 'messages' => 2500, 'price' => 199 ),
		array( 'key' => 'sms5000', 'messages' => 5000, 'price' => 349 ),
		array( 'key' => 'sms10000', 'messages' => 10000, 'price' => 590 ),
		array( 'key' => 'sms25000', 'messages' => 25000, 'price' => 1290 ),
		array( 'key' => 'sms50000', 'messages' => 50000, 'price' => 2290 ),
		array( 'key' => 'sms100000', 'messages' => 100000, 'price' => 3990 ),
	);

	const VAT = 0.18;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register_with_suite' ), 20 );
		add_action( 'admin_post_ocsl_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ocsl_balance', array( __CLASS__, 'refresh_balance' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_filter( 'plugin_action_links_' . OCSL_BASENAME, array( __CLASS__, 'action_link' ) );
	}

	public static function register_with_suite() {
		if ( ! is_admin() || ! function_exists( 'oc_pluginz_register' ) ) {
			return;
		}
		oc_pluginz_register(
			'oc-sms-login',
			array(
				'name'        => 'כניסה עם SMS',
				'page'        => self::SLUG,
				'page_title'  => 'הגדרות',
				'capability'  => self::CAP,
				'callback'    => array( __CLASS__, 'render_settings' ),
				'description' => 'כניסה והרשמה עם קוד SMS, ושער טלפון לפני הקופה: הלקוח מגיע לקופה עם כל הפרטים.',
				'version'     => OCSL_VERSION,
				'account_url' => OCSL_Hub::settings_url(),
				'status'      => array( __CLASS__, 'card_status' ),
				'pages'       => array(
					array(
						'slug'       => self::CREDITS,
						'title'      => 'חבילות SMS',
						'capability' => self::CAP,
						'callback'   => array( __CLASS__, 'render_credits' ),
					),
					array(
						'slug'       => self::LOG,
						'title'      => 'יומן שליחות',
						'capability' => self::CAP,
						'callback'   => array( __CLASS__, 'render_log' ),
					),
					array(
						'slug'       => OCSL_Hub_Admin::PAGE,
						'title'      => 'חשבון OC Pluginz',
						'capability' => OCSL_Hub::CAP,
						'callback'   => array( 'OCSL_Hub_Admin', 'render' ),
					),
				),
			)
		);
	}

	/** One line on the suite's overview card. */
	public static function card_status() {
		$t = OCSL_Sender::transport();
		if ( 'test' === $t ) {
			return 'מצב בדיקה: קודים לא נשלחים';
		}
		if ( 'direct' === $t ) {
			return 'שליחה ישירה (מצב סוכנות)';
		}
		$b = OCSL_Hub::balance();
		return null === $b ? 'יתרת הודעות לא ידועה עדיין' : sprintf( 'יתרה: %s הודעות', number_format_i18n( $b ) );
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">הגדרות</a>' );
		return $links;
	}

	/**
	 * @param string $tab settings | credits | log.
	 * @return string
	 */
	public static function url( $tab = 'settings' ) {
		$page = 'credits' === $tab ? self::CREDITS : ( 'log' === $tab ? self::LOG : self::SLUG );
		return admin_url( 'admin.php?page=' . $page );
	}

	/** Is the current admin screen one of this plugin's? */
	public static function is_own_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'oc-sms-login' ) || OCSL_Hub_Admin::PAGE === $page;
	}

	/* ---------------- Settings ---------------- */

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'אין הרשאה.' );
		}
		check_admin_referer( 'ocsl_save' );
		$in = isset( $_POST['ocsl'] ) && is_array( $_POST['ocsl'] ) ? $_POST['ocsl'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- OCSL_Settings::sanitize() cleans every field.
		OCSL_Settings::save( $in );
		wp_safe_redirect( add_query_arg( 'ocsl_saved', 1, self::url() ) );
		exit;
	}

	private static function transport_line() {
		switch ( OCSL_Sender::transport() ) {
			case 'test':
				return array( 'warn', 'מצב בדיקה (OCSL_TEST_MODE): הקודים לא נשלחים ב-SMS אלא מוצגים על המסך. רק לסביבת פיתוח.' );
			case 'direct':
				return array( 'ok', 'מצב סוכנות: הקודים נשלחים ישירות דרך ActiveTrail עם המפתח שב-wp-config.php.' );
			case 'hub':
				$b = OCSL_Hub::balance();
				if ( null !== $b && $b <= 0 ) {
					return array( 'err', 'אין יתרת הודעות, ולכן הכניסה עם SMS לא פעילה והקופה פתוחה לאורחים כרגיל. רוכשים חבילה במסך "חבילות SMS".' );
				}
				return array( 'ok', 'הקודים נשלחים דרך OC Pluginz' . ( null === $b ? '.' : sprintf( '. יתרה: %s הודעות.', number_format_i18n( $b ) ) ) );
		}
		return array( 'err', OCSL_Hub::off_text() );
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = OCSL_Settings::all();
		$line = self::transport_line();
		$f    = function ( $k ) {
			return 'ocsl[' . $k . ']';
		};
		?>
		<div class="wrap ocsl-admin">
			<h1>כניסה עם SMS</h1>
			<?php if ( isset( $_GET['ocsl_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>ההגדרות נשמרו.</p></div>
			<?php endif; ?>
			<p class="ocsl-state ocsl-state-<?php echo esc_attr( $line[0] ); ?>"><?php echo esc_html( $line[1] ); ?></p>
			<?php self::setup_steps(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ocsl_save' ); ?>
				<input type="hidden" name="action" value="ocsl_save">
				<input type="hidden" name="<?php echo esc_attr( $f( '__submitted' ) ); ?>" value="1">

				<h2>הקופה</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">שער טלפון לפני הקופה</th>
						<td>
							<?php
							$modes = array(
								'required' => 'חובה: אורח לא יכול להזמין בלי להתחבר עם הטלפון (כמו Deliz)',
								'optional' => 'רשות: מציעים כניסה מהירה, ואפשר להמשיך כאורח',
								'off'      => 'כבוי: בלי שער (הכניסה בעמוד "החשבון שלי" ובשורטקוד עדיין עובדת)',
							);
							foreach ( $modes as $v => $label ) :
								?>
								<label style="display:block;margin:0 0 6px"><input type="radio" name="<?php echo esc_attr( $f( 'gate' ) ); ?>" value="<?php echo esc_attr( $v ); ?>" <?php checked( $s['gate'], $v ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p class="description">כשאי אפשר לשלוח קוד (אין רישיון או שהיתרה נגמרה) השער נופל לבד והקופה נפתחת לאורחים כרגיל, כך ששום מכירה לא נחסמת.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">לקוחות שהזמינו כאורחים</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $f( 'from_orders' ) ); ?>" value="1" <?php checked( $s['from_orders'] ); ?>> לזהות לפי ההזמנות הקודמות ולפתוח להם חשבון מההזמנה האחרונה (הקופה מגיעה ממולאת)</label></td>
					</tr>
					<tr>
						<th scope="row">עמוד "החשבון שלי"</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $f( 'myaccount_form' ) ); ?>" value="1" <?php checked( $s['myaccount_form'] ); ?>> להציג כניסה עם SMS מעל טופס האימייל והסיסמה</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ocsl-sel">פתיחת הכניסה גם מ...</label></th>
						<td><input id="ocsl-sel" class="regular-text code" dir="ltr" name="<?php echo esc_attr( $f( 'open_selectors' ) ); ?>" value="<?php echo esc_attr( $s['open_selectors'] ); ?>" placeholder=".header-account a">
							<p class="description">בוררי CSS מופרדים בפסיק, למשל אייקון החשבון בכותרת. לחיצה של אורח עליהם פותחת את חלון הכניסה.</p></td>
					</tr>
				</table>

				<h2>ההודעה</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ocsl-sender">שם השולח</label></th>
						<td><input id="ocsl-sender" class="regular-text" dir="ltr" maxlength="11" name="<?php echo esc_attr( $f( 'sender' ) ); ?>" value="<?php echo esc_attr( $s['sender'] ); ?>" placeholder="<?php echo esc_attr( OCSL_Sender::sender() ); ?>">
							<p class="description">השם שיופיע אצל הלקוח במקום מספר. עד 11 תווים באנגלית וספרות. ריק = <code dir="ltr"><?php echo esc_html( OCSL_Sender::sender() ); ?></code></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ocsl-text">נוסח ההודעה</label></th>
						<td><input id="ocsl-text" class="large-text" name="<?php echo esc_attr( $f( 'sms_text' ) ); ?>" value="<?php echo esc_attr( $s['sms_text'] ); ?>">
							<p class="description">חובה לכלול <code>{code}</code>. אפשר גם <code>{site}</code> ו-<code>{minutes}</code>. הודעה בעברית עד 70 תווים נספרת כהודעה אחת; ארוכה יותר נספרת כשתיים או יותר.</p>
							<p class="description">דוגמה: <strong><?php echo esc_html( OCSL_Sender::message( '482915' ) ); ?></strong> (<?php echo esc_html( (string) mb_strlen( OCSL_Sender::message( '482915' ) ) ); ?> תווים)</p></td>
					</tr>
				</table>

				<h2>החלון</h2>
				<table class="form-table" role="presentation">
					<?php
					$texts = array(
						'gate_title'     => 'כותרת לפני הקופה',
						'title'          => 'כותרת בעמוד החשבון',
						'subtitle'       => 'משפט הסבר',
						'register_title' => 'כותרת להרשמה',
						'register_text'  => 'הסבר להרשמה',
						'consent_text'   => 'אישור פרטיות ({privacy} = קישור)',
					);
					foreach ( $texts as $k => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ocsl-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input id="ocsl-<?php echo esc_attr( $k ); ?>" class="large-text" name="<?php echo esc_attr( $f( $k ) ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2>עיצוב החלון</h2>
				<p class="description">הצבעים, הפינות והכפתורים של חלון הכניסה, של הכניסה בעמוד החשבון ושל חלון ההתחברות בכותרת. השינויים מופיעים מיד בתצוגה המקדימה.</p>
				<div class="ocsl-design">
					<table class="form-table" role="presentation">
						<?php
						$colors = array(
							'brand_color'    => array( 'צבע ראשי (כפתורים וקישורים)', '#1f2937', '--ocsl-brand' ),
							'btn_text_color' => array( 'צבע הטקסט בכפתור', '#ffffff', '--ocsl-btn-ink' ),
							'popup_bg'       => array( 'רקע החלון', '#ffffff', '--ocsl-bg' ),
							'text_color'     => array( 'צבע הטקסט', '#1f2937', '--ocsl-ink' ),
						);
						foreach ( $colors as $k => $meta ) :
							?>
							<tr>
								<th scope="row"><label for="ocsl-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
								<td><input id="ocsl-<?php echo esc_attr( $k ); ?>" type="text" dir="ltr" class="ocsl-color" data-default-color="<?php echo esc_attr( $meta[1] ); ?>" data-var="<?php echo esc_attr( $meta[2] ); ?>" name="<?php echo esc_attr( $f( $k ) ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label for="ocsl-radius">עיגול פינות החלון</label></th>
							<td><input id="ocsl-radius" type="range" min="0" max="28" step="1" name="<?php echo esc_attr( $f( 'radius' ) ); ?>" value="<?php echo esc_attr( (string) $s['radius'] ); ?>"> <output for="ocsl-radius"><?php echo esc_html( (string) $s['radius'] ); ?></output> px</td>
						</tr>
						<tr>
							<th scope="row">צורת הכפתורים והשדות</th>
							<td>
								<?php foreach ( array( 'soft' => 'מעוגלים קלות', 'round' => 'עגולים', 'square' => 'ישרים' ) as $v => $label ) : ?>
									<label style="margin-inline-end:16px"><input type="radio" class="ocsl-shape" name="<?php echo esc_attr( $f( 'btn_shape' ) ); ?>" value="<?php echo esc_attr( $v ); ?>" <?php checked( $s['btn_shape'], $v ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ocsl-overlay">הכהיית הדף מאחורי החלון</label></th>
							<td><input id="ocsl-overlay" type="range" min="0" max="90" step="5" name="<?php echo esc_attr( $f( 'overlay' ) ); ?>" value="<?php echo esc_attr( (string) $s['overlay'] ); ?>"> <output for="ocsl-overlay"><?php echo esc_html( (string) $s['overlay'] ); ?></output>%</td>
						</tr>
					</table>
					<div class="ocsl-preview-wrap">
						<p class="description" style="margin:0 0 8px">תצוגה מקדימה</p>
						<div class="ocsl-preview" id="ocsl-preview" style="<?php echo esc_attr( OCSL_Gate::css_vars( $s ) ); ?>">
							<div class="ocsl-dialog">
								<button type="button" class="ocsl-x" aria-label="סגירה" tabindex="-1"></button>
								<div class="ocsl-steps">
									<div class="ocsl-step">
										<h2 class="ocsl-h"><?php echo esc_html( $s['gate_title'] ); ?></h2>
										<p class="ocsl-sub"><?php echo esc_html( $s['subtitle'] ); ?></p>
										<span class="ocsl-label">מספר טלפון נייד</span>
										<div class="ocsl-row">
											<input class="ocsl-input ocsl-tel" type="tel" placeholder="05X-XXXXXXX" dir="ltr" tabindex="-1">
											<button type="button" class="ocsl-btn" tabindex="-1">שליחת קוד</button>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<h2>חלון ההתחברות בכותרת</h2>
				<p class="description">כשתבנית האתר פותחת חלון התחברות מהכותרת ("שלום, אורח"), הכניסה עם הטלפון מסודרת בו כמו ב-OC Theme: כותרת, טלפון ושליחת קוד, "או" וכניסה עם מייל וסיסמה, והרשמה בתחתית.</p>
				<table class="form-table" role="presentation">
					<?php
					$drawer = array(
						'drawer_head'       => 'כותרת עליונה',
						'drawer_title'      => 'כותרת הכניסה',
						'drawer_email'      => 'כפתור הכניסה עם מייל',
						'drawer_join_title' => 'כותרת ההרשמה',
						'drawer_join_text'  => 'טקסט ההרשמה',
						'drawer_join_btn'   => 'כפתור ההרשמה',
					);
					foreach ( $drawer as $k => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ocsl-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input id="ocsl-<?php echo esc_attr( $k ); ?>" class="large-text" name="<?php echo esc_attr( $f( $k ) ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2>הגנות</h2>
				<p class="description">כל הודעה עולה כסף, ולכן כל שליחה מוגבלת. ספירה רק להודעות שבאמת יצאו.</p>
				<table class="form-table" role="presentation">
					<?php
					$nums = array(
						'code_expiry'     => array( 'תוקף הקוד (שניות)', '' ),
						'max_attempts'    => array( 'ניסיונות לקוד', '' ),
						'resend_cooldown' => array( 'המתנה לפני שליחה חוזרת (שניות)', '' ),
						'phone_hourly'    => array( 'קודים למספר בשעה', '' ),
						'ip_hourly'       => array( 'קודים לכתובת IP בשעה', '' ),
						'daily_cap'       => array( 'תקרה יומית לכל האתר', 'בהגעה לתקרה השליחה נעצרת עד חצות ונשלח מייל למנהל האתר.' ),
						'low_balance'     => array( 'התראה על יתרה נמוכה מתחת ל-', 'הודעות. מתחת לזה מופיעה אזהרה במסכי התוסף ונשלח מייל אחד. 0 = בלי התראה.' ),
					);
					foreach ( $nums as $k => $meta ) :
						?>
						<tr>
							<th scope="row"><label for="ocsl-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td><input id="ocsl-<?php echo esc_attr( $k ); ?>" type="number" min="0" class="small-text" name="<?php echo esc_attr( $f( $k ) ); ?>" value="<?php echo esc_attr( (string) $s[ $k ] ); ?>">
								<?php if ( '' !== $meta[1] ) : ?><span class="description"><?php echo esc_html( $meta[1] ); ?></span><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="ocsl-alert_email">מייל להתראות על היתרה</label></th>
						<td><input id="ocsl-alert_email" type="email" dir="ltr" class="regular-text" name="<?php echo esc_attr( $f( 'alert_email' ) ); ?>" value="<?php echo esc_attr( (string) $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
							<p class="description">ריק = מייל מנהל האתר. מייל אחד כשהיתרה יורדת מתחת לסף, ועוד אחד אם היא נגמרת.</p></td>
					</tr>
				</table>

				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
		self::styles();
	}

	/**
	 * The way from "installed" to "working", for a shop that is not there yet: the licence key, a
	 * pack of messages, and the gate. Gone once all three stand. Hub sites only (test mode and agency
	 * mode have neither a key nor a balance).
	 */
	public static function setup_steps() {
		$t = OCSL_Sender::transport();
		if ( 'test' === $t || 'direct' === $t || OCSL_Hub::agency() ) {
			return;
		}
		$licensed = OCSL_Hub::allows();
		$balance  = OCSL_Hub::balance();
		$funded   = $licensed && null !== $balance && $balance > 0;
		$gate     = (string) OCSL_Settings::get( 'gate' );
		$live     = $funded && OCSL_Sender::available();
		if ( $live && 'off' !== $gate ) {
			return;
		}
		$modes = array(
			'required' => 'חובה',
			'optional' => 'רשות',
		);
		$steps = array(
			array(
				$licensed,
				'חיבור מפתח הרישיון',
				$licensed
					? 'האתר מחובר ל-OC Pluginz.'
					: 'המפתח נשלח אליכם במייל כשהורדתם את התוסף (ההורדה חינמית), ומופיע גם באזור האישי ב-OC Pluginz.',
				$licensed ? '' : OCSL_Hub::settings_url(),
				'לחיבור המפתח',
			),
			array(
				$funded,
				'רכישת חבילת הודעות',
				$funded
					? sprintf( 'ביתרה %s הודעות.', number_format_i18n( (int) $balance ) )
					: 'התוסף חינמי, והקודים נשלחים מיתרת הודעות SMS. את החבילה רוכשים כאן, מתוך התוסף, בתשלום מאובטח. ההודעות לא פגות.',
				( $funded || ! $licensed ) ? '' : self::url( 'credits' ),
				'לחבילות ה-SMS',
			),
			array(
				$live && 'off' !== $gate,
				'הכניסה עם SMS פעילה בקופה',
				$live
					? 'שער הטלפון כבוי בהגדרות למטה. הכניסה עם SMS פועלת רק בעמוד "החשבון שלי" ובשורטקוד.'
					: sprintf( 'ברגע שיש מפתח ויתרה, שער הטלפון עולה לבד (מצב "%s"). עד אז הקופה פתוחה לאורחים כרגיל.', isset( $modes[ $gate ] ) ? $modes[ $gate ] : 'כבוי' ),
				'',
				'',
			),
		);
		echo '<ol class="ocsl-setup" aria-label="שלבי ההפעלה">';
		$first = true;
		foreach ( $steps as $i => $st ) {
			$now = ! $st[0] && $first;
			if ( ! $st[0] ) {
				$first = false;
			}
			echo '<li class="' . esc_attr( $st[0] ? 'is-done' : ( $now ? 'is-now' : '' ) ) . '">';
			echo '<span class="ocsl-setup-n" aria-hidden="true">' . ( $st[0] ? '&#10003;' : (int) ( $i + 1 ) ) . '</span>';
			echo '<span class="ocsl-setup-b"><strong>' . esc_html( $st[1] ) . ( $st[0] ? ' <span class="screen-reader-text">(בוצע)</span>' : '' ) . '</strong>';
			echo '<span>' . esc_html( $st[2] ) . '</span>';
			if ( $now && '' !== $st[3] ) {
				echo '<a class="button button-primary" href="' . esc_url( $st[3] ) . '">' . esc_html( $st[4] ) . '</a>';
			}
			echo '</span></li>';
		}
		echo '</ol>';
	}

	/* ---------------- The packs ---------------- */

	public static function refresh_balance() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'אין הרשאה.' );
		}
		check_admin_referer( 'ocsl_balance' );
		OCSL_Hub::refresh();
		$res = OCSL_Hub::fetch_balance();
		$arg = is_wp_error( $res ) ? array( 'ocsl_err' => rawurlencode( $res->get_error_message() ) ) : array( 'ocsl_ok' => 1 );
		wp_safe_redirect( add_query_arg( $arg, self::url( 'credits' ) ) );
		exit;
	}

	/** The packs to show: the hub's, or the built-in list. */
	private static function packs() {
		$c     = OCSL_Hub::credits();
		$packs = ( isset( $c['packs'] ) && is_array( $c['packs'] ) && $c['packs'] ) ? $c['packs'] : self::PACKS;
		$out   = array();
		foreach ( $packs as $p ) {
			$messages = isset( $p['messages'] ) ? (int) $p['messages'] : ( isset( $p['credits'] ) ? (int) $p['credits'] : 0 );
			// The hub's 'price' is a display string with VAT; 'price_ex' is the number before VAT this screen adds VAT to.
			$price = isset( $p['price_ex'] ) ? (float) $p['price_ex'] : ( isset( $p['price'] ) ? (float) preg_replace( '/[^0-9.]/', '', (string) $p['price'] ) : 0 );
			if ( $messages <= 0 || $price <= 0 ) {
				continue;
			}
			$out[] = array(
				'key'      => isset( $p['key'] ) ? (string) $p['key'] : 'sms' . $messages,
				'messages' => $messages,
				'price'    => $price,
				'url'      => isset( $p['url'] ) ? (string) $p['url'] : '',
			);
		}
		return $out;
	}

	private static function money( $n ) {
		$s = number_format( (float) $n, 2, '.', ',' );
		return '₪' . ( '.00' === substr( $s, -3 ) ? substr( $s, 0, -3 ) : $s );
	}

	public static function render_credits() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// A shop with no messages is here to buy them, and comes back here once it has paid: ask the hub sooner.
		$known = OCSL_Hub::balance();
		OCSL_Hub::refresh_if_stale( ( null === $known || $known <= 0 ) ? 60 : 600 );
		$t       = OCSL_Sender::transport();
		$balance = OCSL_Hub::balance();
		$linked  = OCSL_Hub::allows();
		$packs   = self::packs();
		$pick    = 2 < count( $packs ) ? $packs[2]['key'] : ( $packs ? $packs[0]['key'] : '' );
		$err     = isset( $_GET['ocsl_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ocsl_err'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent    = 0;
		$today   = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		foreach ( OCSL_Sender::entries() as $e ) {
			if ( ! empty( $e['ok'] ) && (int) $e['t'] >= $today ) {
				++$sent;
			}
		}
		?>
		<div class="wrap ocsl-admin">
			<h1>חבילות SMS</h1>
			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php elseif ( isset( $_GET['ocsl_ok'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( null === $balance ? 'היתרה עודכנה.' : sprintf( 'היתרה עודכנה: %s הודעות.', number_format_i18n( $balance ) ) ); ?></p></div>
			<?php endif; ?>
			<?php self::setup_steps(); ?>

			<div class="ocsl-balance">
				<div>
					<span class="ocsl-balance-l">יתרת הודעות</span>
					<strong class="ocsl-balance-n"><?php echo 'hub' === $t || 'none' === $t ? ( null === $balance ? '—' : esc_html( number_format_i18n( $balance ) ) ) : '∞'; ?></strong>
					<span class="ocsl-balance-s">
						<?php
						if ( 'test' === $t ) {
							echo esc_html( 'מצב בדיקה: לא נשלחות הודעות.' );
						} elseif ( 'direct' === $t ) {
							echo esc_html( 'מצב סוכנות: שליחה ישירה, בלי יתרה.' );
						} else {
							echo esc_html( sprintf( 'קודים שנשלחו היום: %s', number_format_i18n( $sent ) ) );
						}
						?>
					</span>
				</div>
				<?php if ( 'hub' === $t || 'none' === $t ) : ?>
					<form method="post" class="ocsl-balance-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ocsl_balance' ); ?>
						<input type="hidden" name="action" value="ocsl_balance">
						<button class="button">רענון היתרה</button>
					</form>
				<?php endif; ?>
			</div>

			<div class="ocsl-buy">
				<h2><span class="dashicons dashicons-cart" aria-hidden="true"></span> רכישת חבילת הודעות SMS</h2>
				<p class="description">בחרו חבילה, התשלום מתבצע בדף תשלום מאובטח של Cardcom. ההודעות נכנסות ליתרה מיד עם אישור התשלום.</p>
				<div class="ocsl-packs" role="radiogroup" aria-label="חבילות הודעות">
					<?php foreach ( $packs as $p ) : ?>
						<?php $per = round( $p['price'] / $p['messages'] * 100, 1 ); ?>
						<label class="ocsl-pack">
							<input type="radio" name="ocsl_pack" value="<?php echo esc_attr( $p['key'] ); ?>" data-url="<?php echo esc_url( $p['url'] ); ?>" <?php checked( $pick, $p['key'] ); ?>>
							<span class="ocsl-pack-in">
								<span class="ocsl-pack-top">
									<strong><?php echo esc_html( number_format_i18n( $p['messages'] ) ); ?> הודעות</strong>
									<strong class="ocsl-pack-price"><?php echo esc_html( self::money( $p['price'] ) ); ?></strong>
								</span>
								<span class="ocsl-pack-bottom">
									<span><?php echo esc_html( rtrim( rtrim( number_format( $per, 1, '.', '' ), '0' ), '.' ) ); ?> אג׳ להודעה</span>
									<span>כולל מע״מ <?php echo esc_html( self::money( round( $p['price'] * ( 1 + self::VAT ), 2 ) ) ); ?></span>
								</span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p>
					<a class="button button-primary button-hero ocsl-pay" href="#" target="_blank" rel="noopener" aria-disabled="true">לתשלום מאובטח</a>
					<?php if ( $linked ) : ?>
						<span class="ocsl-pay-note description" hidden>קישורי התשלום עוד לא הגיעו מ-OC Pluginz. לחצו על "רענון היתרה", ואם הכפתור נשאר כבוי כתבו לנו.</span>
					<?php else : ?>
						<span class="ocsl-pay-note description" hidden>הרכישה נפתחת ברגע שהאתר מחובר ל-OC Pluginz עם מפתח הרישיון. <a href="<?php echo esc_url( OCSL_Hub::settings_url() ); ?>">לחיבור המפתח</a></span>
					<?php endif; ?>
				</p>
				<p class="ocsl-pay-back description" role="status" hidden>התשלום נפתח בלשונית חדשה. אחרי התשלום חזרו לכאן, והיתרה תתעדכן לבד.</p>
				<ul class="ocsl-notes">
					<li>התשלום נרשם על חשבון OC Pluginz שבו נמצא הרישיון של האתר. בדף התשלום השתמשו באותו מייל שאליו נשלח מפתח הרישיון, כדי שההודעות ייכנסו ליתרה של האתר הזה.</li>
					<li>החבילות לא פגות בסוף החודש - היתרה נשארת עד שמשתמשים בה.</li>
					<li>חשבונית מס/קבלה נשלחת למייל של מנהל החשבון מיד אחרי התשלום.</li>
					<li>המחירים לא כוללים מע״מ (<?php echo esc_html( (string) ( self::VAT * 100 ) ); ?>%). הסכום שיחויב מופיע מתחת לכל חבילה.</li>
					<li>כל קוד כניסה הוא הודעה אחת. הודעה בעברית ארוכה מ-70 תווים נספרת כשתיים.</li>
				</ul>
			</div>
		</div>
		<script>
		(function () {
			var pay = document.querySelector('.ocsl-pay'), note = document.querySelector('.ocsl-pay-note');
			function sync() {
				var r = document.querySelector('input[name=ocsl_pack]:checked'), url = r ? r.getAttribute('data-url') : '';
				if (url) { pay.href = url; pay.removeAttribute('aria-disabled'); pay.classList.remove('disabled'); note.hidden = true; }
				else { pay.href = '#'; pay.setAttribute('aria-disabled', 'true'); pay.classList.add('disabled'); note.hidden = false; }
			}
			// After the payment tab, the shop owner comes back to this one: fetch the new balance for them, once.
			var back = document.querySelector('.ocsl-pay-back'), form = document.querySelector('.ocsl-balance-form'), went = false, asked = false;
			function refresh() {
				if (!went || asked || !form || document.hidden) { return; }
				asked = true;
				if (back) { back.textContent = 'מעדכנים את היתרה...'; }
				form.submit();
			}
			pay.addEventListener('click', function (e) {
				if (pay.getAttribute('aria-disabled')) { e.preventDefault(); return; }
				if (back) { back.hidden = false; }
				// Not before the new tab has taken the focus away.
				setTimeout(function () { went = true; }, 1500);
			});
			window.addEventListener('focus', refresh);
			document.addEventListener('visibilitychange', refresh);
			document.querySelectorAll('input[name=ocsl_pack]').forEach(function (r) { r.addEventListener('change', sync); });
			sync();
		})();
		</script>
		<?php
		self::styles();
	}

	/* ---------------- The log ---------------- */

	public static function render_log() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$labels = array(
			'hub'    => 'OC Pluginz',
			'direct' => 'ActiveTrail ישיר',
			'test'   => 'בדיקה (לא נשלח)',
			'none'   => 'לא זמין',
		);
		$rows   = OCSL_Sender::entries();
		?>
		<div class="wrap ocsl-admin">
			<h1>יומן שליחות</h1>
			<p class="description">100 השליחות האחרונות. המספרים מוצגים חלקית.</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>מתי</th><th>מספר</th><th>דרך</th><th>תוצאה</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4">עוד לא נשלחו קודים.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $r['t'] ) ); ?></td>
						<td dir="ltr" style="text-align:right"><?php echo esc_html( (string) $r['phone'] ); ?></td>
						<td><?php echo esc_html( isset( $labels[ $r['via'] ] ) ? $labels[ $r['via'] ] : (string) $r['via'] ); ?></td>
						<td><?php echo ! empty( $r['ok'] ) ? '<span style="color:#15803d">נשלח</span>' : '<span style="color:#b32d2e">' . esc_html( 'נכשל: ' . (string) $r['err'] ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		self::styles();
	}

	/** The settings screen: WordPress's color picker, and the popup's stylesheet for the live preview. */
	public static function admin_assets() {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		$css = plugin_dir_path( OCSL_FILE ) . 'assets/css/ocsl.css';
		wp_enqueue_style( 'ocsl-preview', plugin_dir_url( OCSL_FILE ) . 'assets/css/ocsl.css', array(), OCSL_VERSION . ( file_exists( $css ) ? '.' . filemtime( $css ) : '' ) );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){var pv=document.getElementById("ocsl-preview");if(!pv){return;}var set=function(v,val){if(val){pv.style.setProperty(v,val);}else{pv.style.removeProperty(v);}};$(".ocsl-color").each(function(){var $i=$(this),v=$i.data("var");$i.wpColorPicker({change:function(e,ui){set(v,ui.color.toString());},clear:function(){set(v,"");}});});var shapes={soft:["10px","10px"],round:["999px","24px"],square:["0px","0px"]};$(".ocsl-shape").on("change",function(){var s=shapes[this.value]||shapes.soft;set("--ocsl-btn-radius",s[0]);set("--ocsl-field-radius",s[1]);});$("#ocsl-radius").on("input",function(){set("--ocsl-radius",this.value+"px");$(this).next("output").text(this.value);});$("#ocsl-overlay").on("input",function(){set("--ocsl-overlay",this.value/100);$(this).next("output").text(this.value);});});' );
	}

	private static function styles() {
		?>
		<style>
		.ocsl-admin .ocsl-state{display:inline-block;padding:8px 12px;border-radius:6px;font-size:14px;margin:6px 0 4px}
		.ocsl-admin .ocsl-state-ok{background:#ecfdf3;color:#05603a}
		.ocsl-admin .ocsl-state-warn{background:#fffaeb;color:#93370d}
		.ocsl-admin .ocsl-state-err{background:#fef3f2;color:#b42318}
		.ocsl-balance{display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:860px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 22px;margin:14px 0 20px}
		.ocsl-balance-l{display:block;color:#646970;font-size:13px}
		.ocsl-balance-n{display:block;font-size:30px;line-height:1.2;margin:2px 0}
		.ocsl-balance-s{color:#646970;font-size:13px}
		.ocsl-buy{max-width:860px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 22px}
		.ocsl-buy h2{display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:18px}
		.ocsl-buy h2 .dashicons{color:#5b4dff}
		.ocsl-design{display:grid;grid-template-columns:minmax(0,1fr) 470px;gap:28px;align-items:start;max-width:1240px}
		.ocsl-preview-wrap{position:sticky;top:46px}
		.ocsl-design .form-table th{width:200px}
		@media (max-width:1180px){.ocsl-design{grid-template-columns:1fr}.ocsl-preview-wrap{position:static}}
		.ocsl-packs{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0}
		.ocsl-pack{position:relative;cursor:pointer;display:block}
		.ocsl-pack input{position:absolute;opacity:0;pointer-events:none}
		.ocsl-pack-in{display:flex;flex-direction:column;gap:6px;border:1.5px solid #dcdcde;border-radius:10px;padding:14px 16px;background:#fff;transition:border-color .15s,background .15s}
		.ocsl-pack:hover .ocsl-pack-in{border-color:#a7aaad}
		.ocsl-pack input:checked + .ocsl-pack-in{border-color:#5b4dff;background:#f6f5ff;box-shadow:0 0 0 1px #5b4dff}
		.ocsl-pack input:checked + .ocsl-pack-in::before{content:"\2713";position:absolute;top:-9px;right:-9px;width:22px;height:22px;border-radius:50%;background:#5b4dff;color:#fff;font-size:13px;line-height:22px;text-align:center}
		.ocsl-pack input:focus-visible + .ocsl-pack-in{outline:2px solid #5b4dff;outline-offset:2px}
		.ocsl-pack-top,.ocsl-pack-bottom{display:flex;justify-content:space-between;gap:8px}
		.ocsl-pack-top strong{font-size:16px}
		.ocsl-pack-price{font-size:19px !important}
		.ocsl-pack-bottom{color:#646970;font-size:12.5px}
		.ocsl-pay.disabled{opacity:.55;cursor:not-allowed}
		.ocsl-notes{color:#646970;font-size:13px;margin:12px 0 0;list-style:disc;padding-right:18px}
		.ocsl-pay-back[hidden],.ocsl-pay-note[hidden]{display:none}
		.ocsl-setup{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:860px;margin:14px 0 18px;padding:0;list-style:none}
		.ocsl-setup li{display:flex;gap:12px;align-items:flex-start;margin:0;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:10px;color:#646970}
		.ocsl-setup li.is-now{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
		.ocsl-setup-n{flex:0 0 auto;width:26px;height:26px;border-radius:50%;background:#f0f0f1;color:#50575e;font-weight:600;font-size:13px;line-height:26px;text-align:center}
		.ocsl-setup li.is-now .ocsl-setup-n{background:#2271b1;color:#fff}
		.ocsl-setup li.is-done .ocsl-setup-n{background:#ecfdf3;color:#05603a}
		.ocsl-setup-b{display:flex;flex-direction:column;gap:4px;align-items:flex-start;min-width:0;font-size:13px;line-height:1.5}
		.ocsl-setup-b strong{font-size:14px;color:#1d2327}
		.ocsl-setup-b .button{margin-top:6px}
		@media (max-width:960px){.ocsl-setup{grid-template-columns:1fr}}
		@media (max-width:900px){.ocsl-packs{grid-template-columns:1fr 1fr}}
		@media (max-width:600px){.ocsl-packs{grid-template-columns:1fr}}
		</style>
		<?php
	}
}
