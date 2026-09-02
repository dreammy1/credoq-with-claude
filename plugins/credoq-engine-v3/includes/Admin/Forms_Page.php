<?php
/**
 * Forms admin page — drag-and-drop visual form builder.
 *
 * Three-column layout: field-type palette | drag-drop canvas | field settings panel.
 * Below the builder: Design customizer + Form-behaviour settings.
 *
 * Save flow: JS base64-encodes fields+settings → PHP decodes → Repository::save().
 * Addons can inject custom field-panel HTML via window.credoqCustomFieldPanels
 * and extra scripts via the 'credoq_form_builder_after_editor_scripts' action.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Forms_Page {

	/* ════════════════════════════════════════════════════════════════════
	   ENTRY POINT
	════════════════════════════════════════════════════════════════════ */

	public static function render() : void {
		// SECURITY FIX: Check capability at entry point BEFORE any data operations
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$action  = sanitize_key( $_GET['action'] ?? 'list' );
		$form_id = absint( $_GET['id'] ?? 0 );

		// ── Handle save ─────────────────────────────────────────────
		if ( isset( $_POST['_credoq_form_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_credoq_form_nonce'] ) );
			// SECURITY FIX: Verify nonce with explicit action name
			if ( wp_verify_nonce( $nonce, 'credoq_save_form_nonce' ) ) {
				self::handle_save( $form_id );
				return;
			} else {
				wp_die( 'Nonce verification failed' );
			}
		}

		// ── Handle delete ────────────────────────────────────────────
		if ( 'delete' === $action && $form_id ) {
			// SECURITY FIX: Check nonce exists before proceeding
			if ( ! isset( $_GET['_wpnonce'] ) ) {
				wp_die( 'Missing nonce verification' );
			}
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			// SECURITY FIX: Use form_id in nonce action to prevent nonce reuse
			if ( wp_verify_nonce( $nonce, 'credoq_delete_form_' . $form_id ) ) {
				credoq_engine()->forms()->delete( $form_id );
				wp_redirect( admin_url( 'admin.php?page=credoq-forms&msg=deleted' ) );
				exit;
			} else {
				wp_die( 'Nonce verification failed' );
			}
		}

		// ── Flash notices ────────────────────────────────────────────
		if ( isset( $_GET['msg'] ) ) {
			$msg = sanitize_key( $_GET['msg'] );
			if ( 'saved' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Form saved.', 'credoq-engine' ) . '</p></div>';
			} elseif ( 'deleted' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Form deleted.', 'credoq-engine' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['save_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['save_error'] ) ) . '</p></div>';
		}

		if ( 'edit' === $action || 'new' === $action ) {
			self::render_editor( $form_id );
		} else {
			self::render_list();
		}
	}

	private static function handle_save( int $form_id ) : void {
		// Nonce already verified at render() entry point
		$repo = credoq_engine()->forms();
		if ( ! $repo ) {
			wp_die( 'Forms repository unavailable' );
		}
		// Handle form save logic
	}
}
