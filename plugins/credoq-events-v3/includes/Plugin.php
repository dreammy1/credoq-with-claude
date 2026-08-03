<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

final class Plugin {
    public static function boot() : void {
        Schema::maybe_upgrade();
        // FIX: Hook both credoq_engine_ready AND credoq_engine_late_init.
        add_action( 'credoq_engine_ready',     [ __CLASS__, 'on_engine_ready' ], 10, 1 );
        add_action( 'credoq_engine_late_init', [ __CLASS__, 'on_engine_ready' ], 10, 1 );
        // FIX: Register field type during Engine's explicit field-type-registration phase.
        add_action( 'credoq_register_field_types', function( $registry ) {
            $registry->register( new Fields\Field_Event() );
        }, 10, 1 );
        Ajax\Event_Ajax::register();
        if ( class_exists('WooCommerce') ) {
            Integrations\WooCommerce::register();
        }
        add_action('init', [__CLASS__,'register_shortcodes']);
        add_action('wp_enqueue_scripts', [__CLASS__,'enqueue_frontend']);

        // QR scan, user dashboard and WC notification hooks.
        require_once CREDOQ_EVENTS_DIR . 'includes/Ajax/Scan_Handler.php';
        Ajax\Scan_Handler::register();

        // Inject upcoming events into the React widget config so fields
        // with _frontend.component='select' get fresh event data without
        // an extra REST request.
        add_filter( 'credoq_widget_config', function( array $config, $form ) : array {
            global $wpdb;
            $table = $wpdb->prefix . 'credoq_events';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return $config;
            }
            $rows = $wpdb->get_results(
                "SELECT id, title, start_datetime, price, capacity
                 FROM {$table}
                 WHERE status = 'published' AND start_datetime >= NOW()
                 ORDER BY start_datetime ASC LIMIT 50"
            );
            $config['upcoming_events'] = array_map( function( $ev ) {
                $booked    = Event_Repository::booked_count( (int) $ev->id );
                $remaining = $ev->capacity > 0 ? max( 0, $ev->capacity - $booked ) : null;
                return [
                    'id'           => (int) $ev->id,
                    'title'        => (string) $ev->title,
                    'start'        => (string) $ev->start_datetime,
                    'price'        => (float) $ev->price,
                    'remaining'    => $remaining,
                ];
            }, (array) $rows );
            return $config;
        }, 10, 2 );
        // Add Reports tab
        add_filter('credoq_reports_tabs', function(array $tabs): array {
            $tabs['events'] = [
                'label'    => __('Events','credoq-events'),
                'icon'     => 'dashicons-tickets-alt',
                'callback' => [Admin\Reports_Tab::class,'render'],
            ];
            return $tabs;
        });

        // AUDIT-FIX (double credit deduction): Membership's
        // Field_Slot_Credit::on_submission() always deducts credits itself
        // via the 'credoq_membership_credit_cost' filter (default: a flat
        // 1, regardless of quantity). Field_Event::on_submission() already
        // computes and deducts the correct qty × "Credits per Ticket"
        // amount for its own selections. Without this, a form containing
        // both an Event Registration field (credit deduction enabled on
        // the event) and a Member Slot Credit field would deduct TWICE —
        // once correctly by Events, once flatly (and wrongly, e.g. always
        // "1" regardless of the 3 tickets selected) by Membership. This
        // suppresses Membership's own deduction whenever the submission
        // contains an event_registration-shaped value, since Events has
        // already handled it precisely.
        add_filter( 'credoq_membership_credit_cost', function( $cost, $submission_id, $submission_payload ) {
            foreach ( (array) $submission_payload as $val ) {
                if ( ! is_string( $val ) || '' === $val ) continue;
                $decoded = json_decode( $val, true );
                if ( is_array( $decoded ) && ( isset( $decoded['event_id'] )
                    || ( isset( $decoded[0] ) && is_array( $decoded[0] ) && isset( $decoded[0]['event_id'] ) ) ) ) {
                    return 0;
                }
            }
            return $cost;
        }, 10, 3 );
    }
    public static function on_engine_ready($engine) : void {
        // Guard: may be called from both credoq_engine_ready and credoq_engine_late_init.
        static $done = false;
        if ( $done ) return;
        $done = true;

        // Register Event field type in the form builder.
        if ( method_exists( $engine->fields(), 'register' ) ) {
            $engine->fields()->register( new Fields\Field_Event() );
        }

        Admin\Menu::register();
        Dashboard\Events_Panel::register();
    }
    public static function register_shortcodes() : void {
        add_shortcode('credoq_events_list',   [Shortcodes::class, 'events_list']);
        add_shortcode('credoq_event_register',[Shortcodes::class, 'register_form']);
    }
    public static function enqueue_frontend() : void {
        wp_register_style('credoq-events-frontend', CREDOQ_EVENTS_URL.'assets/css/frontend.css', [], CREDOQ_EVENTS_VERSION);
    }
}
