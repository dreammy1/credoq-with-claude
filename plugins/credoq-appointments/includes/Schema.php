<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

class Schema {

    const DB_VERSION_KEY = 'credoq_apt_db_version';
    const DB_VERSION     = '1.0.1'; // bumped: forces dbDelta to add any missing columns

    public static function install() : void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Services (Appointments) ────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_appointments (
            id                    bigint unsigned NOT NULL AUTO_INCREMENT,
            title                 varchar(200)    NOT NULL DEFAULT '',
            location              varchar(200)    NOT NULL DEFAULT '',
            description           text,
            duration              int unsigned    NOT NULL DEFAULT 60,
            slot_interval         int unsigned    NOT NULL DEFAULT 60,
            max_bookings          int unsigned    NOT NULL DEFAULT 1,
            base_price            decimal(10,2)   NOT NULL DEFAULT 0.00,
            wc_product_id         bigint unsigned NOT NULL DEFAULT 0,
            staff_ids             longtext,
            availability          longtext,
            allow_multi_booking   tinyint(1)      NOT NULL DEFAULT 0,
            multi_price_mode      varchar(20)     NOT NULL DEFAULT 'per_session',
            multi_day_rate        decimal(10,2)   NOT NULL DEFAULT 0.00,
            capacity_mode         varchar(20)     NOT NULL DEFAULT 'per_staff',
            capacity_value        int unsigned    NOT NULL DEFAULT 1,
            min_schedules         int unsigned    NOT NULL DEFAULT 1,
            max_schedules         int unsigned    NOT NULL DEFAULT 1,
            credit_deduct_enabled tinyint(1)      NOT NULL DEFAULT 0,
            credit_deduct_amount  int unsigned    NOT NULL DEFAULT 1,
            booking_settings      longtext,
            accent_color          varchar(7)      NOT NULL DEFAULT '#4f46e5',
            image_url             varchar(500)    NOT NULL DEFAULT '',
            created_at            datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wc_product_id (wc_product_id)
        ) $charset;" );

        // ── Staff ──────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_staff (
            id               bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id          bigint unsigned NOT NULL DEFAULT 0,
            display_name     varchar(200)    NOT NULL DEFAULT '',
            email            varchar(200)    NOT NULL DEFAULT '',
            bio              text,
            avatar_url       varchar(500)    NOT NULL DEFAULT '',
            availability     longtext,
            special_dates    longtext,
            price_multiplier decimal(5,2)    NOT NULL DEFAULT 1.00,
            created_at       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;" );

        // ── Bookings ───────────────────────────────────────────────────
        // AUDIT-FIX A-4: seat_ids and cvsp_booking_id here from day 1
        // so the seats addon never needs to ALTER this table.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_bookings (
            id               bigint unsigned NOT NULL AUTO_INCREMENT,
            appointment_id   bigint unsigned NOT NULL,
            staff_id         bigint unsigned NOT NULL DEFAULT 0,
            user_id          bigint unsigned NOT NULL DEFAULT 0,
            guest_name       varchar(200)    NOT NULL DEFAULT '',
            guest_email      varchar(200)    NOT NULL DEFAULT '',
            selected_date    date            NOT NULL,
            selected_time    time            NOT NULL,
            duration         int unsigned    NOT NULL DEFAULT 60,
            status           varchar(20)     NOT NULL DEFAULT 'pending',
            total_price      decimal(10,2)   NOT NULL DEFAULT 0.00,
            credit_deducted  int unsigned    NOT NULL DEFAULT 0,
            form_data        longtext,
            wc_order_id      bigint unsigned NOT NULL DEFAULT 0,
            group_id         varchar(36)     NOT NULL DEFAULT '',
            group_index      int unsigned    NOT NULL DEFAULT 0,
            seat_ids         longtext,
            cvsp_booking_id  bigint unsigned NOT NULL DEFAULT 0,
            notes            text,
            reminder_sent    tinyint(1)      NOT NULL DEFAULT 0,
            created_at       datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY appointment_id (appointment_id),
            KEY staff_id (staff_id),
            KEY selected_date (selected_date),
            KEY status (status),
            KEY group_id (group_id),
            KEY wc_order_id (wc_order_id)
        ) $charset;" );

        // ── Waiting List ───────────────────────────────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}credoq_waiting_list (
            id             bigint unsigned NOT NULL AUTO_INCREMENT,
            appointment_id bigint unsigned NOT NULL,
            staff_id       bigint unsigned NOT NULL DEFAULT 0,
            booking_date   date            NOT NULL,
            booking_time   time            NOT NULL,
            user_id        bigint unsigned NOT NULL DEFAULT 0,
            guest_email    varchar(200)    NOT NULL DEFAULT '',
            status         varchar(20)     NOT NULL DEFAULT 'waiting',
            offer_sent_at  datetime,
            expires_at     datetime,
            created_at     datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY appointment_slot (appointment_id, booking_date, booking_time),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;" );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    public static function maybe_upgrade() : void {
        if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) self::install();
    }
}
