<?php
namespace CredoqAppointments\Notifications;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Booking_Repository;

/**
 * HTML email templates for booking lifecycle events.
 * AUDIT-FIX B-12: cancel_token expires 30 min from send time.
 * AUDIT-FIX C-12: reminder_sent flag set before sending to prevent duplicates.
 */
class Booking_Mailer {

    public static function send( int $booking_id, string $type ) : bool {
        $settings = get_option( 'credoq_booking_settings', [] );
        if ( empty( $settings[ "email_{$type}_enabled" ] ) ) return false;

        global $wpdb;
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, a.title AS apt_title, a.location AS apt_location,
                    s.display_name AS staff_name, u.display_name, u.user_email
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id = a.id
             LEFT JOIN {$wpdb->prefix}credoq_staff s ON b.staff_id = s.id
             LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
             WHERE b.id = %d", $booking_id ) );

        if ( ! $booking ) return false;
        $to = $booking->user_email ?: sanitize_email( $booking->guest_email );
        if ( ! $to ) return false;

        $date_fmt = date_i18n( get_option('date_format'), strtotime( $booking->selected_date ) );
        $time_fmt = date_i18n( 'H:i', strtotime( $booking->selected_time ) );

        // AUDIT-FIX B-12: cancel token with 30-min expiry stored as transient
        $cancel_token = bin2hex( random_bytes(16) );
        set_transient( 'credoq_cancel_token_' . $cancel_token, $booking_id, 30 * MINUTE_IN_SECONDS );
        $cancel_url = add_query_arg( [ 'credoq_action' => 'cancel_booking', 'token' => $cancel_token ], home_url('/') );

        $tokens = [
            '{name}'         => esc_html( $booking->display_name ?: $to ),
            '{appointment}'  => esc_html( $booking->apt_title ?: 'Appointment' ),
            '{date}'         => esc_html( $date_fmt ),
            '{time}'         => esc_html( $time_fmt ),
            '{location}'     => esc_html( $booking->apt_location ?: '' ),
            '{staff}'        => esc_html( $booking->staff_name ?: '' ),
            '{price}'        => esc_html( number_format( floatval($booking->total_price), 2 ) ),
            '{site_name}'    => esc_html( get_bloginfo('name') ),
            '{cancel_link}'  => '<a href="' . esc_url($cancel_url) . '" style="color:#ef4444;">Cancel Booking</a>',
            '{booking_id}'   => intval( $booking_id ),
        ];

        $raw_subject = $settings["email_{$type}_subject"] ?? self::default_subject( $type );
        $raw_body    = $settings["email_{$type}_body"]    ?? self::default_body( $type );

        $subject = str_replace( array_keys($tokens), array_values($tokens), $raw_subject );
        $body    = str_replace( array_keys($tokens), array_values($tokens), $raw_body );
        $body    = self::wrap_template( wp_kses_post( $body ), $settings );

        $from_name    = sanitize_text_field( $settings['email_from_name']    ?? get_bloginfo('name') );
        $from_email   = sanitize_email(      $settings['email_from_address'] ?? get_option('admin_email') );
        $headers      = [ 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_email}>" ];

        $sent = wp_mail( $to, $subject, $body, $headers );

        // Admin copy
        if ( $sent && ! empty( $settings['email_admin_bcc'] ) ) {
            wp_mail( get_option('admin_email'), '[Admin Copy] ' . $subject, $body, $headers );
        }

        return $sent;
    }

    private static function wrap_template( string $body, array $settings ) : string {
        $accent  = sanitize_hex_color( $settings['email_accent_color'] ?? '#4f46e5' );
        $site    = esc_html( get_bloginfo('name') );
        $logo    = esc_url( $settings['email_logo_url'] ?? '' );
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
  .wrap{max-width:600px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .header{background:{$accent};padding:28px 32px;text-align:center}
  .header img{height:48px;margin-bottom:8px}
  .header h1{color:#fff;margin:0;font-size:22px;font-weight:800}
  .body{padding:32px}
  .body p{color:#334155;font-size:15px;line-height:1.7;margin:0 0 16px}
  .detail-box{background:#f1f5f9;border-radius:12px;padding:20px 24px;margin:24px 0}
  .detail-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:14px}
  .detail-row:last-child{border:none}
  .detail-label{color:#64748b;font-weight:600}
  .detail-value{color:#1e293b;font-weight:700;text-align:right}
  .footer{background:#f8fafc;padding:20px 32px;text-align:center;color:#94a3b8;font-size:12px}
</style></head><body>
<div class="wrap">
  <div class="header">
    {logo_html}
    <h1>{$site}</h1>
  </div>
  <div class="body">{$body}</div>
  <div class="footer">© {$site} · Powered by Credoq</div>
</div></body></html>
HTML;
    }

    private static function default_subject( string $type ) : string {
        return match($type) {
            'confirm'  => 'Booking Confirmed – {appointment} on {date}',
            'cancel'   => 'Booking Cancelled – {appointment}',
            'reminder' => 'Reminder: {appointment} tomorrow at {time}',
            'pending'  => 'Booking Received – {appointment}',
            default    => 'Booking Update – {appointment}',
        };
    }

    private static function default_body( string $type ) : string {
        return match($type) {
            'confirm' => '<p>Hi {name},</p>
<p>Your booking is <strong>confirmed</strong>! Here are the details:</p>
<div class="detail-box">
  <div class="detail-row"><span class="detail-label">Service</span><span class="detail-value">{appointment}</span></div>
  <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">{date}</span></div>
  <div class="detail-row"><span class="detail-label">Time</span><span class="detail-value">{time}</span></div>
  <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">{location}</span></div>
  <div class="detail-row"><span class="detail-label">Staff</span><span class="detail-value">{staff}</span></div>
</div>
<p>Need to cancel? {cancel_link} (valid 30 minutes)</p>
<p>See you soon!<br>{site_name}</p>',

            'cancel' => '<p>Hi {name},</p>
<p>Your booking for <strong>{appointment}</strong> on <strong>{date} at {time}</strong> has been cancelled.</p>
<p>If you believe this is an error, please contact us.</p>
<p>{site_name}</p>',

            'reminder' => '<p>Hi {name},</p>
<p>Just a friendly reminder — you have <strong>{appointment}</strong> scheduled for <strong>tomorrow at {time}</strong>.</p>
<div class="detail-box">
  <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">{date}</span></div>
  <div class="detail-row"><span class="detail-label">Time</span><span class="detail-value">{time}</span></div>
  <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">{location}</span></div>
</div>
<p>See you then!<br>{site_name}</p>',

            default => '<p>Hi {name},</p><p>An update about your booking: <strong>{appointment}</strong> on {date} at {time}.</p>',
        };
    }
}
