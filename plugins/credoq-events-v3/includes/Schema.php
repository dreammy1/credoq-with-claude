<?php
namespace CredoqEvents;
defined( 'ABSPATH' ) || exit;

class Schema {
    public static function maybe_upgrade() : void {
        if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) self::install();
    }
    const DB_VERSION_KEY = 'credoq_events_db_version';
    // AUDIT-FIX (WC checkout redirect for Event Registration form field):
    // bumped to add submission_id — links a credoq_event_bookings row back
    // to the credoq_submissions row that created it (via the Form Builder's
    // "Event Registration" field), so WooCommerce order-status hooks can
    // find and confirm/cancel the right booking(s) after checkout.
    const DB_VERSION     = '1.0.3';

    public static function install() : void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_events (
            id                    bigint unsigned NOT NULL AUTO_INCREMENT,
            title                 varchar(200)    NOT NULL DEFAULT '',
            description           longtext,
            start_datetime        datetime        NOT NULL,
            end_datetime          datetime        NOT NULL,
            location              varchar(500)    NOT NULL DEFAULT '',
            capacity              int unsigned    NOT NULL DEFAULT 0,
            price                 decimal(10,2)   NOT NULL DEFAULT 0.00,
            wc_product_id         bigint unsigned NOT NULL DEFAULT 0,
            staff_id              bigint unsigned NOT NULL DEFAULT 0,
            accent_color          varchar(7)      NOT NULL DEFAULT '#4f46e5',
            image_url             varchar(500)    NOT NULL DEFAULT '',
            zoom_link             varchar(500)    NOT NULL DEFAULT '',
            google_meet_link      varchar(500)    NOT NULL DEFAULT '',
            credit_deduct_enabled tinyint(1)      NOT NULL DEFAULT 0,
            credit_deduct_amount  int unsigned    NOT NULL DEFAULT 1,
            status                varchar(20)     NOT NULL DEFAULT 'published',
            created_at            datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY start_datetime (start_datetime),
            KEY status (status),
            KEY wc_product_id (wc_product_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_event_bookings (
            id               bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id         bigint unsigned NOT NULL,
            user_id          bigint unsigned NOT NULL DEFAULT 0,
            guest_name       varchar(200)    NOT NULL DEFAULT '',
            guest_email      varchar(200)    NOT NULL DEFAULT '',
            quantity         int unsigned    NOT NULL DEFAULT 1,
            total_price      decimal(10,2)   NOT NULL DEFAULT 0.00,
            status           varchar(20)     NOT NULL DEFAULT 'confirmed',
            qr_token         varchar(64)     NOT NULL DEFAULT '',
            scan_logs        longtext,
            wc_order_id      bigint unsigned NOT NULL DEFAULT 0,
            credit_deducted  int unsigned    NOT NULL DEFAULT 0,
            reminder_sent    tinyint(1)      NOT NULL DEFAULT 0,
            submission_id    bigint unsigned NOT NULL DEFAULT 0,
            created_at       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY qr_token (qr_token),
            KEY wc_order_id (wc_order_id),
            KEY submission_id (submission_id)
        ) $charset;" );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }
}
