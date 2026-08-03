<?php
/**
 * Built-in field types provided by the Engine.
 *
 * These are the universal form inputs (text, email, select, etc.) that
 * exist with zero addons installed.
 *
 * @package CredoqEngine\Fields
 */

namespace CredoqEngine\Fields;

use CredoqEngine\Abstracts\Field_Type;

defined( 'ABSPATH' ) || exit;

class Builtin_Types {

	public static function register( Registry $registry ) : void {
		$registry->register( new Field_Text() );
		$registry->register( new Field_Email() );
		$registry->register( new Field_Phone() );
		$registry->register( new Field_Textarea() );
		$registry->register( new Field_Select() );
		$registry->register( new Field_Radio() );
		$registry->register( new Field_Checkbox() );
		$registry->register( new Field_Date() );
		$registry->register( new Field_Time() );
		$registry->register( new Field_Number() );
		$registry->register( new Field_File() );
		$registry->register( new Field_Hidden() );
		$registry->register( new Field_Html() );
		// Booking & pricing fields.
		$registry->register( new Field_Quantity() );
		$registry->register( new Field_Calculate() );
		$registry->register( new Field_Total_Price() );
		$registry->register( new Field_Signature() );
		// Layout / structural fields.
		$registry->register( new Field_Step() );
		$registry->register( new Field_Page_Break() );
		$registry->register( new Field_Submit_Button() );
	}
}

/* ── Text ──────────────────────────────────────────────────────────── */
class Field_Text extends Field_Type {
	public function get_slug() : string  { return 'text'; }
	public function get_label() : string { return __( 'Text', 'credoq-engine' ); }
	public function get_icon() : string  { return 'type'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',       'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'placeholder', 'type' => 'text',     'label' => __( 'Placeholder', 'credoq-engine' ) ),
			array( 'key' => 'required',    'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
			array( 'key' => 'max_length',  'type' => 'number',   'label' => __( 'Max length', 'credoq-engine' ), 'default' => 255 ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		$max = absint( $field_config['max_length'] ?? 255 );
		$v   = sanitize_text_field( (string) $value );
		return $max > 0 ? substr( $v, 0, $max ) : $v;
	}
}

/* ── Email ─────────────────────────────────────────────────────────── */
class Field_Email extends Field_Type {
	public function get_slug() : string  { return 'email'; }
	public function get_label() : string { return __( 'Email', 'credoq-engine' ); }
	public function get_icon() : string  { return 'mail'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',       'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'placeholder', 'type' => 'text',     'label' => __( 'Placeholder', 'credoq-engine' ) ),
			array( 'key' => 'required',    'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function validate( $value, array $field_config, array $submission ) {
		$parent = parent::validate( $value, $field_config, $submission );
		if ( is_wp_error( $parent ) ) return $parent;
		if ( '' !== $value && ! is_email( (string) $value ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'credoq-engine' ) );
		}
		return true;
	}
	public function sanitize( $value, array $field_config ) {
		return sanitize_email( (string) $value );
	}
}

/* ── Phone ─────────────────────────────────────────────────────────── */
class Field_Phone extends Field_Type {
	public function get_slug() : string  { return 'phone'; }
	public function get_label() : string { return __( 'Phone', 'credoq-engine' ); }
	public function get_icon() : string  { return 'phone'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',       'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'placeholder', 'type' => 'text',     'label' => __( 'Placeholder', 'credoq-engine' ) ),
			array( 'key' => 'required',    'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
}

/* ── Textarea ──────────────────────────────────────────────────────── */
class Field_Textarea extends Field_Type {
	public function get_slug() : string  { return 'textarea'; }
	public function get_label() : string { return __( 'Long text', 'credoq-engine' ); }
	public function get_icon() : string  { return 'align-left'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',       'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'placeholder', 'type' => 'text',     'label' => __( 'Placeholder', 'credoq-engine' ) ),
			array( 'key' => 'rows',        'type' => 'number',   'label' => __( 'Rows', 'credoq-engine' ), 'default' => 4 ),
			array( 'key' => 'required',    'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		return sanitize_textarea_field( (string) $value );
	}
}

/* ── Select ────────────────────────────────────────────────────────── */
class Field_Select extends Field_Type {
	public function get_slug() : string     { return 'select'; }
	public function get_label() : string    { return __( 'Dropdown', 'credoq-engine' ); }
	public function get_icon() : string     { return 'chevrons-up-down'; }
	public function get_category() : string { return 'choice'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',           'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'options',         'type' => 'options',  'label' => __( 'Options', 'credoq-engine' ) ),
			array( 'key' => 'required',        'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
			array( 'key' => 'enable_wc',       'type' => 'toggle',   'label' => __( 'Enable WC Checkout', 'credoq-engine' ) ),
			array( 'key' => 'wc_product_id',   'type' => 'number',   'label' => __( 'WC Product ID', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
			array( 'key' => 'wc_option_price', 'type' => 'checkbox', 'label' => __( 'Option value as price → add to WC grand total', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
		);
	}
	/**
	 * Validate against the allowed option keys (not display labels or prices).
	 * AUDIT-FIX C-6: previously compared on price values, breaking non-numeric option values.
	 */
	public function validate( $value, array $field_config, array $submission ) {
		$parent = parent::validate( $value, $field_config, $submission );
		if ( is_wp_error( $parent ) ) return $parent;
		if ( '' === $value ) return true;
		$valid_keys = array();
		foreach ( (array) ( $field_config['options'] ?? array() ) as $opt ) {
			$valid_keys[] = (string) ( $opt['key'] ?? $opt['value'] ?? $opt['label'] ?? '' );
		}
		if ( ! in_array( (string) $value, $valid_keys, true ) ) {
			return new \WP_Error( 'invalid_option', __( 'Please choose a valid option.', 'credoq-engine' ) );
		}
		return true;
	}
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		// Only apply pricing when the admin explicitly enabled it.
		if ( empty( $field_config['add_to_total'] ) ) return 0.0;
		foreach ( (array) ( $field_config['options'] ?? array() ) as $opt ) {
			$key = (string) ( $opt['key'] ?? $opt['value'] ?? $opt['label'] ?? '' );
			if ( $key === (string) $value ) {
				return (float) ( $opt['price'] ?? 0 );
			}
		}
		return 0.0;
	}

	/**
	 * Standalone WC checkout bridge (AUDIT-FIX: 3-setting architecture).
	 *
	 * When "Enable WC Checkout" is on and a "WC Product ID" is set, this
	 * field's selected option contributes toward that product's cart
	 * price. If "Option value as price → add to WC grand total" is also
	 * on, the option's value (interpreted as a number) is added to the
	 * product's price.
	 */
	public function wc_contribution( $value, array $field_config, array $submission ) : array {
		if ( empty( $field_config['enable_wc'] ) ) return array();
		$product_id = absint( $field_config['wc_product_id'] ?? 0 );
		if ( ! $product_id ) return array();

		$price = 0.0;
		if ( ! empty( $field_config['wc_option_price'] ) && '' !== $value && null !== $value && is_numeric( $value ) ) {
			$price = (float) $value;
		}

		return array( array( 'product_id' => $product_id, 'price' => $price ) );
	}
}

/* ── Radio (inherits Select logic for validation/pricing) ──────────── */
class Field_Radio extends Field_Select {
	public function get_slug() : string     { return 'radio'; }
	public function get_label() : string    { return __( 'Radio buttons', 'credoq-engine' ); }
	public function get_icon() : string     { return 'circle-dot'; }
	public function get_category() : string { return 'choice'; }
}

/* ── Checkbox group ───────────────────────────────────────────────── */
class Field_Checkbox extends Field_Type {
	public function get_slug() : string     { return 'checkbox'; }
	public function get_label() : string    { return __( 'Checkboxes', 'credoq-engine' ); }
	public function get_icon() : string     { return 'check-square'; }
	public function get_category() : string { return 'choice'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',           'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'options',         'type' => 'options',  'label' => __( 'Options', 'credoq-engine' ) ),
			array( 'key' => 'required',        'type' => 'checkbox', 'label' => __( 'At least one required', 'credoq-engine' ) ),
			array( 'key' => 'enable_wc',       'type' => 'toggle',   'label' => __( 'Enable WC Checkout', 'credoq-engine' ) ),
			array( 'key' => 'wc_product_id',   'type' => 'number',   'label' => __( 'WC Product ID', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
			array( 'key' => 'wc_option_price', 'type' => 'checkbox', 'label' => __( 'Option value as price → add to WC grand total', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		if ( ! is_array( $value ) ) $value = array();
		return array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
	}
	public function validate( $value, array $field_config, array $submission ) {
		$value = is_array( $value ) ? $value : array();
		if ( ! empty( $field_config['required'] ) && empty( $value ) ) {
			return new \WP_Error( 'required', sprintf(
				__( '"%s" requires at least one selection.', 'credoq-engine' ),
				$field_config['label'] ?? $this->get_label()
			) );
		}
		$valid_keys = array();
		foreach ( (array) ( $field_config['options'] ?? array() ) as $opt ) {
			$valid_keys[] = (string) ( $opt['key'] ?? $opt['value'] ?? $opt['label'] ?? '' );
		}
		foreach ( $value as $v ) {
			if ( ! in_array( (string) $v, $valid_keys, true ) ) {
				return new \WP_Error( 'invalid_option', __( 'Invalid option selected.', 'credoq-engine' ) );
			}
		}
		return true;
	}
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		// Only apply pricing when the admin explicitly enabled it.
		if ( empty( $field_config['add_to_total'] ) ) return 0.0;
		$value = is_array( $value ) ? $value : array();
		$total = 0.0;
		foreach ( (array) ( $field_config['options'] ?? array() ) as $opt ) {
			$key = (string) ( $opt['key'] ?? $opt['value'] ?? $opt['label'] ?? '' );
			if ( in_array( $key, array_map( 'strval', $value ), true ) ) {
				$total += (float) ( $opt['price'] ?? 0 );
			}
		}
		return $total;
	}

	/**
	 * Standalone WC checkout bridge (AUDIT-FIX: 3-setting architecture).
	 *
	 * When "Enable WC Checkout" is on and a "WC Product ID" is set, the
	 * selected checkbox option(s) contribute toward that product's cart
	 * price. If "Option value as price → add to WC grand total" is also
	 * on, each selected option's value (interpreted as a number) is
	 * summed and added to the product's price.
	 */
	public function wc_contribution( $value, array $field_config, array $submission ) : array {
		if ( empty( $field_config['enable_wc'] ) ) return array();
		$product_id = absint( $field_config['wc_product_id'] ?? 0 );
		if ( ! $product_id ) return array();

		$price = 0.0;
		if ( ! empty( $field_config['wc_option_price'] ) ) {
			foreach ( (array) $value as $v ) {
				if ( is_numeric( $v ) ) {
					$price += (float) $v;
				}
			}
		}

		return array( array( 'product_id' => $product_id, 'price' => $price ) );
	}
}

/* ── Date ──────────────────────────────────────────────────────────── */
class Field_Date extends Field_Type {
	public function get_slug() : string  { return 'date'; }
	public function get_label() : string { return __( 'Date', 'credoq-engine' ); }
	public function get_icon() : string  { return 'calendar'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',    'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'min',      'type' => 'date',     'label' => __( 'Min date', 'credoq-engine' ) ),
			array( 'key' => 'max',      'type' => 'date',     'label' => __( 'Max date', 'credoq-engine' ) ),
			array( 'key' => 'required', 'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function validate( $value, array $field_config, array $submission ) {
		$parent = parent::validate( $value, $field_config, $submission );
		if ( is_wp_error( $parent ) ) return $parent;
		if ( '' !== $value && ! \DateTime::createFromFormat( 'Y-m-d', (string) $value ) ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date.', 'credoq-engine' ) );
		}
		return true;
	}
}

/* ── Time ──────────────────────────────────────────────────────────── */
class Field_Time extends Field_Type {
	public function get_slug() : string  { return 'time'; }
	public function get_label() : string { return __( 'Time', 'credoq-engine' ); }
	public function get_icon() : string  { return 'clock'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',    'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'required', 'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
}

/* ── Number ────────────────────────────────────────────────────────── */
class Field_Number extends Field_Type {
	public function get_slug() : string  { return 'number'; }
	public function get_label() : string { return __( 'Number', 'credoq-engine' ); }
	public function get_icon() : string  { return 'hash'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',    'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'min',      'type' => 'number',   'label' => __( 'Min', 'credoq-engine' ) ),
			array( 'key' => 'max',      'type' => 'number',   'label' => __( 'Max', 'credoq-engine' ) ),
			array( 'key' => 'step',     'type' => 'number',   'label' => __( 'Step', 'credoq-engine' ), 'default' => 1 ),
			array( 'key' => 'price_per_unit', 'type' => 'number', 'label' => __( 'Price per unit', 'credoq-engine' ), 'default' => 0 ),
			array( 'key' => 'required', 'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) { return (float) $value; }
	public function validate( $value, array $field_config, array $submission ) {
		$parent = parent::validate( $value, $field_config, $submission );
		if ( is_wp_error( $parent ) ) return $parent;
		$v = (float) $value;
		if ( isset( $field_config['min'] ) && '' !== $field_config['min'] && $v < (float) $field_config['min'] ) {
			return new \WP_Error( 'min_value', sprintf( __( 'Minimum value is %s.', 'credoq-engine' ), $field_config['min'] ) );
		}
		if ( isset( $field_config['max'] ) && '' !== $field_config['max'] && $v > (float) $field_config['max'] ) {
			return new \WP_Error( 'max_value', sprintf( __( 'Maximum value is %s.', 'credoq-engine' ), $field_config['max'] ) );
		}
		return true;
	}
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		return ( (float) $value ) * (float) ( $field_config['price_per_unit'] ?? 0 );
	}
}

/* ── File upload ───────────────────────────────────────────────────── */
class Field_File extends Field_Type {
	public function get_slug() : string     { return 'file'; }
	public function get_label() : string    { return __( 'File upload', 'credoq-engine' ); }
	public function get_icon() : string     { return 'paperclip'; }
	public function get_category() : string { return 'special'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',     'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'mime_list', 'type' => 'text',     'label' => __( 'Allowed types (comma-separated)', 'credoq-engine' ), 'default' => 'image/jpeg,image/png,application/pdf' ),
			array( 'key' => 'max_kb',    'type' => 'number',   'label' => __( 'Max size (KB)', 'credoq-engine' ), 'default' => 5120 ),
			array( 'key' => 'required',  'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	/**
	 * Validates the saved file reference (attachment ID or URL).
	 *
	 * NOTE: The actual file upload from the React widget happens via the
	 * admin-ajax 'credoq_submit_booking' action (Phase 1B), where strict
	 * mime + size checks against the field config's 'mime_list' and
	 * 'max_kb' are enforced before the file is moved into the uploads
	 * directory. This method runs AFTER that upload, when the field's
	 * value is already a sanitized attachment ID or URL.
	 *
	 * AUDIT-FIX S-2: strict allow-list of MIME types is enforced at upload
	 * time, not here. Here we only verify required-ness.
	 */
	public function validate( $value, array $field_config, array $submission ) {
		if ( ! empty( $field_config['required'] ) && empty( $value ) ) {
			return new \WP_Error( 'required', sprintf(
				/* translators: %s field label */
				__( '"%s" requires a file upload.', 'credoq-engine' ),
				$field_config['label'] ?? $this->get_label()
			) );
		}
		return true;
	}

	public function sanitize( $value, array $field_config ) {
		// File value is either an attachment ID (int-like string) or a URL.
		if ( is_numeric( $value ) ) return absint( $value );
		if ( is_string( $value ) )  return esc_url_raw( $value );
		return '';
	}
}

/* ── Hidden ────────────────────────────────────────────────────────── */
class Field_Hidden extends Field_Type {
	public function get_slug() : string     { return 'hidden'; }
	public function get_label() : string    { return __( 'Hidden', 'credoq-engine' ); }
	public function get_icon() : string     { return 'eye-off'; }
	public function get_category() : string { return 'special'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'name',  'type' => 'text', 'label' => __( 'Field name', 'credoq-engine' ) ),
			array( 'key' => 'value', 'type' => 'text', 'label' => __( 'Default value', 'credoq-engine' ) ),
		);
	}
}

/* ── HTML block ────────────────────────────────────────────────────── */
class Field_Html extends Field_Type {
	public function get_slug() : string  { return 'html'; }
	public function get_label() : string { return __( 'HTML block', 'credoq-engine' ); }
	public function get_icon() : string  { return 'code'; }
	public function get_category() : string { return 'special'; }
	public function get_settings_schema() : array {
		return array(
			// AUDIT-FIX: key matches what Forms_Page.php's admin builder
			// JS actually saves this field's content under (f.html_code),
			// not the previously-documented 'html' key.
			array( 'key' => 'html_code', 'type' => 'html', 'label' => __( 'HTML content', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		return wp_kses_post( (string) $value );
	}
}

/* ── Quantity stepper ──────────────────────────────────────────────── */
class Field_Quantity extends Field_Type {
	public function get_slug() : string     { return 'quantity'; }
	public function get_label() : string    { return __( 'Quantity', 'credoq-engine' ); }
	public function get_icon() : string     { return 'hash'; }
	public function get_category() : string { return 'booking'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',        'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'qty_min',      'type' => 'number',   'label' => __( 'Minimum', 'credoq-engine' ), 'default' => 1 ),
			array( 'key' => 'qty_max',      'type' => 'number',   'label' => __( 'Maximum', 'credoq-engine' ), 'default' => 99 ),
			array( 'key' => 'qty_multiply', 'type' => 'checkbox', 'label' => __( 'Multiply appointment price by quantity', 'credoq-engine' ) ),
			array( 'key' => 'required',     'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		return max( 0, (int) $value );
	}
}

/* ── Formula / calculate field ─────────────────────────────────────── */
class Field_Calculate extends Field_Type {
	public function get_slug() : string     { return 'calculate'; }
	public function get_label() : string    { return __( 'Formula', 'credoq-engine' ); }
	public function get_icon() : string     { return 'sigma'; }
	public function get_category() : string { return 'booking'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',           'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'formula',         'type' => 'text',     'label' => __( 'Formula expression', 'credoq-engine' ) ),
			array( 'key' => 'add_to_total',    'type' => 'checkbox', 'label' => __( 'Add result to booking total', 'credoq-engine' ) ),
			array( 'key' => 'enable_wc',       'type' => 'toggle',   'label' => __( 'Enable WC Checkout', 'credoq-engine' ) ),
			array( 'key' => 'wc_product_id',   'type' => 'number',   'label' => __( 'WC Product ID', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
			array( 'key' => 'wc_option_price', 'type' => 'checkbox', 'label' => __( 'Formula result as price → add to WC grand total', 'credoq-engine' ), 'show_if' => array( 'enable_wc' => true ) ),
		);
	}
	public function sanitize( $value, array $field_config ) { return (float) $value; }

	/**
	 * Bug 3 fix: when add_to_total is enabled, the frontend submits the
	 * calculated result as the field value (a float).  Return it here so
	 * Submission_Handler adds it to the total_price sum.
	 */
	public function price_contribution( $value, array $field_config, array $submission ) : float {
		if ( empty( $field_config['add_to_total'] ) ) return 0.0;
		return (float) $value;
	}

	/**
	 * Standalone WC checkout bridge (AUDIT-FIX: 3-setting architecture).
	 *
	 * When "Enable WC Checkout" is on and a "WC Product ID" is set, this
	 * formula's result contributes toward that product's cart price if
	 * "Formula result as price → add to WC grand total" is also on.
	 */
	public function wc_contribution( $value, array $field_config, array $submission ) : array {
		if ( empty( $field_config['enable_wc'] ) ) return array();
		$product_id = absint( $field_config['wc_product_id'] ?? 0 );
		if ( ! $product_id ) return array();

		$price = ! empty( $field_config['wc_option_price'] ) ? (float) $value : 0.0;

		return array( array( 'product_id' => $product_id, 'price' => $price ) );
	}
}

/* ── Total price display ───────────────────────────────────────────── */
class Field_Total_Price extends Field_Type {
	public function get_slug() : string     { return 'total_price'; }
	public function get_label() : string    { return __( 'Total price', 'credoq-engine' ); }
	public function get_icon() : string     { return 'dollar-sign'; }
	public function get_category() : string { return 'booking'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'total_label', 'type' => 'text', 'label' => __( 'Display label', 'credoq-engine' ), 'default' => 'Total' ),
		);
	}
	public function sanitize( $value, array $field_config ) { return ''; }
}

/* ── Signature pad ─────────────────────────────────────────────────── */
class Field_Signature extends Field_Type {
	public function get_slug() : string     { return 'signature'; }
	public function get_label() : string    { return __( 'Signature', 'credoq-engine' ); }
	public function get_icon() : string     { return 'pen-tool'; }
	public function get_category() : string { return 'special'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label',    'type' => 'text',     'label' => __( 'Label', 'credoq-engine' ) ),
			array( 'key' => 'required', 'type' => 'checkbox', 'label' => __( 'Required', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) {
		if ( is_string( $value ) && 0 === strpos( $value, 'data:image/' ) ) {
			return $value;
		}
		return '';
	}
}

/* ── Step divider ──────────────────────────────────────────────────── */
class Field_Step extends Field_Type {
	public function get_slug() : string     { return 'step'; }
	public function get_label() : string    { return __( 'Step divider', 'credoq-engine' ); }
	public function get_icon() : string     { return 'minus'; }
	public function get_category() : string { return 'layout'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'step_title', 'type' => 'text', 'label' => __( 'Step title', 'credoq-engine' ) ),
		);
	}
	public function sanitize( $value, array $field_config ) { return ''; }
}

/* ── Page break ────────────────────────────────────────────────────── */
class Field_Page_Break extends Field_Type {
	public function get_slug() : string     { return 'page_break'; }
	public function get_label() : string    { return __( 'Page break', 'credoq-engine' ); }
	public function get_icon() : string     { return 'layout'; }
	public function get_category() : string { return 'layout'; }
	public function get_settings_schema() : array { return array(); }
	public function sanitize( $value, array $field_config ) { return ''; }
}

/* ── Submit button ─────────────────────────────────────────────────── */
class Field_Submit_Button extends Field_Type {
	public function get_slug() : string     { return 'submit'; }
	public function get_label() : string    { return __( 'Submit button', 'credoq-engine' ); }
	public function get_icon() : string     { return 'send'; }
	public function get_category() : string { return 'layout'; }
	public function get_settings_schema() : array {
		return array(
			array( 'key' => 'label', 'type' => 'text', 'label' => __( 'Button label', 'credoq-engine' ), 'default' => 'Submit' ),
		);
	}
	public function sanitize( $value, array $field_config ) { return ''; }
}
