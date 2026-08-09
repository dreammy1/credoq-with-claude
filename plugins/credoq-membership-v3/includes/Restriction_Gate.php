<?php
/**
 * Restriction_Gate.
 *
 * AUDIT-FIX (Track A settings-integration testing found this — a major
 * gap, not a minor one): every plan's restricted_pages / restricted_products
 * / restricted_urls / restriction_html / unlock_url / hide_css_selectors
 * settings were fully built into the admin UI (Admin\Plans_Page) and saved
 * into each plan's `rules` JSON — but there was NO enforcement code
 * anywhere in the plugin. An admin could tick "restrict this page to
 * members," save it, and the page would remain fully public with zero
 * effect. For a plugin whose stated purpose is "content restriction," this
 * was the single most significant dead-setting gap found across the whole
 * suite. This class is the enforcement half that was missing.
 *
 * Design: a page/product/URL restricted by one or more plans is blocked
 * (its content replaced with that plan's restriction_html, or a generic
 * fallback) unless the current user has an active membership in AT LEAST
 * ONE of the plans that lists it — soft content-replacement via the
 * `the_content` filter, not a hard wp_die, matching how membership
 * plugins conventionally handle this (search engines and page builders
 * that pre-render server-side still get a real page, just gated content).
 * `hide_css_selectors` is independent of page-level restriction — for
 * every plan the current user lacks, its selectors are hidden site-wide
 * via a small inline stylesheet, so a member-only widget/section can be
 * hidden without gating the whole page it lives on.
 *
 * @package CredoqMembership
 */

namespace CredoqMembership;

defined( 'ABSPATH' ) || exit;

class Restriction_Gate {

	public static function register() : void {
		add_filter( 'the_content', array( __CLASS__, 'maybe_restrict_content' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'print_hide_css' ) );
	}

	/**
	 * @return object[] Plans (each with ->id, ->rules) that restrict the CURRENT request.
	 */
	private static function plans_restricting_current_request() : array {
		$repo  = new Plan_Repository();
		$plans = $repo->all();

		$post_id    = get_queried_object_id();
		$product_id = ( function_exists( 'is_product' ) && is_product() ) ? $post_id : 0;
		$path       = isset( $_SERVER['REQUEST_URI'] ) ? untrailingslashit( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?: '' ) : '';

		$matches = array();
		foreach ( $plans as $plan ) {
			$rules = $plan->rules ?? array();

			$pages = array_map( 'intval', (array) ( $rules['restricted_pages'] ?? array() ) );
			if ( $post_id && in_array( (int) $post_id, $pages, true ) ) {
				$matches[] = $plan;
				continue;
			}

			$products = array_map( 'intval', (array) ( $rules['restricted_products'] ?? array() ) );
			if ( $product_id && in_array( (int) $product_id, $products, true ) ) {
				$matches[] = $plan;
				continue;
			}

			$url_list = array_filter( array_map( 'trim', explode( "\n", (string) ( $rules['restricted_urls'] ?? '' ) ) ) );
			foreach ( $url_list as $u ) {
				if ( '' !== $path && untrailingslashit( $u ) === $path ) {
					$matches[] = $plan;
					break;
				}
			}
		}

		return $matches;
	}

	/** True if the given user has an active, unexpired, paid-up membership in $plan_id. */
	private static function user_has_plan_access( int $user_id, int $plan_id ) : bool {
		if ( ! $user_id ) return false;
		$svc = new Membership_Service();
		foreach ( $svc->get_active_memberships( $user_id ) as $m ) {
			if ( (int) $m->plan_id === $plan_id ) return true;
		}
		return false;
	}

	public static function maybe_restrict_content( string $content ) : string {
		// Only gate the main queried content, not excerpts/widgets
		// rendering unrelated posts elsewhere on the page.
		if ( ! is_main_query() ) return $content;

		$restricting_plans = self::plans_restricting_current_request();
		if ( empty( $restricting_plans ) ) return $content;

		$user_id = get_current_user_id();
		foreach ( $restricting_plans as $plan ) {
			if ( self::user_has_plan_access( $user_id, (int) $plan->id ) ) {
				return $content; // access via at least one matching plan — show real content.
			}
		}

		// Blocked by every matching plan. Use the first one's restriction_html
		// (or a generic fallback), and append an unlock link if one is set.
		$first  = $restricting_plans[0];
		$html   = trim( (string) ( $first->rules['restriction_html'] ?? '' ) );
		$unlock = trim( (string) ( $first->rules['unlock_url'] ?? '' ) );

		if ( '' === $html ) {
			$html = '<p>' . esc_html__( 'This content is available to members only.', 'credoq-membership' ) . '</p>';
		}
		if ( $unlock ) {
			$html .= '<p><a class="credoq-unlock-link" href="' . esc_url( $unlock ) . '">' . esc_html__( 'Become a member', 'credoq-membership' ) . '</a></p>';
		}

		return $html;
	}

	public static function print_hide_css() : void {
		$repo    = new Plan_Repository();
		$plans   = $repo->all();
		$user_id = get_current_user_id();

		$selectors = array();
		foreach ( $plans as $plan ) {
			$sel = trim( (string) ( $plan->rules['hide_css_selectors'] ?? '' ) );
			if ( '' === $sel ) continue;
			if ( self::user_has_plan_access( $user_id, (int) $plan->id ) ) continue; // member of this plan — don't hide its content from them.
			$selectors[] = $sel;
		}
		if ( empty( $selectors ) ) return;

		echo '<style id="credoq-membership-hide-css">' . esc_html( implode( ',', $selectors ) ) . '{display:none !important;}</style>' . "\n";
	}
}
