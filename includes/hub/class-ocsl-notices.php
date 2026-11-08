<?php
/**
 * Notices from OC Pluginz (my.ocpluginz.com): what the hub answers under
 * `notices` next to the licence (a new plugin, an important release, a service
 * message) is kept with the entitlement (ocsl_hub) and shown on every admin
 * screen, like any plugin's notice, until the admin dismisses it.
 *
 * Every OC plugin on a site shares one dismissal list (user meta
 * ocpz_dismissed_notices) and one guard per request ($GLOBALS['ocpz_notices_shown']),
 * so a notice meant for every product appears once, however many OC plugins
 * the site runs. The list is refreshed whenever the licence is (hourly, and
 * when the account screen opens), so a notice reaches the site within the hour
 * and a withdrawn one leaves the same way. Agency mode and a legacy site
 * without a key never ask the hub, so they never have a list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Notices {

	const META   = 'ocpz_dismissed_notices';
	const ACTION = 'ocsl_dismiss_notice';
	/** The line above the title: who is talking, in which plugin. */
	const SOURCE = 'הודעה מ-OC Pluginz · OC SMS Login';
	const TYPES  = array( 'info', 'success', 'warning' );
	const MAX    = 5;

	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/* ---------------- The rules, without WordPress ---------------- */

	/**
	 * The notices in the hub's answer, cleaned: at most MAX, each with an id and a title.
	 *
	 * @param array $data The hub's answer.
	 * @return array[] list of {id, type, title, body, url, url_text, image}
	 */
	public static function from_answer( array $data ) {
		$out = array();
		$raw = isset( $data['notices'] ) ? (array) $data['notices'] : array();
		foreach ( $raw as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$id    = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $n['id'] ) ? (string) $n['id'] : '' ) );
			$title = trim( strip_tags( isset( $n['title'] ) ? (string) $n['title'] : '' ) );
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$type  = isset( $n['type'] ) ? (string) $n['type'] : 'info';
			$url   = isset( $n['url'] ) ? (string) $n['url'] : '';
			$img   = isset( $n['image'] ) ? (string) $n['image'] : '';
			$out[] = array(
				'id'       => $id,
				'type'     => in_array( $type, self::TYPES, true ) ? $type : 'info',
				'title'    => $title,
				'body'     => trim( strip_tags( isset( $n['body'] ) ? (string) $n['body'] : '' ) ),
				'url'      => preg_match( '#^https?://#i', $url ) ? $url : '',
				'url_text' => trim( strip_tags( isset( $n['url_text'] ) ? (string) $n['url_text'] : '' ) ),
				'image'    => preg_match( '#^https://#i', $img ) ? $img : '',
				'style'    => ( isset( $n['style'] ) && 'dark' === $n['style'] ) ? 'dark' : 'light',
				'brand'    => ( isset( $n['brand'] ) && preg_match( '#^https://#i', (string) $n['brand'] ) ) ? (string) $n['brand'] : '',
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What is left to show: not dismissed by this user, not already shown by another OC plugin this request.
	 *
	 * @param array[] $list      The kept notices.
	 * @param string[] $dismissed The user's dismissed ids.
	 * @param array   $shown     Ids shown already this request (id => true).
	 * @return array[]
	 */
	public static function pending( array $list, array $dismissed, array $shown ) {
		$out = array();
		foreach ( $list as $n ) {
			if ( ! is_array( $n ) || '' === ( isset( $n['id'] ) ? (string) $n['id'] : '' ) ) {
				continue;
			}
			if ( in_array( $n['id'], $dismissed, true ) || isset( $shown[ $n['id'] ] ) ) {
				continue;
			}
			$out[] = $n;
		}
		return $out;
	}

	/* ---------------- WordPress ---------------- */

	/** @return string[] */
	private static function dismissed() {
		$list = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	public static function render() {
		if ( OCSL_Hub::agency() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = OCSL_Hub::state();
		$kept  = isset( $state['notices'] ) ? $state['notices'] : array();
		if ( ! is_array( $kept ) || ! $kept ) {
			return;
		}
		if ( ! isset( $GLOBALS['ocpz_notices_shown'] ) || ! is_array( $GLOBALS['ocpz_notices_shown'] ) ) {
			$GLOBALS['ocpz_notices_shown'] = array();
		}
		$list = self::pending( $kept, self::dismissed(), $GLOBALS['ocpz_notices_shown'] );
		if ( ! $list ) {
			return;
		}
		self::styles();
		foreach ( $list as $n ) {
			$GLOBALS['ocpz_notices_shown'][ $n['id'] ] = true;

			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=' . rawurlencode( $n['id'] ) ), self::ACTION . '_' . $n['id'] );
			$img     = isset( $n['image'] ) ? (string) $n['image'] : '';
			$brand   = isset( $n['brand'] ) ? (string) $n['brand'] : '';
			$style   = ( isset( $n['style'] ) && 'dark' === $n['style'] ) ? 'dark' : 'light';
			echo '<div class="notice ocpz-notice ocpz-banner ocpz-s-' . esc_attr( $style ) . ' ocpz-t-' . esc_attr( $n['type'] ) . ' is-dismissible" data-ocpz-dismiss="' . esc_url( $dismiss ) . '">';
			if ( '' !== $img ) {
				// The image lives on the hub; if it cannot load, the square goes and the text takes the width.
				echo '<div class="ocpz-b-im"><img src="' . esc_url( $img ) . '" alt="" loading="lazy" onerror="this.parentNode.style.display=\'none\'" /></div>';
			}
			echo '<div class="ocpz-b-tx">';
			echo '<p class="ocpz-b-src">' . ( '' !== $brand ? '<img src="' . esc_url( $brand ) . '" alt="" onerror="this.style.display=\'none\'" />' : '' ) . esc_html( self::SOURCE ) . '</p>';
			echo '<p class="ocpz-b-h">' . esc_html( $n['title'] ) . '</p>';
			if ( '' !== $n['body'] ) {
				echo '<p class="ocpz-b-b">' . nl2br( esc_html( $n['body'] ) ) . '</p>';
			}
			echo '<p class="ocpz-b-cta">';
			if ( '' !== $n['url'] ) {
				echo '<a class="ocpz-b-p" href="' . esc_url( $n['url'] ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $n['url_text'] ? $n['url_text'] : 'לפרטים' ) . '</a>';
			}
			echo '<a class="ocpz-b-g ocpz-b-ok" href="#" role="button">הבנתי</a>';
			echo '</p></div></div>' . "\n";
		}
		if ( empty( $GLOBALS['ocpz_notices_js'] ) ) {
			$GLOBALS['ocpz_notices_js'] = true;
			// "הבנתי" and the X WordPress adds both tell the plugin to remember the dismissal.
			echo '<script>document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target:null;if(!t)return;var ok=t.closest(".ocpz-banner .ocpz-b-ok"),x=t.closest(".ocpz-notice .notice-dismiss");if(!ok&&!x)return;var n=(ok||x).closest(".ocpz-notice");if(!n)return;if(ok){e.preventDefault();n.style.display="none";}var u=n.getAttribute("data-ocpz-dismiss");if(u){fetch(u,{credentials:"same-origin"}).catch(function(){});}});</script>' . "\n";
		}
	}

	/**
	 * The banner's stylesheet, once per request across OC plugins. Logical
	 * properties, so the accent bar and the X land on the right side in RTL and LTR.
	 */
	private static function styles() {
		if ( ! empty( $GLOBALS['ocpz_notices_css'] ) ) {
			return;
		}
		$GLOBALS['ocpz_notices_css'] = true;
		echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Hebrew:wght@400;600;700;800&amp;display=swap" />' . "\n";
		echo '<style>'
			. '.ocpz-banner{position:relative;display:flex!important;align-items:center;gap:24px;padding:26px 28px 24px!important;padding-inline-end:56px!important;margin:16px 20px 16px 2px!important;border:1px solid #d9d4f5!important;border-radius:16px;background:linear-gradient(to right,#f7f5ff,#fff 70%);box-shadow:0 8px 24px rgba(83,74,183,.12);overflow:hidden;font-family:"Noto Sans Hebrew",Heebo,-apple-system,"Segoe UI",Arial,sans-serif}'
			. '[dir=rtl] .ocpz-banner{background:linear-gradient(to left,#f7f5ff,#fff 70%)}'
			. '.ocpz-banner::before{content:"";position:absolute;top:0;left:0;right:0;height:6px;background:linear-gradient(90deg,#f1564b,#bd419c,#7b3ff2)}'
			. '.ocpz-banner .ocpz-b-im{flex:0 0 auto;width:128px;height:128px;border-radius:28px;background:#e9e5fb;display:flex;align-items:center;justify-content:center}'
			. '.ocpz-banner .ocpz-b-im img{display:block;width:104px;height:104px;border-radius:24px;object-fit:cover;box-shadow:0 6px 16px rgba(38,33,92,.18)}'
			. '.ocpz-banner .ocpz-b-tx{flex:1;min-width:0}'
			. '.ocpz-banner p{margin:0!important;padding:0!important}'
			. '.ocpz-banner .ocpz-b-src{display:inline-flex!important;align-items:center;gap:8px;background:#fff;border:1px solid #d9d4f5;border-radius:999px;padding:4px 12px 4px 6px!important;font-size:12px;font-weight:600;color:#3c3489;line-height:1.4;margin:0 0 12px!important}'
			. '.ocpz-banner .ocpz-b-src img{width:20px;height:20px;border-radius:50%;display:block}'
			. '.ocpz-banner .ocpz-b-h{font-size:22px;font-weight:800;color:#26215c;line-height:1.3}'
			. '.ocpz-banner .ocpz-b-b{font-size:15px;color:#50556b;line-height:1.6;max-width:62ch;margin-top:6px!important}'
			. '.ocpz-banner .ocpz-b-cta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:18px!important}'
			. '.ocpz-banner .ocpz-b-p{background:linear-gradient(90deg,#534ab7,#7b3ff2);color:#fff!important;text-decoration:none;font-size:15px;font-weight:700;padding:11px 22px;border-radius:10px;box-shadow:0 4px 12px rgba(83,74,183,.3);line-height:1.3}'
			. '.ocpz-banner .ocpz-b-p:hover{filter:brightness(1.08)}'
			. '.ocpz-banner .ocpz-b-g{color:#3c3489!important;background:#fff;border:1px solid #d9d4f5;text-decoration:none;font-size:15px;font-weight:600;padding:10px 18px;border-radius:10px;line-height:1.3}'
			. '.ocpz-banner .ocpz-b-g:hover{background:#f3f1fc}'
			. '.ocpz-banner .notice-dismiss{top:14px}'
			. '.ocpz-banner.ocpz-t-success::before{background:#15803d}.ocpz-banner.ocpz-t-success{background:linear-gradient(to right,#f1fbf4,#fff 70%);border-color:#bbf7d0!important}[dir=rtl] .ocpz-banner.ocpz-t-success{background:linear-gradient(to left,#f1fbf4,#fff 70%)}.ocpz-banner.ocpz-t-success .ocpz-b-im{background:#dcfce7}.ocpz-banner.ocpz-t-success .ocpz-b-src{color:#15803d;border-color:#bbf7d0}.ocpz-banner.ocpz-t-success .ocpz-b-p{background:#15803d;box-shadow:0 4px 12px rgba(21,128,61,.3)}.ocpz-banner.ocpz-t-success .ocpz-b-g{color:#15803d!important;border-color:#bbf7d0}'
			. '.ocpz-banner.ocpz-t-warning::before{background:#b45309}.ocpz-banner.ocpz-t-warning{background:linear-gradient(to right,#fff8ef,#fff 70%);border-color:#fde7c7!important}[dir=rtl] .ocpz-banner.ocpz-t-warning{background:linear-gradient(to left,#fff8ef,#fff 70%)}.ocpz-banner.ocpz-t-warning .ocpz-b-im{background:#fdebd0}.ocpz-banner.ocpz-t-warning .ocpz-b-src{color:#b45309;border-color:#fde7c7}.ocpz-banner.ocpz-t-warning .ocpz-b-p{background:#b45309;box-shadow:0 4px 12px rgba(180,83,9,.3)}.ocpz-banner.ocpz-t-warning .ocpz-b-g{color:#b45309!important;border-color:#fde7c7}'
			. '.ocpz-banner.ocpz-s-dark{background:#1b1b2f!important;border-color:#1b1b2f!important;box-shadow:0 12px 32px rgba(27,27,47,.28)}'
			. '.ocpz-banner.ocpz-s-dark::before{display:none}'
			. '.ocpz-banner.ocpz-s-dark::after{content:"";position:absolute;inset:auto -60px -120px auto;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(123,63,242,.45),transparent 65%);pointer-events:none}'
			. '[dir=rtl] .ocpz-banner.ocpz-s-dark::after{inset:auto auto -120px -60px}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-im,.ocpz-banner.ocpz-s-dark .ocpz-b-tx{position:relative;z-index:1}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-im{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12)}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-im img{box-shadow:0 10px 30px rgba(123,63,242,.45)}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-src{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.14);color:#e6e2ff}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-h{color:#fff}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-b{color:#c3c0da}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-p{background:linear-gradient(90deg,#f1564b,#bd419c,#7b3ff2);box-shadow:none}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-g{color:#e6e2ff!important;background:transparent;border-color:rgba(255,255,255,.2)}'
			. '.ocpz-banner.ocpz-s-dark .ocpz-b-g:hover{background:rgba(255,255,255,.08)}'
			. '.ocpz-banner.ocpz-s-dark .notice-dismiss:before{color:#fff}'
			. '.ocpz-banner.ocpz-s-dark .notice-dismiss{z-index:2}'
			. '@media(max-width:640px){.ocpz-banner{flex-direction:column;align-items:flex-start;gap:16px}}'
			. '</style>' . "\n";
	}

	/** Remember the dismissal for this user, for every OC plugin on the site. */
	public static function dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		$id = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $_GET['notice'] ) ? (string) $_GET['notice'] : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next
		check_admin_referer( self::ACTION . '_' . $id );
		if ( '' !== $id ) {
			$list   = self::dismissed();
			$list[] = $id;
			update_user_meta( get_current_user_id(), self::META, array_values( array_unique( array_slice( $list, -100 ) ) ) );
		}
		if ( ! headers_sent() ) {
			status_header( 204 );
		}
		exit;
	}
}
