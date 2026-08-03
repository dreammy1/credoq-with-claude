<?php
/**
 * Shortcode registration.
 *
 * @package CredoqEngine
 */

namespace CredoqEngine;

defined( 'ABSPATH' ) || exit;

class Shortcodes {

	public static function register() : void {
		add_shortcode( 'credoq_booking_form', [ __CLASS__, 'booking_form' ] );
		add_shortcode( 'credoq_dashboard_app', [ Dashboard\Spa::class, 'render_dashboard_app' ] );
		add_shortcode( 'credoq_sidebar_nav',   [ Dashboard\Spa::class, 'render_sidebar_nav' ] );
		add_shortcode( 'credoq_bottom_nav',    [ Dashboard\Spa::class, 'render_bottom_nav' ] );
	}

	/**
	 * The main React-rendered booking widget shortcode.
	 *
	 * Usage: [credoq_booking_form form_id="42"]
	 *
	 * Renders the mounting div + a serialized config blob. The React bundle
	 * (assets/js/booking-widget.min.js) reads it from data-config and renders.
	 *
	 * IMPORTANT: The DOM contract here MUST match react-widget/src/main.jsx:
	 *   - Element ID: 'credoq-booking-root'
	 *   - Attribute:  'data-config' (raw JSON, NOT base64)
	 *
	 * Config schema is the one the React widget actually reads in
	 * BookingWidget.jsx / FormField.jsx / useAjax.js. Addons inject their
	 * own keys via the 'credoq_widget_config' filter.
	 */
	public static function booking_form( $atts ) : string {
		$atts = shortcode_atts( array(
			'form_id'        => 0,
			'appointment_id' => 0,
		), $atts, 'credoq_booking_form' );

		$form_id = absint( $atts['form_id'] );
		if ( ! $form_id ) {
			return '<div class="credoq-widget-error">' . esc_html__( 'Please specify form_id.', 'credoq-engine' ) . '</div>';
		}

		$form = credoq_engine()->forms()->find( $form_id );
		if ( ! $form ) {
			return '<div class="credoq-widget-error">' . esc_html__( 'Form not found.', 'credoq-engine' ) . '</div>';
		}

		// Enqueue widget assets only when actually used.
		Assets::enqueue_widget();

		// Engine settings.
		$settings = get_option( 'credoq_engine_settings', array() );
		$currency = isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD';

		$user = wp_get_current_user();

		// AUDIT-FIX (Field Registry frontend bridge / Bug 2):
		// Decorate each field with a generic '_frontend' render descriptor
		// and '_addon' id from whichever plugin registered its type via
		// 'credoq_register_field_types'. The Engine never hardcodes addon
		// field slugs — FormField.jsx's generic <AddonField> renderer uses
		// '_frontend' for any type it doesn't have a built-in case for.
		$registry      = credoq_engine()->fields();
		$widget_fields = array();
		foreach ( (array) $form->fields as $field ) {
			$type = $registry->get( (string) ( $field['type'] ?? '' ) );
			if ( $type ) {
				$frontend = $type->get_frontend_render( $field );
				if ( ! empty( $frontend ) ) {
					$field['_frontend'] = $frontend;
				}
				$addon_id = $type->get_addon_id();
				if ( $addon_id ) {
					$field['_addon'] = $addon_id;
				}
			}
			// Defense in depth: the React widget renders html_code via
			// dangerouslySetInnerHTML. Re-sanitize here even though only
			// form-builder admins (manage_options) can set it.
			if ( isset( $field['html_code'] ) ) {
				$field['html_code'] = wp_kses_post( (string) $field['html_code'] );
			}
			$widget_fields[] = $field;
		}

		// Build the React widget's config in the EXACT shape it expects.
		// The widget reads: ajax_url, nonce, currency, fields, appointment_id,
		// appointments[], allow_multi_booking, etc. (see BookingWidget.jsx).
		$config = array(
			'form_id'              => $form_id,
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			// FIX: nonce must match check_ajax_referer('credoq_nonce') in all AJAX handlers.
			// Previous value 'credoq_booking' caused 403 Forbidden on every AJAX call.
			'nonce'                => wp_create_nonce( 'credoq_nonce' ),
			'event_nonce'          => wp_create_nonce( 'credoq_events' ),
			'rest_url'             => esc_url_raw( rest_url( CREDOQ_ENGINE_REST_NS . '/' ) ),
			'rest_nonce'           => wp_create_nonce( 'wp_rest' ),
			'currency'             => $currency,
			'fields'               => $widget_fields,
			'is_logged_in'         => is_user_logged_in(),
			'current_user'         => is_user_logged_in() ? array(
				'id'           => (int) $user->ID,
				'display_name' => (string) $user->display_name,
				'user_email'   => (string) $user->user_email,
			) : null,
			'locale'               => get_user_locale(),
			'brand'                => apply_filters( 'credoq_widget_brand', array(
				'name'       => get_bloginfo( 'name' ),
				'logo_url'   => '',
				'lottie_url' => '',
			), $form ),
			'strings'              => apply_filters( 'credoq_widget_strings', array(), $form ),

			// Design tokens — addons / site owners inject via 'credoq_widget_config'.
			// Keys: color_accent, color_secondary, color_border, color_bg, color_text,
			//       font_family, font_size_base, font_size_label, font_size_heading,
			//       card_radius, btn_radius, card_padding.
			'design'               => apply_filters( 'credoq_widget_design', array(), $form ),

			// Appointment-related keys are filled by the Appointments addon
			// via the 'credoq_widget_config' filter. Engine defaults below
			// keep the widget happy when no addon is present.
			'appointment_id'       => absint( $atts['appointment_id'] ),
			'appointment_title'    => '',
			'appointment_location' => '',
			'base_price'           => 0,
			'appointments'         => array(),
			'allow_multi_booking'  => 0,
			'multi_price_mode'     => 'slot',
			'multi_day_rate'       => 0,
			'max_schedules'        => 0,
			'min_schedules'        => 1,
			'visual_seats_enabled' => 0,
			'slot_interval'        => 30,

			// Security — reCAPTCHA. Site key only (secret key never
			// leaves the server). Engine\Security\Gate verifies the
			// token server-side on submission.
			'recaptcha'            => self::recaptcha_config(),
		);

		// Let addons inject extra config (Appointments populates appointments,
		// Membership adds member_credit info, Seats adds visual_seats_enabled).
		$config = apply_filters( 'credoq_widget_config', $config, $form );

		// React widget contract: id="credoq-booking-root" + data-config="...JSON..."
		// We use htmlspecialchars on the JSON to safely embed in HTML attribute.
		$json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '<div class="credoq-widget-error">' . esc_html__( 'Widget config encode failed.', 'credoq-engine' ) . '</div>';
		}

		return sprintf(
			'<div id="credoq-booking-root" class="credoq-booking-widget" data-config="%s"></div>',
			esc_attr( $json )
		);
	}

	/**
	 * Build the reCAPTCHA block for widget config. Only the SITE key
	 * (public) is ever exposed to the frontend — the secret key stays
	 * server-side, used only by Security\Gate::verify_recaptcha().
	 *
	 * @return array{enabled:bool, version:string, site_key:string}
	 */
	private static function recaptcha_config() : array {
		$s = get_option( 'credoq_engine_settings', array() );
		$enabled = ! empty( $s['recaptcha_enabled'] )
			&& ! empty( $s['recaptcha_site_key'] )
			&& ! empty( $s['recaptcha_secret_key'] );
		return array(
			'enabled'  => $enabled,
			'version'  => ( $s['recaptcha_version'] ?? 'v2' ) === 'v3' ? 'v3' : 'v2',
			'site_key' => $enabled ? (string) $s['recaptcha_site_key'] : '',
		);
	}
}
