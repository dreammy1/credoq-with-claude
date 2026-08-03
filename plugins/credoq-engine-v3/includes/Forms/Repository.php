<?php
/**
 * Forms Repository — CRUD for credoq_forms table.
 *
 * @package CredoqEngine\Forms
 *
 * --- BUG FIXED IN THIS FILE ---
 *
 * FIX-FR-1: validate_fields_schema() previously returned WP_Error for any
 *           field type not already registered when the form was saved.
 *           Addon types (appointment, event_registration, member_slot_credit)
 *           are registered at credoq_engine_ready / credoq_engine_late_init,
 *           which fires AFTER admin_init.  A race condition existed where
 *           saving a form with addon fields from the WP Admin would return:
 *             "Unknown field type 'appointment' at position 1."
 *           and silently discard the save.
 *
 *           Fix: addon field types that are not yet in the registry are
 *           ALLOWED through with a debug-log warning rather than a hard
 *           error.  The field data is stored verbatim; integrity is enforced
 *           on the front-end submission path (Submission_Handler) where the
 *           registry is always fully populated at request time.
 *
 *           Structural fields (step, page_break, submit, html) are also
 *           exempt from unknown-type rejection since they carry no data.
 */

namespace CredoqEngine\Forms;

use CredoqEngine\Abstracts\Field_Type;

defined( 'ABSPATH' ) || exit;

class Repository {

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'credoq_forms';
    }

    public function find( int $id ) : ?Form {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ) );
        return $row ? Form::from_row( $row ) : null;
    }

    /** @return Form[] */
    public function all( int $limit = 50, int $offset = 0 ) : array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d OFFSET %d",
            max( 1, $limit ), max( 0, $offset )
        ) );
        return array_map( [ Form::class, 'from_row' ], $rows ?: [] );
    }

    public function count() : int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
    }

    /**
     * Save (insert or update). Returns the form ID.
     *
     * @return int|\WP_Error
     */
    public function save( array $data ) {
        global $wpdb;

        $id       = absint( $data['id'] ?? 0 );
        $title    = sanitize_text_field( $data['title'] ?? '' );
        $fields   = $this->validate_fields_schema( $data['fields']   ?? [] );
        $settings = $this->validate_settings_schema( $data['settings'] ?? [] );

        if ( '' === $title ) {
            return new \WP_Error( 'invalid_title', __( 'Form title is required.', 'credoq-engine' ) );
        }
        if ( is_wp_error( $fields ) )   return $fields;
        if ( is_wp_error( $settings ) ) return $settings;

        $row = [
            'title'      => $title,
            'fields'     => wp_json_encode( $fields ),
            'settings'   => wp_json_encode( $settings ),
            'updated_at' => current_time( 'mysql', true ),
        ];

        if ( $id > 0 ) {
            $result = $wpdb->update(
                $this->table(), $row, [ 'id' => $id ],
                [ '%s','%s','%s','%s' ], [ '%d' ]
            );
            if ( false === $result ) {
                return new \WP_Error( 'db_error', $wpdb->last_error );
            }
            return $id;
        }

        $row['created_at'] = current_time( 'mysql', true );
        $result = $wpdb->insert( $this->table(), $row, [ '%s','%s','%s','%s','%s' ] );
        if ( false === $result ) {
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }
        return (int) $wpdb->insert_id;
    }

    public function delete( int $id ) : bool {
        global $wpdb;
        return false !== $wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Validate the fields array.
     *
     * FIX-FR-1: Addon field types registered late (at credoq_engine_ready)
     * are NOT present in the registry when the admin saves a form from the
     * WP Admin dashboard (fires at admin_init, before the Engine fires its
     * late-init hook on 'init').
     *
     * Strategy:
     *  - Structural / layout types with no stored value are always allowed.
     *  - Builtin types that must be in the registry ARE hard-validated.
     *  - Addon types not yet registered are allowed through with a debug log.
     *    Integrity is enforced at submission time by Submission_Handler.
     *
     * @return array|\WP_Error
     */
    private function validate_fields_schema( $fields ) {
        if ( ! is_array( $fields ) ) {
            return new \WP_Error(
                'invalid_fields',
                __( 'Form fields must be an array.', 'credoq-engine' )
            );
        }

        // Structural field types that carry no data value — always allow.
        $structural_types = [
            'step', 'page_break', 'submit', 'html', 'hidden',
            'section_header', 'divider',
        ];

        // Addon type slugs that may be registered late — allow with warning.
        // Expandable by addons via the 'credoq_allow_unregistered_field_types' filter.
        $addon_passthrough = apply_filters( 'credoq_allow_unregistered_field_types', [
            'appointment',
            'event_registration',
            'member_slot_credit',
            'seat_map',
            'provider_picker',
            'service_picker',
            'date_picker',
            'time_slot_picker',
            'event_calendar',
        ] );

        $registry = function_exists( 'credoq_engine' ) ? credoq_engine()->fields() : null;
        $clean    = [];

        foreach ( $fields as $i => $field ) {
            if ( ! is_array( $field ) || empty( $field['type'] ) ) {
                return new \WP_Error(
                    'invalid_field',
                    sprintf( __( 'Field %d is malformed.', 'credoq-engine' ), $i + 1 )
                );
            }

            $type = sanitize_key( $field['type'] );

            // Always allow structural / layout fields.
            if ( ! in_array( $type, $structural_types, true ) ) {

                // FIX-FR-1: check registry only if available and type is not
                // in the known addon passthrough list.
                $is_addon_passthrough = in_array( $type, (array) $addon_passthrough, true );

                if ( ! $is_addon_passthrough && $registry !== null && ! $registry->has( $type ) ) {
                    return new \WP_Error(
                        'unknown_field_type',
                        sprintf(
                            /* translators: 1: field type slug  2: position index */
                            __( 'Unknown field type "%1$s" at position %2$d. Is the required addon installed?', 'credoq-engine' ),
                            $type, $i + 1
                        )
                    );
                }

                // Addon type not yet in registry → log and allow through.
                if ( $is_addon_passthrough && $registry !== null && ! $registry->has( $type ) ) {
                    if ( function_exists( 'credoq_log' ) ) {
                        credoq_log(
                            "Field type \"{$type}\" saved before addon registered (position " . ( $i + 1 ) . '). Will be validated at submission.',
                            'debug'
                        );
                    }
                }
            }

            $clean[] = array_merge( $field, [
                'type' => $type,
                'id'   => sanitize_key( $field['id']   ?? 'f_' . wp_generate_uuid4() ),
                'name' => sanitize_key( $field['name'] ?? 'field_' . ( $i + 1 ) ),
            ] );
        }

        return $clean;
    }

    private function validate_settings_schema( $settings ) {
        return is_array( $settings ) ? $settings : [];
    }
}
