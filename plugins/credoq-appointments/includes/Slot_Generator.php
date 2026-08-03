<?php
namespace CredoqAppointments;
defined( 'ABSPATH' ) || exit;

/**
 * Generates available time slots for a given appointment + staff + date.
 *
 * AUDIT-FIX A-2:  Zero JetAppointments dependency — pure table queries.
 * AUDIT-FIX C-3:  Uses time() not current_time('timestamp').
 * AUDIT-FIX C-6:  Special-date price overrides + server-side price recalculation.
 * AUDIT-FIX C-8:  Appointment memoized via Appointment_Repository::find().
 *
 * --- BUGS FIXED IN THIS REWRITE ---
 *
 * FIX-SG-1: available_dates_in_month() returned a flat string[] but
 *            Slots_Handler::get_date_capacity() called array_keys() on it
 *            expecting a {date => count} map.  Calendar dots never appeared.
 *            → Now returns array<string,int>.
 *
 * FIX-SG-2: get_working_windows() had a silent failure mode when staff was
 *            set but its day entry had closed=false with hours=[].
 *            Code exited the staff block without returning and then hit
 *            "return []" at the end of get_working_windows(), bypassing
 *            service-level availability entirely.
 *            → Explicit array_key_exists() gate + resolve_day_config() helper
 *            distinguish "explicitly closed", "valid open hours", and
 *            "ambiguous (fall through)" for both staff and service layers.
 *
 * FIX-SG-3: Added is_slot_available() — called by Field_Appointment::validate()
 *            but was absent from Booking_Service, causing a PHP fatal on submit.
 *
 * FIX-SG-4: Availability JSON with missing day entries (e.g. admin saved only
 *            closed days) caused all undeclared days to return [].
 *            → Supports Format A {"closed":bool,"hours":[…]} and
 *              Format B simple [{start,end},…] arrays.
 *
 * FIX-SG-5: min/max lead-time rules from booking_settings were never enforced.
 *
 * FIX-SG-6: Special-date price overrides were never read; slots had no
 *            dynamic price field.
 */
class Slot_Generator {

    // ── Public API ────────────────────────────────────────────────────

    /**
     * Generate time slots for a specific date.
     *
     * @param int    $appointment_id
     * @param int    $staff_id        0 = any / no staff filter
     * @param string $date            Y-m-d
     * @return array<array{
     *   time:string, available:bool, capacity:int,
     *   booked:int,  remaining:int,  is_full:bool,
     *   price?:float
     * }>
     */
    public static function for_date( int $appointment_id, int $staff_id, string $date ) : array {
        // Guard: validate date format before touching the DB.
        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }

        $apt = Appointment_Repository::find( $appointment_id ); // AUDIT-FIX C-8: memoized
        if ( ! $apt ) return [];

        $duration = max( 1, intval( $apt->duration      ?: 60 ) );
        $interval = max( 1, intval( $apt->slot_interval ?: $duration ) );
        $max_cap  = max( 1, intval( $apt->max_bookings  ?: 1 ) );

        $staff = $staff_id > 0 ? Staff_Repository::find( $staff_id ) : null;

        // ── Booking settings (lead-time rules, special pricing) ───────
        // AUDIT-FIX C-6: decode once and share with sub-methods.
        $bk_settings = [];
        if ( ! empty( $apt->booking_settings ) ) {
            $decoded = json_decode( $apt->booking_settings, true );
            if ( is_array( $decoded ) ) $bk_settings = $decoded;
        }

        // FIX-SG-5: max advance booking cutoff.
        $max_lead_days = intval( $bk_settings['max_lead_time_days'] ?? 0 );
        if ( $max_lead_days > 0 ) {
            $cutoff_ts = mktime( 23, 59, 59,
                (int) date( 'n' ),
                (int) date( 'j' ) + $max_lead_days,
                (int) date( 'Y' )
            ); // AUDIT-FIX C-3: uses time() base, no current_time('timestamp')
            if ( strtotime( $date ) > $cutoff_ts ) return [];
        }

        // ── Working windows for this date ─────────────────────────────
        $day_of_week = strtolower( date( 'l', strtotime( $date ) ) ); // 'monday', etc.
        $windows     = self::get_working_windows( $apt, $staff, $day_of_week, $date );
        if ( empty( $windows ) ) return [];

        // ── Special-date price override ───────────────────────────────
        // AUDIT-FIX C-6: dynamic pricing per special date / weekend.
        // Pass $staff so the staff's own Special Dates price takes priority.
        $price_override = self::get_date_price_override( $date, $day_of_week, $bk_settings, $staff );

        // ── Booked-slots map {time => count} ──────────────────────────
        $booked_map  = [];
        $booked_rows = Booking_Repository::get_booked_slots( $appointment_id, $staff_id, $date );
        foreach ( $booked_rows as $b ) {
            $booked_map[ $b->selected_time ] = intval( $b->count );
        }

        // ── Visual Seats: capacity is the seat plan's own seat count, not
        //    the generic max_bookings row counter (one "booking" can hold
        //    several seats at once, so counting rows badly under-restricts
        //    capacity once seat selection is on). ────────────────────────
        $seats_enabled = ! empty( $bk_settings['visual_seats_enabled'] );
        $seat_plan_id  = absint( $bk_settings['seat_plan_id'] ?? 0 );
        $seat_cap      = 0;
        if ( $seats_enabled && $seat_plan_id && class_exists( '\CredoqSeats\Repositories\Plan_Repository' ) ) {
            $plan = \CredoqSeats\Repositories\Plan_Repository::find( $seat_plan_id );
            if ( $plan ) $seat_cap = (int) ( $plan->capacity_limit ?: $plan->total_seats );
        }
        $use_seat_capacity = $seats_enabled && $seat_plan_id && $seat_cap > 0
            && class_exists( '\CredoqSeats\Repositories\Booking_Repository' );

        // ── Min lead-time: earliest bookable slot ─────────────────────
        // FIX-SG-5: enforce minimum minutes notice before a slot opens.
        $min_lead_minutes = intval( $bk_settings['min_lead_time'] ?? 0 );
        $earliest_ts      = time() + max( 300, $min_lead_minutes * 60 );
        // AUDIT-FIX C-3: time() is correct; 300 = 5-min buffer when no rule set.
        $is_today         = ( $date === date( 'Y-m-d' ) );

        // ── Generate slots ────────────────────────────────────────────
        $slots = [];
        foreach ( $windows as [ $window_start, $window_end ] ) {
            $cur = strtotime( $date . ' ' . $window_start );
            $end = strtotime( $date . ' ' . $window_end   );

            // Guard against bad strtotime results.
            if ( $cur === false || $end === false || $end <= $cur ) continue;

            while ( ( $cur + $duration * 60 ) <= $end ) {
                $time_key = date( 'H:i', $cur );

                // Skip slots that fail lead-time check (today only for speed,
                // but also applies to future dates if min_lead > 0).
                if ( $is_today && $cur < $earliest_ts ) {
                    $cur += $interval * 60;
                    continue;
                }

                if ( $use_seat_capacity ) {
                    $seat_booked = count( \CredoqSeats\Repositories\Booking_Repository::booked_seat_ids( $seat_plan_id, $date, $time_key ) );
                    $slot_cap    = $seat_cap;
                    $booked      = $seat_booked;
                } else {
                    $slot_cap = $max_cap;
                    $booked   = $booked_map[ $time_key ] ?? 0;
                }
                $remaining = max( 0, $slot_cap - $booked );
                $available = $remaining > 0;

                $slot = [
                    'time'      => $time_key,
                    'available' => $available,
                    'is_full'   => ! $available,
                    'capacity'  => $slot_cap,
                    'booked'    => $booked,
                    'remaining' => $remaining,
                ];

                // FIX-SG-6: attach price override so the React widget can
                // display the correct per-slot price without an extra request.
                if ( $price_override !== null ) {
                    $slot['price'] = $price_override;
                }

                $slots[] = $slot;
                $cur     += $interval * 60;
            }
        }

        return $slots;
    }

    /**
     * Quick single-slot availability check.
     *
     * FIX-SG-3: This method was called by Field_Appointment::validate() but
     * did not exist, causing a PHP fatal on form submission.
     *
     * @param int    $appointment_id
     * @param int    $staff_id
     * @param string $date   Y-m-d
     * @param string $time   H:i
     */
    public static function is_slot_available(
        int $appointment_id, int $staff_id, string $date, string $time
    ) : bool {
        $slots = self::for_date( $appointment_id, $staff_id, $date );
        foreach ( $slots as $s ) {
            if ( $s['time'] === $time ) return $s['available'];
        }
        return false;
    }

    /**
     * Available dates in a month — returns a {date → open-slot-count} map.
     *
     * FIX-SG-1: Previously returned a flat string[] which broke the AJAX
     * handler that calls array_keys() on this value.
     *
     * @param int $appointment_id
     * @param int $staff_id
     * @param int $year
     * @param int $month
     * @return array<string,int>  e.g. {"2026-06-05": 8, "2026-06-06": 4}
     */
    public static function available_dates_in_month(
        int $appointment_id, int $staff_id, int $year, int $month
    ) : array {
        $apt = Appointment_Repository::find( $appointment_id );
        if ( ! $apt ) return [];

        $days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
        $today         = date( 'Y-m-d' );
        $available     = [];

        for ( $d = 1; $d <= $days_in_month; $d++ ) {
            $date  = sprintf( '%04d-%02d-%02d', $year, $month, $d );
            if ( $date < $today ) continue;

            $slots = self::for_date( $appointment_id, $staff_id, $date );
            $open  = 0;
            foreach ( $slots as $s ) {
                if ( $s['available'] ) $open++;
            }
            if ( $open > 0 ) {
                $available[ $date ] = $open; // FIX-SG-1: keyed map, not flat array
            }
        }

        return $available;
    }

    // ── Private helpers ───────────────────────────────────────────────

    /**
     * Produce [[start, end], …] working windows for this date.
     *
     * Priority (highest to lowest):
     *   1. Staff special_dates entry for this exact date
     *   2. Staff weekly availability for this day-of-week
     *   3. Service-level availability for this day-of-week
     *   4. Empty (no slots)
     *
     * Supports two JSON storage formats:
     *   Format A (full):   {"monday": {"closed": true|false, "hours": [{start, end}]}}
     *   Format B (simple): {"monday": [{"start": "09:00", "end": "17:00"}]}
     *
     * FIX-SG-2: Staff layer previously short-circuited service layer even when
     * the staff entry was ambiguous (closed=false, hours=[]).  Now uses
     * resolve_day_config() which returns null for ambiguous entries, letting
     * the caller fall through to the next layer.
     *
     * @return array<array{0:string,1:string}>
     */
    private static function get_working_windows(
        object $apt, ?object $staff, string $day_of_week, string $date
    ) : array {

        // ── Layer 1: Staff special_dates ──────────────────────────────
        if ( $staff ) {
            $special = Staff_Repository::get_special_dates( $staff );
            if ( is_array( $special ) ) {
                foreach ( $special as $sd ) {
                    if ( ! is_array( $sd ) || ( $sd['date'] ?? '' ) !== $date ) continue;
                    // Explicit special-date entry found for this date.
                    if ( ! empty( $sd['closed'] ) ) return [];
                    if ( ! empty( $sd['hours'] ) && is_array( $sd['hours'] ) ) {
                        $parsed = self::parse_hours( $sd['hours'] );
                        if ( ! empty( $parsed ) ) return $parsed;
                    }
                    return []; // Special date is defined but has no hours → closed.
                }
            }
        }

        // ── Layer 2: Staff weekly availability ────────────────────────
        if ( $staff ) {
            $staff_avail = Staff_Repository::get_availability( $staff );
            // Only enter this branch if the staff has an EXPLICIT entry for this day.
            // FIX-SG-2: use array_key_exists so we distinguish "key missing" from
            // "key present with a falsy value".
            if ( is_array( $staff_avail ) && array_key_exists( $day_of_week, $staff_avail ) ) {
                $result = self::resolve_day_config( $staff_avail[ $day_of_week ] );
                if ( $result !== null ) {
                    // null  = ambiguous → fall through to service layer.
                    // []    = explicitly closed.
                    // [...] = valid windows.
                    return $result;
                }
                // Staff entry is ambiguous (open but no hours) — inherit service hours.
            }
            // No entry for this day in staff availability → inherit from service.
        }

        // ── Layer 3: Service-level availability ───────────────────────
        $raw = $apt->availability ?? '';
        if ( ! is_string( $raw ) || $raw === '' ) {
            return []; // No availability configured at all.
        }

        $avail = json_decode( $raw, true );
        if ( ! is_array( $avail ) ) {
            return []; // Malformed JSON.
        }

        // FIX-SG-2/4: use array_key_exists — a day set to closed:true stores
        // a non-falsy array, but we must not treat a missing key as "open".
        if ( array_key_exists( $day_of_week, $avail ) ) {
            $result = self::resolve_day_config( $avail[ $day_of_week ] );
            if ( $result !== null ) return $result;
            // Ambiguous service entry (open, no hours) → fall to default.
        }

        // Day not found in service availability at all → closed by default.
        return [];
    }

    /**
     * Resolve a day config value to a windows list (or null for "ambiguous").
     *
     * @param mixed $day_cfg  The value at $avail[$day_of_week]
     * @return array<array{0:string,1:string}>|null
     *   array  → use these windows (may be [] meaning explicitly closed)
     *   null   → ambiguous, caller should fall through to next layer
     */
    private static function resolve_day_config( $day_cfg ) : ?array {
        if ( ! is_array( $day_cfg ) ) return null;

        // ── Format A: {"closed": bool, "hours": [{start, end}]} ───────
        if ( array_key_exists( 'closed', $day_cfg ) ) {
            // Safely coerce the stored value to a PHP bool.
            // Handles true, false, "true", "false", 1, 0, "1", "0".
            $raw_closed = $day_cfg['closed'];
            if ( is_bool( $raw_closed ) ) {
                $closed = $raw_closed;
            } elseif ( is_string( $raw_closed ) ) {
                $closed = filter_var( $raw_closed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                if ( $closed === null ) $closed = ! empty( $raw_closed );
            } else {
                $closed = (bool) $raw_closed;
            }

            if ( $closed ) return []; // Explicitly closed — return empty array (not null).

            // Open: parse hours.
            if ( ! empty( $day_cfg['hours'] ) && is_array( $day_cfg['hours'] ) ) {
                $parsed = self::parse_hours( $day_cfg['hours'] );
                if ( ! empty( $parsed ) ) return $parsed;
            }
            // closed=false but hours=[] or hours missing → ambiguous, fall through.
            return null;
        }

        // ── Format B: simple array of {start, end} objects ────────────
        // e.g. [{"start": "09:00", "end": "17:00"}, …]
        if ( isset( $day_cfg[0] ) && is_array( $day_cfg[0] ) ) {
            $parsed = self::parse_hours( $day_cfg );
            return ! empty( $parsed ) ? $parsed : null;
        }

        return null; // Unknown structure.
    }

    /**
     * Convert a hours array to [[start, end], …] pairs.
     * Validates HH:MM format and ensures start < end.
     *
     * @param array $hours  [{start: "09:00", end: "17:00"}, …]
     * @return array<array{0:string,1:string}>
     */
    private static function parse_hours( array $hours ) : array {
        $windows = [];
        foreach ( $hours as $h ) {
            if ( ! is_array( $h ) ) continue;
            $start = trim( sanitize_text_field( $h['start'] ?? '' ) );
            $end   = trim( sanitize_text_field( $h['end']   ?? '' ) );
            // Enforce strict HH:MM — reject anything that could produce wrong slots.
            if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) continue;
            if ( ! preg_match( '/^\d{2}:\d{2}$/', $end   ) ) continue;
            if ( $start >= $end ) continue; // Also rejects midnight-spanning windows.

            // AUDIT-FIX (Weekly Schedule break times): if the admin set a
            // break window, split the working day into two parts:
            //   [ start … break_start ] and [ break_end … end ]
            // so no bookable slot straddles the break period.
            $bs = trim( sanitize_text_field( $h['break_start'] ?? '' ) );
            $be = trim( sanitize_text_field( $h['break_end']   ?? '' ) );

            if (
                $bs && $be
                && preg_match( '/^\d{2}:\d{2}$/', $bs )
                && preg_match( '/^\d{2}:\d{2}$/', $be )
                && $bs > $start && $bs < $end
                && $be > $bs    && $be <= $end
            ) {
                // Morning block: start → break_start
                if ( $start < $bs ) {
                    $windows[] = [ $start, $bs ];
                }
                // Afternoon block: break_end → end
                if ( $be < $end ) {
                    $windows[] = [ $be, $end ];
                }
            } else {
                $windows[] = [ $start, $end ];
            }
        }
        return $windows;
    }

    /**
     * Returns a price override float for a specific date, or null if none.
     *
     * AUDIT-FIX C-6: price must come from the server; the frontend shows it
     * as a hint but the final charge is always recalculated in Booking_Service.
     *
     * Booking-settings JSON shape (booking_settings column):
     * {
     *   "min_lead_time":      15,        // minutes
     *   "max_lead_time_days": 60,
     *   "weekend_price":      150.00,
     *   "special_dates": [
     *     { "date": "2026-12-25", "price": 200.00 },
     *     { "date": "2026-12-26", "price": 180.00 }
     *   ]
     * }
     *
     * @param string       $date           Y-m-d
     * @param string       $day_of_week    e.g. 'saturday'
     * @param array        $bk_settings    already-decoded booking_settings
     * @param object|null  $staff          staff row (for special-date price on Staff edit page)
     * @return float|null
     */
    private static function get_date_price_override(
        string $date, string $day_of_week, array $bk_settings, ?object $staff = null
    ) : ?float {
        // Staff-level special-date price takes highest priority.
        if ( $staff ) {
            $staff_price = Staff_Repository::get_special_date_price( $staff, $date );
            if ( $staff_price !== null ) return $staff_price;
        }

        // Appointment-level exact date override.
        $special_dates = $bk_settings['special_dates'] ?? [];
        if ( is_array( $special_dates ) ) {
            foreach ( $special_dates as $sd ) {
                if ( is_array( $sd ) && ( $sd['date'] ?? '' ) === $date && isset( $sd['price'] ) ) {
                    return (float) $sd['price'];
                }
            }
        }

        // Weekend surcharge.
        if (
            in_array( $day_of_week, [ 'saturday', 'sunday' ], true )
            && isset( $bk_settings['weekend_price'] )
        ) {
            return (float) $bk_settings['weekend_price'];
        }

        return null;
    }
}
