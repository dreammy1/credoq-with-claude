<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

final class Plugin {

    public static function boot() : void {
        Schema::maybe_upgrade();

        // FIX: Hook both credoq_engine_ready (for same-priority loading) AND
        // credoq_engine_late_init (for when we load at priority 20 and miss the first fire).
        add_action( 'credoq_engine_ready',     [ __CLASS__, 'on_engine_ready' ], 10, 1 );
        add_action( 'credoq_engine_late_init', [ __CLASS__, 'on_engine_ready' ], 10, 1 );

        // FIX: Also hook credoq_register_field_types so our field type is available
        // in the form builder even if on_engine_ready fires late.
        // credoq_register_field_types fires during Engine boot with the registry as arg.
        // Since we boot at priority 20 (after Engine at 10), this hook is already past.
        // on_engine_ready via credoq_engine_late_init handles it instead, but we
        // register here too as belt-and-suspenders for same-priority scenarios.
        add_action( 'credoq_register_field_types', function( $registry ) {
            $registry->register( new Fields\Field_Appointment() );
        }, 10, 1 );

        // AJAX handlers
        Ajax\Slots_Handler::register();
        Ajax\Booking_Handler::register();

        // Cron
        Cron\Apt_Cron::register_hooks();

        // WooCommerce
        if ( class_exists('WooCommerce') ) {
            Integrations\WooCommerce::register();
        }

        // Frontend shortcodes
        add_action('init', [__CLASS__, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend']);
    }

    public static function on_engine_ready($engine) : void {
        // Guard: may be called from both credoq_engine_ready and credoq_engine_late_init.
        static $done = false;
        if ( $done ) return;
        $done = true;

        // Register Appointment field type in the form builder.
        if ( method_exists( $engine->fields(), 'register' ) ) {
            $engine->fields()->register( new Fields\Field_Appointment() );
        }

        // Register appointment booking REST API routes (primary path for frontend widget,
        // avoids admin-ajax.php blocks on shared hosting).
        Api\Booking_Rest::register();

        Admin\Menu::register();
        Dashboard\Schedule_Panel::register();

        // FIX: Inject appointment data into the React booking widget config.
        // The widget reads cfg.appointments[], cfg.appointment_title, cfg.base_price, etc.
        // Without this filter the widget rendered an empty service list.
        add_filter( 'credoq_widget_config', [ __CLASS__, 'inject_widget_config' ], 10, 2 );

        // Register reports tab with Engine
        add_filter('credoq_reports_tabs', function(array $tabs) : array {
            $tabs['bookings'] = [
                'label'    => __('Appointments','credoq-appointments'),
                'icon'     => 'dashicons-calendar-alt',
                'callback' => [Admin\Reports_Tab::class, 'render'],
            ];
            return $tabs;
        });
    }

    public static function register_shortcodes() : void {
        // NOTE: [credoq_booking_form] is intentionally NOT registered here.
        // The Engine owns [credoq_booking_form] and renders the React booking widget.
        // Appointments injects its data (services, slots, staff) into that widget
        // via the 'credoq_widget_config' filter in Plugin::inject_widget_config().
        // Registering it here would OVERWRITE the Engine's shortcode and load the
        // old vanilla-JS widget instead of the React bundle → blank page.

        // [credoq_my_schedule] is unique to Appointments and safe to register here.
        add_shortcode('credoq_my_schedule', [Shortcodes::class, 'my_schedule']);
    }

    public static function enqueue_frontend() : void {
        // Only register the addon CSS (appointment-specific styling).
        // The React booking widget JS is loaded by the Engine's Assets::enqueue_widget()
        // when the [credoq_booking_form] shortcode renders. Do NOT enqueue the old
        // booking-widget.js here — it conflicts with the React bundle.
        wp_register_style(
            'credoq-appointments-frontend',
            CREDOQ_APT_URL . 'assets/css/frontend.css',
            [],
            CREDOQ_APT_VERSION
        );
    }

    /**
     * Inject appointment data into the React booking widget config.
     *
     * The React widget (BookingWidget.jsx) reads:
     *   cfg.appointments[]      — array of service objects {id,title,location,base_price,...}
     *   cfg.appointment_title   — title of the pre-selected service
     *   cfg.appointment_location
     *   cfg.base_price
     *   cfg.slot_interval
     *   cfg.allow_multi_booking
     *   cfg.waiting_list_enabled
     *
     * @param array $config  Existing widget config.
     * @param object $form   Current form object.
     * @return array
     */
    public static function inject_widget_config( array $config, $form ) : array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'credoq_appointments';

        // Guard: table may not exist yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
            return $config;
        }

        // Load ALL active appointments for service-picker / all-mode forms.
        // FIX: column is `title` not `name`. No `status` column — fetch all.
        $rows = $wpdb->get_results(
            "SELECT id, title, location, base_price, duration, slot_interval,
                    allow_multi_booking, multi_price_mode, multi_day_rate,
                    max_schedules, min_schedules, capacity_mode, capacity_value,
                    credit_deduct_enabled, credit_deduct_amount, accent_color, image_url
             FROM {$tbl}
             ORDER BY id ASC",
            ARRAY_A
        );

        $appointments = [];
        foreach ( (array) $rows as $row ) {
            $appointments[] = [
                'id'                   => (int)    $row['id'],
                'title'                => (string) $row['title'],
                'location'             => (string) $row['location'],
                'base_price'           => (float)  $row['base_price'],
                'duration'             => (int)    $row['duration'],
                'slot_interval'        => (int)    $row['slot_interval'],
                'allow_multi_booking'  => (int)    $row['allow_multi_booking'],
                'multi_price_mode'     => (string) $row['multi_price_mode'],
                'multi_day_rate'       => (float)  $row['multi_day_rate'],
                'max_schedules'        => (int)    $row['max_schedules'],
                'min_schedules'        => (int)    $row['min_schedules'],
                'capacity_mode'        => (string) $row['capacity_mode'],
                'capacity_value'       => (int)    $row['capacity_value'],
                'credit_deduct_enabled'=> (int)    $row['credit_deduct_enabled'],
                'credit_deduct_amount' => (int)    $row['credit_deduct_amount'],
                'accent_color'         => (string) $row['accent_color'],
                'image_url'            => (string) $row['image_url'],
            ];
        }

        $config['appointments'] = $appointments;

        // If a specific appointment_id was requested via shortcode attr, pre-populate.
        $apt_id = (int) ( $config['appointment_id'] ?? 0 );
        if ( $apt_id ) {
            $apt = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$tbl} WHERE id = %d LIMIT 1", $apt_id ), ARRAY_A );
            if ( $apt ) {
                $config['appointment_title']    = (string) $apt['title'];
                $config['appointment_location'] = (string) $apt['location'];
                $config['base_price']           = (float)  $apt['base_price'];
                $config['slot_interval']        = (int)    $apt['slot_interval'];
                $config['allow_multi_booking']  = (int)    $apt['allow_multi_booking'];
                $config['multi_price_mode']     = (string) $apt['multi_price_mode'];
                $config['multi_day_rate']       = (float)  $apt['multi_day_rate'];
                $config['max_schedules']        = (int)    $apt['max_schedules'];
                $config['min_schedules']        = (int)    $apt['min_schedules'];
                $config['credit_deduct_enabled']= (int)    $apt['credit_deduct_enabled'];
                $config['credit_deduct_amount'] = (int)    $apt['credit_deduct_amount'];
            }
        }

        // Waiting list support.
        $config['waiting_list_enabled'] = (bool) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_waiting_list LIMIT 1" ) !== false;

        return $config;
    }
}
