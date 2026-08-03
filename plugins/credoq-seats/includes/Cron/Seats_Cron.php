<?php
namespace CredoqSeats\Cron;

use CredoqSeats\Repositories\Booking_Repository;

defined( 'ABSPATH' ) || exit;

class Seats_Cron {

	const HOOK = 'credoq_seats_expire_holds';

	public static function schedule() : void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'credoq_seats_5min', self::HOOK );
		}
	}

	public static function clear() : void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function register_hooks() : void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function add_interval( array $schedules ) : array {
		$schedules['credoq_seats_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Credoq Seats)', 'credoq-seats' ),
		);
		return $schedules;
	}

	/**
	 * Also runs lazily inside Booking_Repository::booked_seat_ids() /
	 * hold_seat() so shared hosting without a working WP-Cron still gets
	 * correct availability — this scheduled sweep just keeps the table
	 * tidy (fewer stale rows to scan).
	 */
	public static function run() : void {
		Booking_Repository::expire_holds();
	}
}
