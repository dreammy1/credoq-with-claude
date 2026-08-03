<?php
/**
 * Field Type Registry.
 *
 * The central addon API. Every addon (Appointments, Events, Seat,
 * Membership, QR) registers itself by adding a field type here.
 *
 * Example addon registration:
 *
 *   add_filter( 'credoq_register_field_types', function( $registry ) {
 *       $registry->register( new My_Appointment_Field_Type() );
 *   });
 *
 * @package CredoqEngine\Fields
 */

namespace CredoqEngine\Fields;

use CredoqEngine\Abstracts\Field_Type;

defined( 'ABSPATH' ) || exit;

class Registry {

	/** @var Field_Type[] keyed by type slug */
	private $types = array();

	/**
	 * Register a field type. Idempotent — re-registering the same
	 * slug overwrites, with a debug log entry.
	 */
	public function register( Field_Type $type ) : void {
		$slug = $type->get_slug();
		if ( '' === $slug ) {
			credoq_log( 'Refused to register field type with empty slug', 'error' );
			return;
		}
		if ( isset( $this->types[ $slug ] ) ) {
			credoq_log(
				sprintf( 'Field type "%s" re-registered (was %s, now %s)', $slug, get_class( $this->types[ $slug ] ), get_class( $type ) ),
				'warning'
			);
		}
		$this->types[ $slug ] = $type;
	}

	public function unregister( string $slug ) : void {
		unset( $this->types[ $slug ] );
	}

	public function has( string $slug ) : bool {
		return isset( $this->types[ $slug ] );
	}

	public function get( string $slug ) : ?Field_Type {
		return $this->types[ $slug ] ?? null;
	}

	/** @return Field_Type[] */
	public function all() : array {
		return $this->types;
	}

	/**
	 * Returns the schema descriptor for the form-builder UI. Each
	 * field type contributes its label, icon, category, and the
	 * editable settings the admin can configure.
	 */
	public function builder_descriptors() : array {
		$out = array();
		foreach ( $this->types as $slug => $type ) {
			$out[] = array(
				'slug'        => $slug,
				'label'       => $type->get_label(),
				'icon'        => $type->get_icon(),
				'category'    => $type->get_category(),
				'description' => $type->get_description(),
				'settings'    => $type->get_settings_schema(),
				'addon'       => $type->get_addon_id(),
			);
		}
		return $out;
	}
}
