<?php
/**
 * Form value object — a lightweight wrapper around a credoq_forms row.
 *
 * @package CredoqEngine\Forms
 */

namespace CredoqEngine\Forms;

defined( 'ABSPATH' ) || exit;

class Form {
	public int    $id;
	public string $title;
	public array  $fields;
	public array  $settings;
	public string $created_at;
	public string $updated_at;

	public static function from_row( $row ) : self {
		$f             = new self();
		$f->id         = (int) $row->id;
		$f->title      = (string) $row->title;
		$f->fields     = json_decode( $row->fields, true ) ?: array();
		$f->settings   = json_decode( $row->settings, true ) ?: array();
		$f->created_at = (string) ( $row->created_at ?? '' );
		$f->updated_at = (string) ( $row->updated_at ?? '' );
		return $f;
	}

	/** Look up one field by its name. */
	public function field_by_name( string $name ) : ?array {
		foreach ( $this->fields as $field ) {
			if ( ( $field['name'] ?? '' ) === $name ) return $field;
		}
		return null;
	}

	/** Look up one field by its ID. */
	public function field_by_id( string $id ) : ?array {
		foreach ( $this->fields as $field ) {
			if ( ( $field['id'] ?? '' ) === $id ) return $field;
		}
		return null;
	}

	public function to_array() : array {
		return array(
			'id'         => $this->id,
			'title'      => $this->title,
			'fields'     => $this->fields,
			'settings'   => $this->settings,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
		);
	}
}
