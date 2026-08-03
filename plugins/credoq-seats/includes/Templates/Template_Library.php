<?php
/**
 * Template_Library — pre-built seat map layouts.
 *
 * Per the plan, templates are versionable code, not DB rows: a new plan
 * "created from a template" just gets that template's layout_json copied
 * into it once, then it's a normal editable plan from then on.
 *
 * @package CredoqSeats\Templates
 */

namespace CredoqSeats\Templates;

defined( 'ABSPATH' ) || exit;

class Template_Library {

	/** @return array<string,array{label:string,description:string}> */
	public static function catalog() : array {
		return array(
			'hall_theater'    => array( 'label' => 'Hall / Theater / Concert', 'description' => 'Curved rows A–T, stage label, VIP front 3 rows.' ),
			'cinema'          => array( 'label' => 'Cinema / Movie Theater',   'description' => 'Rows A–K, screen bar, aisle gaps, premium center block.' ),
			'classroom'       => array( 'label' => 'Workshop / Classroom',     'description' => '5 rows x 6 seats, whiteboard label.' ),
			'bus'             => array( 'label' => 'Bus (coach / shuttle)',    'description' => '2x2 columns, driver area, center aisle.' ),
			'train_carriage'  => array( 'label' => 'Train Carriage',          'description' => '2+1 layout with table zones.' ),
			'plane_economy'   => array( 'label' => 'Airplane Economy Cabin',  'description' => 'A-F columns, exit row, overhead label.' ),
			'plane_business'  => array( 'label' => 'Airplane Business Class', 'description' => '2+2 layout, wider seats.' ),
			'restaurant'      => array( 'label' => 'Restaurant / Dining Room','description' => 'Round tables of 2/4/6, bar stools.' ),
			'stadium_section' => array( 'label' => 'Stadium Section',         'description' => 'Fan-arc rows, block/row/seat labels.' ),
			'conference_room' => array( 'label' => 'Boardroom / Conference',  'description' => 'U-shape long table.' ),
			'custom'          => array( 'label' => 'Blank Canvas',            'description' => 'Empty — build from scratch.' ),
		);
	}

	public static function get( string $key ) : array {
		$method = 'build_' . $key;
		if ( method_exists( __CLASS__, $method ) ) return self::$method();
		return self::build_custom();
	}

	/* ── Shared helpers ───────────────────────────────────────────────── */

	private static function seat( string $label, int $row, int $col, float $x, float $y, string $type = 'standard', $price = null ) : array {
		return array(
			'label'  => $label,
			'type'   => $type,
			'row'    => $row,
			'col'    => $col,
			'x'      => $x,
			'y'      => $y,
			'price'  => $price,
			'status' => 'available',
		);
	}

	private static function col_letter( int $i ) : string {
		return chr( 65 + ( $i % 26 ) );
	}

	/** Simple rectangular grid of seats with optional aisle gap columns. */
	private static function grid_seats( int $rows, int $cols, array $aisle_after = array(), string $vip_rows_below = null, int $vip_row_count = 0 ) : array {
		$seats = array();
		$spacing = 34;
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = self::col_letter( $r );
			$x = 0;
			$col_counter = 1;
			for ( $c = 0; $c < $cols; $c++ ) {
				$type = ( $vip_row_count > 0 && $r < $vip_row_count ) ? 'vip' : 'standard';
				$seats[] = self::seat( $row_label . $col_counter, $r, $c, $x, $r * $spacing, $type );
				$col_counter++;
				$x += $spacing;
				if ( in_array( $c, $aisle_after, true ) ) $x += $spacing * 0.6; // aisle gap
			}
		}
		return $seats;
	}

	/* ── Templates ────────────────────────────────────────────────────── */

	private static function build_custom() : array {
		return array( 'floors' => array( array( 'name' => 'Floor 1', 'color' => '#4f46e5', 'seats' => array() ) ) );
	}

	private static function build_classroom() : array {
		return array( 'floors' => array( array(
			'name'  => 'Classroom',
			'color' => '#4f46e5',
			'seats' => self::grid_seats( 5, 6 ),
		) ) );
	}

	private static function build_hall_theater() : array {
		$rows    = 20; // A–T
		$cols    = 16;
		$spacing = 32;
		$seats   = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = self::col_letter( $r );
			// Curve: rows bow outward slightly toward the back, narrower up front.
			$row_cols = min( $cols, 10 + $r ); // fewer seats near the stage
			$offset_x = ( $cols - $row_cols ) * $spacing / 2;
			$curve    = sin( ( $r / $rows ) * M_PI ) * 18;
			for ( $c = 0; $c < $row_cols; $c++ ) {
				$type = $r < 3 ? 'vip' : 'standard';
				$seats[] = self::seat( $row_label . ( $c + 1 ), $r, $c, $offset_x + $c * $spacing + $curve, $r * $spacing, $type );
			}
		}
		return array( 'floors' => array( array( 'name' => 'Orchestra', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_cinema() : array {
		$rows    = 11; // A–K
		$cols    = 14;
		$spacing = 32;
		$seats   = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = self::col_letter( $r );
			$x = 0;
			for ( $c = 0; $c < $cols; $c++ ) {
				$is_premium = $r >= 4 && $r <= 7 && $c >= 4 && $c <= 9;
				$type = $is_premium ? 'vip' : 'standard';
				$seats[] = self::seat( $row_label . ( $c + 1 ), $r, $c, $x, $r * $spacing, $type );
				$x += $spacing;
				if ( 6 === $c ) $x += $spacing * 0.6; // center aisle
			}
		}
		return array( 'floors' => array( array( 'name' => 'Cinema', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_bus() : array {
		$rows    = 12;
		$spacing = 34;
		$seats   = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = (string) ( $r + 1 );
			// 2 seats, aisle, 2 seats
			$positions = array( 0, 1, 3, 4 ); // column 2 is the aisle
			$letters   = array( 'A', 'B', 'C', 'D' );
			foreach ( $positions as $i => $c ) {
				$seats[] = self::seat( $row_label . $letters[ $i ], $r, $c, $c * $spacing, $r * $spacing, 'standard' );
			}
		}
		return array( 'floors' => array( array( 'name' => 'Bus', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_train_carriage() : array {
		$rows    = 14;
		$spacing = 34;
		$seats   = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = (string) ( $r + 1 );
			// 2 + 1 with aisle: columns 0,1 | aisle | column 3
			$seats[] = self::seat( $row_label . 'A', $r, 0, 0, $r * $spacing, 'standard' );
			$seats[] = self::seat( $row_label . 'B', $r, 1, $spacing, $r * $spacing, 'standard' );
			$seats[] = self::seat( $row_label . 'C', $r, 3, $spacing * 2.6, $r * $spacing, 'standard' );
		}
		return array( 'floors' => array( array( 'name' => 'Carriage', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_plane_economy() : array {
		$rows    = 30;
		$spacing = 30;
		$seats   = array();
		$letters = array( 'A', 'B', 'C', 'D', 'E', 'F' );
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = (string) ( $r + 1 );
			$is_exit   = in_array( $r + 1, array( 12, 13 ), true );
			foreach ( $letters as $i => $L ) {
				$x = $i * $spacing + ( $i >= 3 ? $spacing * 0.6 : 0 ); // aisle after C
				$seats[] = self::seat( $row_label . $L, $r, $i, $x, $r * $spacing, $is_exit ? 'accessible' : 'standard' );
			}
		}
		return array( 'floors' => array( array( 'name' => 'Economy', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_plane_business() : array {
		$rows    = 8;
		$spacing = 40;
		$seats   = array();
		$letters = array( 'A', 'B', 'D', 'E' ); // 2+2
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = (string) ( $r + 1 );
			foreach ( $letters as $i => $L ) {
				$x = $i * $spacing + ( $i >= 2 ? $spacing * 0.8 : 0 );
				$seats[] = self::seat( $row_label . $L, $r, $i, $x, $r * $spacing, 'vip' );
			}
		}
		return array( 'floors' => array( array( 'name' => 'Business', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_restaurant() : array {
		$spacing = 60;
		$seats   = array();
		$table_i = 0;
		$sizes   = array( 4, 4, 2, 6, 4, 2, 4, 4, 6, 2 );
		$per_row = 5;
		foreach ( $sizes as $t => $size ) {
			$row = intdiv( $t, $per_row );
			$col = $t % $per_row;
			$cx  = $col * $spacing;
			$cy  = $row * $spacing;
			for ( $s = 0; $s < $size; $s++ ) {
				$angle = ( 2 * M_PI / $size ) * $s;
				$sx = $cx + cos( $angle ) * 14;
				$sy = $cy + sin( $angle ) * 14;
				$seats[] = self::seat( 'T' . ( $t + 1 ) . '-' . ( $s + 1 ), $t, $s, $sx, $sy, 'standard' );
			}
			$table_i++;
		}
		return array( 'floors' => array( array( 'name' => 'Dining Room', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_stadium_section() : array {
		$rows    = 15;
		$cols    = 20;
		$spacing = 26;
		$seats   = array();
		for ( $r = 0; $r < $rows; $r++ ) {
			$row_label = self::col_letter( $r );
			$curve = sin( ( $r / $rows ) * M_PI ) * 40;
			for ( $c = 0; $c < $cols; $c++ ) {
				$type = $r < 2 ? 'vip' : 'standard';
				$seats[] = self::seat( 'Block1-' . $row_label . ( $c + 1 ), $r, $c, $c * $spacing, $r * $spacing + $curve * -1, $type );
			}
		}
		return array( 'floors' => array( array( 'name' => 'Section 101', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}

	private static function build_conference_room() : array {
		$spacing = 34;
		$seats   = array();
		$seat_n  = 1;
		// Long table: two facing rows of 6.
		for ( $side = 0; $side < 2; $side++ ) {
			for ( $c = 0; $c < 6; $c++ ) {
				$seats[] = self::seat( 'S' . $seat_n, $side, $c, $c * $spacing, $side * $spacing * 3, 'standard' );
				$seat_n++;
			}
		}
		// Head of table.
		$seats[] = self::seat( 'S' . $seat_n, 2, 0, -$spacing, $spacing * 1.5, 'vip' );
		return array( 'floors' => array( array( 'name' => 'Boardroom', 'color' => '#4f46e5', 'seats' => $seats ) ) );
	}
}
