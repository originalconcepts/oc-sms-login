<?php
/**
 * OC Pluginz — the shared admin chrome of the OC Pluginz suite.
 *
 * One top-level "OC Pluginz" menu for every plugin of the suite that is
 * installed on the site: an overview page (a card per installed plugin), one
 * entry per plugin, and a last entry that opens ocpluginz.com. A plugin's
 * other screens are hidden pages reached through a tab strip that this class
 * draws above every screen of the suite, so the menu stays short however many
 * plugins the customer has.
 *
 * Loaded by loader.php (the newest bundled copy wins). Plugins register with
 * oc_pluginz_register( $slug, $args ) — see register() for the arguments.
 *
 * Canonical copy: originalconcepts/ocpluginz-hub, lib/oc-pluginz/.
 * Do not edit the copy inside a plugin — update the canonical one and copy it.
 *
 * @package OC_Pluginz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'OC_Pluginz', false ) ) {
	return;
}

final class OC_Pluginz {

	const VERSION   = '1.0.0';
	const MENU_SLUG = 'oc-pluginz';
	const SITE_URL  = 'https://ocpluginz.com/';
	const POSITION  = 58;
	const DOMAIN    = 'oc-pluginz';

	/** @var bool */
	private static $booted = false;

	/** @var array<string,array> slug => normalised registration */
	private static $plugins = array();

	/** @var array<string,bool> slug => pages were added to the menu */
	private static $added = array();

	/** @var array<string,string> page slug => hook suffix (= screen id) */
	private static $hooks = array();

	/** @var array<string,string> screen id => owning plugin slug */
	private static $owner = array();

	/** @var string|null the parent's capability for this user, once computed */
	private static $cap = null;

	/* ------------------------------------------------------------------ boot */

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'menu_parent' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_plugins' ), 10 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_finish' ), 9999 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'in_admin_header', array( __CLASS__, 'strip' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_head', array( __CLASS__, 'head' ) );
		add_action( 'admin_footer', array( __CLASS__, 'footer' ) );
	}

	/**
	 * Register a plugin of the suite.
	 *
	 * @param string $slug Plugin slug (e.g. 'oc-rma'); also the key of its card.
	 * @param array  $args {
	 *   @type string   $name         Product name shown in the menu, the strip and the card. Required.
	 *   @type string   $page         Slug of the main admin page (default: $slug).
	 *   @type string   $page_title   <title> of the main page and its tab label (default: name).
	 *   @type string   $menu_title   Label of the entry under OC Pluginz (default: name).
	 *   @type string   $capability   Capability of the main page (default: manage_woocommerce).
	 *   @type callable $callback     Renders the main page. Required unless $menu is false.
	 *   @type callable $on_load      Runs on load-{hook} of the main page (optional).
	 *   @type bool     $menu         false when the entry is created elsewhere (a CPT with show_in_menu => 'oc-pluginz'). Default true.
	 *   @type string   $url          URL of the main screen (default: admin.php?page={page}).
	 *   @type string   $submenu_file Submenu slug to highlight (default: page; a CPT passes 'edit.php?post_type=…').
	 *   @type string[] $screens      Extra screen ids that belong to the plugin (a CPT's 'edit-{cpt}', '{cpt}').
	 *   @type string   $description  One line for the overview card.
	 *   @type string   $icon         Icon URL for the overview card (optional).
	 *   @type string   $version      Plugin version for the overview card (optional).
	 *   @type string   $account_url  URL of the licence/account screen or tab (optional; adds a link on the card).
	 *   @type callable $status       Returns a short status line for the card, e.g. the licence state (optional).
	 *   @type int      $position     Order among the suite's entries (default 50; lower first, then by name).
	 *   @type array[]  $pages        Hidden sub-pages, each: slug, title, callback, capability (default: plugin's),
	 *                                tab (bool, default true: show in the strip), on_load (callable, optional).
	 * }
	 */
	public static function register( $slug, $args ) {
		$slug = sanitize_key( (string) $slug );
		$args = (array) $args;
		if ( '' === $slug || empty( $args['name'] ) ) {
			return;
		}
		$p = array(
			'slug'         => $slug,
			'name'         => (string) $args['name'],
			'page'         => isset( $args['page'] ) ? (string) $args['page'] : $slug,
			'capability'   => isset( $args['capability'] ) ? (string) $args['capability'] : 'manage_woocommerce',
			'callback'     => isset( $args['callback'] ) ? $args['callback'] : null,
			'on_load'      => isset( $args['on_load'] ) ? $args['on_load'] : null,
			'menu'         => ! isset( $args['menu'] ) || false !== $args['menu'],
			'screens'      => isset( $args['screens'] ) ? array_map( 'strval', (array) $args['screens'] ) : array(),
			'description'  => isset( $args['description'] ) ? (string) $args['description'] : '',
			'icon'         => isset( $args['icon'] ) ? (string) $args['icon'] : '',
			'version'      => isset( $args['version'] ) ? (string) $args['version'] : '',
			'account_url'  => isset( $args['account_url'] ) ? (string) $args['account_url'] : '',
			'status'       => isset( $args['status'] ) ? $args['status'] : null,
			'position'     => isset( $args['position'] ) ? (int) $args['position'] : 50,
			'pages'        => array(),
		);
		$p['page_title']   = isset( $args['page_title'] ) ? (string) $args['page_title'] : $p['name'];
		$p['menu_title']   = isset( $args['menu_title'] ) ? (string) $args['menu_title'] : $p['name'];
		$p['url']          = isset( $args['url'] ) ? (string) $args['url'] : self::url( $p['page'] );
		$p['submenu_file'] = isset( $args['submenu_file'] ) ? (string) $args['submenu_file'] : $p['page'];

		if ( ! empty( $args['pages'] ) && is_array( $args['pages'] ) ) {
			foreach ( $args['pages'] as $sub ) {
				$sub = (array) $sub;
				if ( empty( $sub['slug'] ) || empty( $sub['title'] ) ) {
					continue;
				}
				$p['pages'][] = array(
					'slug'       => (string) $sub['slug'],
					'title'      => (string) $sub['title'],
					'capability' => isset( $sub['capability'] ) ? (string) $sub['capability'] : $p['capability'],
					'callback'   => isset( $sub['callback'] ) ? $sub['callback'] : null,
					'tab'        => ! isset( $sub['tab'] ) || false !== $sub['tab'],
					'on_load'    => isset( $sub['on_load'] ) ? $sub['on_load'] : null,
				);
			}
		}

		self::$plugins[ $slug ] = $p;
		foreach ( $p['screens'] as $screen ) {
			self::$owner[ $screen ] = $slug;
		}

		// Registered from inside admin_menu (after the parent exists): add the pages right away.
		if ( did_action( 'admin_menu' ) || doing_action( 'admin_menu' ) ) {
			self::add_pages( $slug );
		}
	}

	/* ---------------------------------------------------------------- queries */

	/** Hook suffix (= screen id) of a page registered through the library, '' before admin_menu ran. */
	public static function hook( $page ) {
		return isset( self::$hooks[ $page ] ) ? self::$hooks[ $page ] : '';
	}

	/** Slug of the plugin that owns a screen id / hook suffix, '' if none. */
	public static function owner( $screen_id ) {
		$screen_id = (string) $screen_id;
		return isset( self::$owner[ $screen_id ] ) ? self::$owner[ $screen_id ] : '';
	}

	/** Slug of the plugin that owns the current admin screen, '' if none. */
	public static function current() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen ? self::owner( $screen->id ) : '';
	}

	/** admin.php?page={page}. */
	public static function url( $page ) {
		return admin_url( 'admin.php?page=' . rawurlencode( (string) $page ) );
	}

	public static function dashboard_url() {
		return self::url( self::MENU_SLUG );
	}

	/** The registered plugins, keyed by slug. */
	public static function plugins() {
		return self::$plugins;
	}

	/**
	 * The parent's capability for the current user: the first one they hold out
	 * of manage_woocommerce, manage_options and the registered plugins' own, so
	 * the overview is open to anyone who can use at least one plugin.
	 */
	public static function cap() {
		if ( null !== self::$cap ) {
			return self::$cap;
		}
		$caps = array( 'manage_woocommerce', 'manage_options' );
		foreach ( self::$plugins as $p ) {
			$caps[] = $p['capability'];
		}
		self::$cap = 'manage_options';
		foreach ( array_unique( $caps ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				self::$cap = $cap;
				break;
			}
		}
		return apply_filters( 'oc_pluginz_cap', self::$cap );
	}

	/* ------------------------------------------------------------------- menu */

	public static function menu_parent() {
		$cap  = self::cap();
		$hook = add_menu_page( 'OC Pluginz', 'OC Pluginz', $cap, self::MENU_SLUG, array( __CLASS__, 'render_dashboard' ), self::icon(), self::POSITION );
		add_submenu_page( self::MENU_SLUG, __( 'סקירה', 'oc-pluginz' ), __( 'סקירה', 'oc-pluginz' ), $cap, self::MENU_SLUG, array( __CLASS__, 'render_dashboard' ) );
		if ( $hook ) {
			self::$owner[ $hook ] = self::MENU_SLUG;
		}
	}

	public static function menu_plugins() {
		foreach ( array_keys( self::$plugins ) as $slug ) {
			self::add_pages( $slug );
		}
	}

	private static function add_pages( $slug ) {
		if ( isset( self::$added[ $slug ] ) || ! isset( self::$plugins[ $slug ] ) ) {
			return;
		}
		self::$added[ $slug ] = true;
		$p = self::$plugins[ $slug ];

		if ( $p['menu'] && $p['callback'] ) {
			$hook = add_submenu_page( self::MENU_SLUG, $p['page_title'], $p['menu_title'], $p['capability'], $p['page'], $p['callback'] );
			if ( $hook ) {
				self::$hooks[ $p['page'] ] = $hook;
				self::$owner[ $hook ]      = $slug;
				if ( $p['on_load'] ) {
					add_action( 'load-' . $hook, $p['on_load'] );
				}
			}
		}

		foreach ( $p['pages'] as $sub ) {
			if ( ! $sub['callback'] ) {
				continue;
			}
			// A hidden page: registered without a parent, reached from the strip's tabs.
			$hook = add_submenu_page( '', $sub['title'], $sub['title'], $sub['capability'], $sub['slug'], $sub['callback'] );
			if ( $hook ) {
				self::$hooks[ $sub['slug'] ] = $hook;
				self::$owner[ $hook ]        = $slug;
				$title = $sub['title'];
				add_action(
					'load-' . $hook,
					function () use ( $title ) {
						// A hidden page has no menu entry for WordPress to take its <title> from.
						if ( empty( $GLOBALS['title'] ) ) {
							$GLOBALS['title'] = $title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
						}
					},
					1
				);
				if ( $sub['on_load'] ) {
					add_action( 'load-' . $hook, $sub['on_load'] );
				}
			}
		}
	}

	/** Last: the link to the site, then a stable order — overview, the plugins by position and name, the link. */
	public static function menu_finish() {
		global $submenu;
		// The hidden pages must not stay listed under the empty parent: WordPress would resolve their parent to "" when it
		// draws the menu and drop the highlight of OC Pluginz. They stay registered (their hook names were computed already).
		if ( ! empty( $submenu[''] ) && is_array( $submenu[''] ) ) {
			foreach ( $submenu[''] as $i => $item ) {
				if ( isset( $item[2], self::$hooks[ $item[2] ] ) ) {
					unset( $submenu[''][ $i ] );
				}
			}
			if ( empty( $submenu[''] ) ) {
				unset( $submenu[''] );
			}
		}
		add_submenu_page( self::MENU_SLUG, 'OC Pluginz', __( 'לכל התוספים', 'oc-pluginz' ) . ' &#8599;', self::cap(), self::SITE_URL );

		if ( empty( $submenu[ self::MENU_SLUG ] ) || ! is_array( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}
		$by_page = array();
		foreach ( self::$plugins as $p ) {
			$by_page[ $p['submenu_file'] ] = $p;
		}
		$site  = untrailingslashit( self::SITE_URL );
		$items = array_values( $submenu[ self::MENU_SLUG ] );
		$keyed = array();
		foreach ( $items as $i => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( self::MENU_SLUG === $slug ) {
				$weight = -1;
			} elseif ( $site === $slug || self::SITE_URL === $slug ) {
				$weight = 100000;
			} else {
				$weight = isset( $by_page[ $slug ] ) ? $by_page[ $slug ]['position'] : 50;
			}
			$keyed[] = array( $weight, isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '', $i, $item );
		}
		usort(
			$keyed,
			function ( $a, $b ) {
				if ( $a[0] !== $b[0] ) {
					return $a[0] < $b[0] ? -1 : 1;
				}
				$c = strnatcasecmp( $a[1], $b[1] );
				return $c ? $c : ( $a[2] < $b[2] ? -1 : 1 );
			}
		);
		$sorted = array();
		foreach ( $keyed as $k ) {
			$sorted[] = $k[3];
		}
		$submenu[ self::MENU_SLUG ] = $sorted; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/** Keep "OC Pluginz" open and the plugin's entry highlighted on its hidden pages. */
	public static function parent_file( $parent_file ) {
		$slug = self::current();
		return ( '' !== $slug && self::MENU_SLUG !== $slug ) ? self::MENU_SLUG : $parent_file;
	}

	public static function submenu_file( $submenu_file, $parent_file ) {
		$slug = self::current();
		if ( '' !== $slug && self::MENU_SLUG !== $slug && isset( self::$plugins[ $slug ] ) ) {
			return self::$plugins[ $slug ]['submenu_file'];
		}
		return $submenu_file;
	}

	/** Monochrome "OC" mark for the admin menu (WordPress recolours fill="" itself). */
	public static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill="#a7aaad" fill-rule="evenodd" d="M.5 10a5.1 5.1 0 1 0 10.2 0a5.1 5.1 0 1 0-10.2 0zM3 10a2.6 2.6 0 1 0 5.2 0a2.6 2.6 0 1 0-5.2 0z"/>'
			. '<path fill="#a7aaad" d="M18.19 6.59A5.1 5.1 0 1 0 18.19 13.41L16.33 11.74A2.6 2.6 0 1 1 16.33 8.26Z"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/* ------------------------------------------------------------------ strip */

	/** Marks the screens that carry the strip (the mobile layout needs to know). */
	public static function body_class( $classes ) {
		$slug = self::current();
		if ( '' !== $slug && self::MENU_SLUG !== $slug && isset( self::$plugins[ $slug ] ) ) {
			$classes .= ' ocpz-screen';
		}
		return $classes;
	}

	/** The tab strip above every screen of the suite. */
	public static function strip() {
		$slug = self::current();
		if ( '' === $slug || self::MENU_SLUG === $slug || ! isset( self::$plugins[ $slug ] ) ) {
			return;
		}
		$p      = self::$plugins[ $slug ];
		$screen = get_current_screen();
		$tabs   = array();
		if ( $p['menu'] ) {
			if ( current_user_can( $p['capability'] ) ) {
				$tabs[] = array( $p['page_title'], $p['url'], self::hook( $p['page'] ) === $screen->id );
			}
		} else {
			$tabs[] = array( $p['page_title'], $p['url'], in_array( $screen->id, $p['screens'], true ) );
		}
		foreach ( $p['pages'] as $sub ) {
			if ( $sub['tab'] && current_user_can( $sub['capability'] ) && isset( self::$hooks[ $sub['slug'] ] ) ) {
				$tabs[] = array( $sub['title'], self::url( $sub['slug'] ), self::$hooks[ $sub['slug'] ] === $screen->id );
			}
		}
		?>
		<div class="ocpz-strip" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<a class="ocpz-strip__brand" href="<?php echo esc_url( self::dashboard_url() ); ?>">
				<span class="ocpz-strip__mark" aria-hidden="true"><?php echo self::mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span>OC Pluginz</span>
			</a>
			<span class="ocpz-strip__sep" aria-hidden="true"></span>
			<span class="ocpz-strip__name"><?php echo esc_html( $p['name'] ); ?></span>
			<?php if ( count( $tabs ) > 1 ) : ?>
				<nav class="ocpz-strip__tabs" aria-label="<?php echo esc_attr( $p['name'] ); ?>">
					<?php foreach ( $tabs as $t ) : ?>
						<a class="ocpz-strip__tab<?php echo $t[2] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $t[1] ); ?>"<?php echo $t[2] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $t[0] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The coloured "OC" mark used inline (strip and overview). */
	private static function mark() {
		return '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true">'
			. '<path fill="#f0485b" fill-rule="evenodd" d="M.5 10a5.1 5.1 0 1 0 10.2 0a5.1 5.1 0 1 0-10.2 0zM3 10a2.6 2.6 0 1 0 5.2 0a2.6 2.6 0 1 0-5.2 0z"/>'
			. '<path fill="#6c4de6" d="M18.19 6.59A5.1 5.1 0 1 0 18.19 13.41L16.33 11.74A2.6 2.6 0 1 1 16.33 8.26Z"/>'
			. '</svg>';
	}

	public static function head() {
		$slug = self::current();
		if ( '' === $slug ) {
			return;
		}
		?>
		<style id="oc-pluginz-chrome">
		.ocpz-strip{display:flex;align-items:center;gap:14px;min-height:46px;padding:0 20px;background:#fff;border-bottom:1px solid #dcdcde;font-size:13px}
		.ocpz-strip__brand{display:inline-flex;align-items:center;gap:8px;color:#1d2327;font-weight:600;text-decoration:none}
		.ocpz-strip__brand:hover{color:#2271b1}
		.ocpz-strip__mark{display:inline-flex;line-height:0}
		.ocpz-strip__sep{width:1px;height:18px;background:#dcdcde}
		.ocpz-strip__name{font-weight:600;color:#1d2327}
		.ocpz-strip__tabs{display:flex;align-items:stretch;gap:2px;margin-inline-start:18px;align-self:stretch}
		.ocpz-strip__tab{display:inline-flex;align-items:center;padding:0 12px;color:#50575e;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
		.ocpz-strip__tab:hover{color:#1d2327}
		.ocpz-strip__tab.is-current{color:#2271b1;border-bottom-color:#2271b1;font-weight:600}
		@media (max-width:782px){.ocpz-strip{flex-wrap:wrap;padding:8px 12px;gap:10px;margin-top:46px}.ocpz-screen #wpbody{padding-top:0}.ocpz-strip__tabs{margin-inline-start:0;width:100%;overflow-x:auto}}
		.ocpz-dash{max-width:1100px}
		.ocpz-dash__lead{color:#50575e;margin:4px 0 20px}
		.ocpz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
		.ocpz-card{display:flex;flex-direction:column;gap:10px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
		.ocpz-card__head{display:flex;align-items:center;gap:12px}
		.ocpz-card__icon{width:44px;height:44px;border-radius:10px;flex:0 0 auto;object-fit:cover}
		.ocpz-card__name{font-size:15px;font-weight:600;color:#1d2327}
		.ocpz-card__ver{color:#787c82;font-size:12px;margin-inline-start:6px;font-weight:400}
		.ocpz-card__desc{color:#50575e;margin:0;min-height:1.4em}
		.ocpz-card__status{color:#50575e;font-size:12px;margin:0}
		.ocpz-card__actions{display:flex;gap:8px;align-items:center;margin-top:auto;padding-top:6px}
		.ocpz-dash__more{margin-top:28px;padding-top:18px;border-top:1px solid #dcdcde;color:#50575e}
		</style>
		<?php
	}

	/** The site link in the menu opens in a new tab. */
	public static function footer() {
		?>
		<script>(function(){var a=document.querySelectorAll('#adminmenu a[href^="<?php echo esc_js( untrailingslashit( self::SITE_URL ) ); ?>"]');for(var i=0;i<a.length;i++){a[i].target='_blank';a[i].rel='noopener';}})();</script>
		<?php
	}

	/* -------------------------------------------------------------- overview */

	public static function render_dashboard() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לצפות בעמוד זה.', 'oc-pluginz' ) );
		}
		$plugins = self::$plugins;
		uasort(
			$plugins,
			function ( $a, $b ) {
				if ( $a['position'] !== $b['position'] ) {
					return $a['position'] < $b['position'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		?>
		<div class="wrap ocpz-dash">
			<h1 style="display:flex;align-items:center;gap:10px"><?php echo self::mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> OC Pluginz</h1>
			<p class="ocpz-dash__lead"><?php esc_html_e( 'כל התוספים של OC Pluginz שמותקנים באתר, במקום אחד.', 'oc-pluginz' ); ?></p>
			<div class="ocpz-grid">
				<?php
				$shown = 0;
				foreach ( $plugins as $p ) {
					if ( ! current_user_can( $p['capability'] ) ) {
						continue;
					}
					$shown++;
					$status = '';
					if ( $p['status'] && is_callable( $p['status'] ) ) {
						$status = (string) call_user_func( $p['status'] );
					}
					?>
					<div class="ocpz-card">
						<div class="ocpz-card__head">
							<?php if ( $p['icon'] ) : ?>
								<img class="ocpz-card__icon" src="<?php echo esc_url( $p['icon'] ); ?>" alt="" width="44" height="44">
							<?php endif; ?>
							<div class="ocpz-card__name"><?php echo esc_html( $p['name'] ); ?><?php if ( $p['version'] ) : ?><span class="ocpz-card__ver"><?php echo esc_html( $p['version'] ); ?></span><?php endif; ?></div>
						</div>
						<p class="ocpz-card__desc"><?php echo esc_html( $p['description'] ); ?></p>
						<?php if ( $status ) : ?><p class="ocpz-card__status"><?php echo esc_html( $status ); ?></p><?php endif; ?>
						<div class="ocpz-card__actions">
							<a class="button button-primary" href="<?php echo esc_url( $p['url'] ); ?>"><?php esc_html_e( 'ניהול', 'oc-pluginz' ); ?></a>
							<?php if ( $p['account_url'] ) : ?>
								<a class="button" href="<?php echo esc_url( $p['account_url'] ); ?>"><?php esc_html_e( 'חשבון ורישיון', 'oc-pluginz' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
					<?php
				}
				if ( ! $shown ) {
					echo '<p>' . esc_html__( 'לא נמצאו תוספים פעילים של OC Pluginz.', 'oc-pluginz' ) . '</p>';
				}
				?>
			</div>
			<p class="ocpz-dash__more">
				<?php esc_html_e( 'עוד תוספים ל-WooCommerce מבית OC Pluginz:', 'oc-pluginz' ); ?>
				<a href="<?php echo esc_url( self::SITE_URL ); ?>" target="_blank" rel="noopener">ocpluginz.com &#8599;</a>
			</p>
		</div>
		<?php
	}
}
