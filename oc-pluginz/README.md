# OC Pluginz — shared admin chrome (`oc-pluginz/`)

One top-level **OC Pluginz** menu in the customer's WordPress admin for every plugin of the
suite installed on the site:

```
OC Pluginz
├─ סקירה            overview: a card per installed OC plugin (name, version, description, ניהול / חשבון ורישיון)
├─ RMA              one entry per plugin → its main screen
├─ חוקי סל
├─ …
└─ לכל התוספים ↗    https://ocpluginz.com/ (new tab)
```

A plugin's other screens are **hidden pages** (no menu entry) reached through the **tab strip**
the library draws above every screen of the suite (`OC Pluginz › RMA | פניות | פנייה חדשה | …`).
The entry of the plugin stays highlighted on its hidden pages, the `<title>` is set, and the
plugin's assets gate keeps working through the hook suffixes the library hands back.

## Files — bundle verbatim in every plugin

```
<plugin-root>/oc-pluginz/loader.php              version registry; the newest bundled copy on the site wins
<plugin-root>/oc-pluginz/class-oc-pluginz.php    the OC_Pluginz class (menu, strip, overview)
<plugin-root>/oc-pluginz/README.md               this file
```

Canonical copy: `originalconcepts/ocpluginz-hub`, `lib/oc-pluginz/`. Never edit the copy inside a
plugin: change the canonical one, bump `OC_Pluginz::VERSION` **and** the version string at the
bottom of `loader.php`, then copy the folder into the plugins.

In the plugin's **main file**, at file scope (not inside a hook), right after the `ABSPATH` guard
and before anything else of the plugin loads:

```php
require_once __DIR__ . '/oc-pluginz/loader.php';
```

The loader is PHP 5.6-safe and does nothing outside `is_admin()`. If several OC plugins are
active, each copy registers its version and the newest is loaded once, on `plugins_loaded` at
priority `-1000`.

## Registering the plugin

Call `oc_pluginz_register()` (exists as soon as the loader is included; it queues until the class
is loaded) from an `init` callback at priority **20 or later** — never earlier, or WordPress 6.7+
logs `_load_textdomain_just_in_time` notices because the labels are translated before `init`.

```php
add_action( 'init', function () {
	oc_pluginz_register(
		'oc-rma',                                     // plugin slug = card key
		array(
			'name'        => __( 'RMA', 'oc-rma' ),   // product name: menu entry, strip, card (plain text)
			'page'        => 'oc-rma',                // main page slug (admin.php?page=…); default = plugin slug
			'page_title'  => __( 'Cases', 'oc-rma' ), // <title> of the main page and its tab label; default = name
			'menu_title'  => __( 'RMA', 'oc-rma' ),   // entry label; may carry HTML (a count bubble); default = name
			'capability'  => 'oc_rma_view',           // default manage_woocommerce
			'callback'    => array( Inbox::class, 'route' ),
			'on_load'     => array( Inbox::class, 'on_load' ),   // optional: load-{hook} of the main page
			'description' => __( 'Returns, exchanges … customer updates.', 'oc-rma' ), // one Hebrew line on the card
			'icon'        => OC_RMA_URL . 'assets/icon-256x256.png',                   // optional
			'version'     => OC_RMA_VERSION,                                            // shown next to the name
			'account_url' => View::url( 'oc-rma-settings', array( 'tab' => 'license' ) ), // optional: "חשבון ורישיון" button
			'status'      => array( License::class, 'summary' ),  // optional callable → one short line on the card
			'position'    => 50,                                   // order among the entries (lower first, then by name)
			'pages'       => array(                                // hidden pages = tabs in the strip
				array( 'slug' => 'oc-rma-new',      'title' => __( 'New case', 'oc-rma' ), 'capability' => 'oc_rma_manage', 'callback' => array( NewCase::class, 'render' ) ),
				array( 'slug' => 'oc-rma-settings', 'title' => __( 'Settings', 'oc-rma' ), 'capability' => 'oc_rma_settings', 'callback' => array( Settings::class, 'render' ), 'on_load' => array( Settings::class, 'on_load' ) ),
				array( 'slug' => 'oc-rma-wizard',   'title' => __( 'Setup', 'oc-rma' ),    'callback' => array( Wizard::class, 'render' ), 'tab' => false ), // reachable, not a tab
			),
		)
	);
}, 20 );
```

The old `add_menu_page()` / `add_submenu_page()` calls of the plugin go away; the library makes
them. Remove the plugin's own `admin_menu` callback (or keep it for things that are not pages).

### A CPT-based plugin (its list screen is the main screen)

```php
register_post_type( 'osp_popup', array( …, 'show_in_menu' => 'oc-pluginz', 'labels' => array( …, 'all_items' => 'פופ אפים' ) ) );

oc_pluginz_register( 'oc-simple-popup', array(
	'name'         => 'Popup',
	'menu'         => false,                                  // WordPress adds the entry itself (show_in_menu)
	'url'          => admin_url( 'edit.php?post_type=osp_popup' ),
	'submenu_file' => 'edit.php?post_type=osp_popup',         // the entry to keep highlighted
	'screens'      => array( 'edit-osp_popup', 'osp_popup' ), // screen ids that belong to the plugin (list + editor)
	'page_title'   => 'פופ אפים',                              // label of the first tab
	'pages'        => array( array( 'slug' => 'osp-account', 'title' => 'חשבון OC Pluginz', 'callback' => … ) ),
	…
) );
```

## What changes for the plugin's code

* **Hook suffixes / screen ids.** The main page becomes `oc-pluginz_page_{page}`; a hidden page
  becomes `admin_page_{slug}`. Code that compares against `toplevel_page_…` or `{old-parent}_page_…`
  must use the library instead:
  * `OC_Pluginz::hook( $page_slug )` → the hook suffix of a page registered through the library
    (`''` before `admin_menu` ran or when the user cannot open it);
  * `OC_Pluginz::owner( $hook_or_screen_id )` → the owning plugin's slug, `''` if none;
  * `OC_Pluginz::current()` → the owner of the current screen.
  A `strpos( $hook, 'my-slug' )` gate keeps working when every page slug of the plugin starts with
  the plugin slug.
* **URLs** built as `admin.php?page={slug}` keep working, including for hidden pages. URLs built
  as `edit.php?post_type=…&page=…` or `admin.php?page={old-parent}&…` must change to
  `admin.php?page={slug}`.
* **`load-{hook}` actions**: pass them as `on_load` instead of hooking the return value of
  `add_submenu_page()`.
* **Capabilities** are per page, as before. The parent's capability is the first one the user
  holds out of `manage_woocommerce`, `manage_options` and the registered plugins' own, so a role
  that can use one plugin sees the menu (WordPress then points the parent at the first entry it
  can open).
* **The `name` is plain text** (it is escaped everywhere); the `menu_title` may carry HTML.
* **The page `<h1>`s and in-page `nav-tab-wrapper` tabs (`?tab=`) stay** as they are; only separate
  admin *pages* become strip tabs.

## Hebrew

The chrome itself is Hebrew (`oc-pluginz` text domain, no .mo needed). The plugin's own labels
(`name`, `page_title`, tab titles, `description`) go through the plugin's text domain: a plugin
with an inline gettext table (Cart Rules, RMA) adds the new source strings there; a plugin with
`languages/*.po` adds the entries to the `.po` and recompiles the `.mo` (RMA's test suite fails
when a string has no Hebrew).

## Verifying

* `php -l` every PHP file you touched; run the plugin's test suite (`php tests/run.php` or
  whatever `.github/workflows/ci.yml` runs).
* On a site: the entry appears under OC Pluginz; the main screen and every hidden page open;
  the strip shows the tabs with the right one current; the OC Pluginz menu stays open and the
  plugin's entry highlighted on hidden pages; `<title>` is set; the plugin's admin CSS/JS still
  load on its screens and not elsewhere; `wp-content/debug.log` shows no new notice.
