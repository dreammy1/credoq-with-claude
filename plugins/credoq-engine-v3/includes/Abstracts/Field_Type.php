<?php
/**
 * Field_Type — abstract base class.
 *
 * All addon field types extend this. The Engine calls these methods to:
 *   - render the field in the React widget (config + JSON schema)
 *   - validate user input on submission
 *   - sanitize and persist the value
 *   - render the value in admin reports / emails
 *   - hook into pricing (does this field affect total_price?)
 *   - hook into the booking lifecycle (post-submission side effects)
 *
 * Addon authors only need to implement what they use. Sensible defaults
 * are provided for everything else.
 *
 * @package CredoqEngine\Abstracts
 */

namespace CredoqEngine\Abstracts;

defined( 'ABSPATH' ) || exit;

abstract class Field_Type {

	/* ── Identity ──────────────────────────────────────────────────── */

	/** Globally unique slug, e.g. 'appointment', 'event', 'seat_map'. */
	abstract public function get_slug() : string;

	/** Human-readable label shown in the form builder. */
	abstract public function get_label() : string;

	/** Lucide-style icon name (rendered SVG client-side). */
	public function get_icon() : string { return 'square'; }

	/** Category for grouping in the builder palette. */
	public function get_category() : string { return 'basic'; }

	/** One-line description shown under the label. */
	public function get_description() : string { return ''; }

	/** Which addon owns this field, e.g. 'credoq-appointments'. Empty = core. */
	public function get_addon_id() : string { return ''; }

	/* ── Builder schema ────────────────────────────────────────────── */

	/**
	 * Settings the admin can edit in the form-builder side panel.
	 *
	 * Return an array of setting definitions, e.g.:
	 *   [
	 *     [ 'key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text' ],
	 *     [ 'key' => 'required',    'label' => 'Required',    'type' => 'checkbox' ],
	 *   ]
	 */
	abstract public function get_settings_schema() : array;

	/** Default settings used when a field of this type is first dropped. */
	public function get_default_settings() : array { return array(); }

	/* ── Server-side lifecycle ─────────────────────────────────────── */

	/**
	 * Validate a submitted value. Return true on success, or a
	 * WP_Error with code+message on failure.
	 *
	 * @param mixed $value
	 * @param array $field_config The full field config from the form schema.
	 * @param array $submission   The complete submission payload (for cross-field validation).
	 * @return true|\WP_Error
	 */
	public function validate( $value, array $field_config, array $submission ) {
		if ( ! empty( $field_config['required'] ) && ( '' === $value || null === $value || array() === $value ) ) {
			return new \WP_Error( 'required', sprintf(
				/* translators: %s field label */
				__( '"%s" is required.', 'credoq-engine' ),
				$field_config['label'] ?? $this->get_label()
			) );
		}
		return true;
	}

	/**
	 * Sanitize a submitted value before storage.
	 *
	 * @param mixed $value
	 * @param array $field_config
	 * @return mixed
	 */
	public function sanitize( $value, array $field_config ) {
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		return $value;
	}

	/**
	 * If this field affects the total price, return the amount to add.
	 * Negative numbers are allowed (discounts). Default: no effect.
	 *
	 * @param mixed $value
	 * @param array $field_config
	 * @param array $submission
	 * @return float
	 */
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		return 0.0;
	}

	/**
	 * Standalone WooCommerce checkout bridging.
	 *
	 * If this field has "Enable WC Checkout" turned on and a WC Product ID
	 * configured, return one or more contributions describing how this
	 * field's value affects a WooCommerce cart item's price.
	 *
	 * Each contribution is:
	 *   [ 'product_id' => int, 'price' => float ]
	 *
	 * 'price' is the amount this field adds toward the grand total for
	 * that product (only non-zero when "Option value as price → add to
	 * WC grand total" is enabled). The product is still returned with a
	 * zero price when only "Enable WC Checkout" + "WC Product ID" are
	 * set, so the Submission_Handler knows to add that product to the
	 * cart even if this particular field contributes no price itself.
	 *
	 * Submission_Handler sums contributions per product_id across all
	 * fields to build the final cart item price.
	 *
	 * @param mixed $value
	 * @param array $field_config
	 * @param array $submission
	 * @return array<int, array{product_id:int, price:float}>
	 */
	public function wc_contribution( $value, array $field_config, array $submission ) : array {
		return array();
	}

	/**
	 * If this field requires credit-based access (e.g. appointment, event),
	 * return the number of credits to deduct. Default: 0.
	 *
	 * @param mixed $value
	 * @param array $field_config
	 * @param array $submission
	 * @return int
	 */
	public function credit_cost( $value, array $field_config, array $submission ) : int {
		return 0;
	}

	/**
	 * Called after the submission is saved. Use for side effects:
	 * creating an appointment booking row, reserving seats, registering
	 * the user for an event, etc.
	 *
	 * Throwing or returning WP_Error here will mark the submission as
	 * failed and trigger rollback hooks.
	 *
	 * @param int    $submission_id
	 * @param mixed  $value
	 * @param array  $field_config
	 * @param array  $submission_payload
	 * @return true|\WP_Error
	 */
	public function on_submission( int $submission_id, $value, array $field_config, array $submission_payload ) {
		return true;
	}

	/**
	 * Called when a submission is cancelled or refunded. Reverse side
	 * effects (release seats, cancel appointment, refund credits).
	 *
	 * @param int   $submission_id
	 * @param array $context  e.g. [ 'reason' => 'wc_cancelled', 'refund_credits' => true ]
	 */
	public function on_cancellation( int $submission_id, array $context ) : void {}

	/* ── Rendering helpers ────────────────────────────────────────── */

	/**
	 * How this field should be displayed in admin reports and emails.
	 * Return HTML (will be wp_kses_post'd) or a plain string.
	 *
	 * @param mixed $value
	 * @param array $field_config
	 * @return string
	 */
	public function render_value( $value, array $field_config ) : string {
		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}

	/**
	 * AUDIT-FIX (Field Registry frontend bridge): describe how this field
	 * should be rendered by the React booking widget when the widget's
	 * built-in FormField.jsx does not have a hardcoded case for this
	 * field type's slug.
	 *
	 * Built-in field types (text, select, checkbox, calculate, etc.) are
	 * rendered natively by FormField.jsx and should leave this returning
	 * an empty array.
	 *
	 * Addon field types (e.g. Membership's `membership_credit`, Events'
	 * `event_registration`) can return a small declarative descriptor and
	 * FormField.jsx's generic <AddonField> renderer will display it
	 * without the Engine ever needing to know the addon's internals.
	 *
	 * Supported `component` values (handled generically by
	 * <AddonField> in FormField.jsx):
	 *
	 *   - 'display' : read-only info box.
	 *       props: { text: string, value?: string|number }
	 *
	 *   - 'select'  : dropdown using field_config['options'] (or
	 *                 props.options if provided).
	 *       props: { options?: [{label,value}], placeholder?: string }
	 *
	 *   - 'number'  : numeric input.
	 *       props: { min?, max?, step?, placeholder? }
	 *
	 *   - 'html'    : raw HTML block (sanitized server-side with
	 *                 wp_kses_post before being sent to the browser).
	 *       props: { html: string }
	 *
	 * @param array $field_config The saved field configuration.
	 * @return array{component?:string, props?:array}
	 */
	public function get_frontend_render( array $field_config ) : array {
		return array();
	}
}
