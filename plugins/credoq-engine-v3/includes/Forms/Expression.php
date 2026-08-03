<?php
/**
 * Safe arithmetic expression evaluator.
 *
 * AUDIT-FIX A-1: replaces the previous eval() approach.
 *
 * Supports: + - * / ( ) numbers (int/float, leading -, leading +)
 *           and named tokens that are substituted from a tokens array.
 *
 * Implementation: classic Shunting-yard → RPN → evaluate.
 * No PHP eval. No function calls. No variables outside the token map.
 *
 * @package CredoqEngine\Forms
 */

namespace CredoqEngine\Forms;

defined( 'ABSPATH' ) || exit;

class Expression {

	/**
	 * Evaluate `$expr`, substituting `{token}` placeholders from `$tokens`.
	 *
	 * @param string $expr   e.g. "{qty} * {unit_price} + 5"
	 * @param array  $tokens e.g. [ 'qty' => 3, 'unit_price' => 10 ]
	 * @return float|\WP_Error
	 */
	public static function eval_safe( string $expr, array $tokens ) {
		// Substitute {tokens}.
		$expr = preg_replace_callback( '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ( $m ) use ( $tokens ) {
			$key = $m[1];
			return isset( $tokens[ $key ] ) ? (string) (float) $tokens[ $key ] : '0';
		}, $expr );

		// After substitution, only digits, dots, operators, parens, whitespace.
		if ( ! preg_match( '/^[\d.\s+\-*\/()]*$/', $expr ) ) {
			return new \WP_Error( 'invalid_expression', __( 'Expression contains forbidden characters.', 'credoq-engine' ) );
		}
		if ( '' === trim( $expr ) ) return 0.0;

		try {
			$rpn = self::to_rpn( $expr );
			return self::eval_rpn( $rpn );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'eval_error', $e->getMessage() );
		}
	}

	/**
	 * Tokenize + shunting-yard → reverse Polish notation.
	 */
	private static function to_rpn( string $expr ) : array {
		$pos = 0;
		$len = strlen( $expr );
		$out = array();
		$ops = array();
		$prec = array( '+' => 1, '-' => 1, '*' => 2, '/' => 2 );
		$prev_was_op = true; // for unary +/-

		while ( $pos < $len ) {
			$c = $expr[ $pos ];

			if ( ctype_space( $c ) ) { $pos++; continue; }

			// Number (possibly with leading unary sign).
			if ( ctype_digit( $c ) || '.' === $c || ( in_array( $c, array( '-', '+' ), true ) && $prev_was_op ) ) {
				$start = $pos;
				if ( in_array( $c, array( '-', '+' ), true ) ) $pos++;
				while ( $pos < $len && ( ctype_digit( $expr[ $pos ] ) || '.' === $expr[ $pos ] ) ) $pos++;
				$num = substr( $expr, $start, $pos - $start );
				if ( ! is_numeric( $num ) ) {
					throw new \RuntimeException( 'Bad number: ' . $num );
				}
				$out[]       = (float) $num;
				$prev_was_op = false;
				continue;
			}

			// Operator.
			if ( isset( $prec[ $c ] ) ) {
				while ( ! empty( $ops ) ) {
					$top = end( $ops );
					if ( '(' === $top ) break;
					if ( $prec[ $top ] >= $prec[ $c ] ) {
						$out[] = array_pop( $ops );
					} else { break; }
				}
				$ops[]       = $c;
				$prev_was_op = true;
				$pos++;
				continue;
			}

			if ( '(' === $c ) { $ops[] = $c; $prev_was_op = true; $pos++; continue; }
			if ( ')' === $c ) {
				while ( ! empty( $ops ) && '(' !== end( $ops ) ) {
					$out[] = array_pop( $ops );
				}
				if ( empty( $ops ) ) throw new \RuntimeException( 'Mismatched parenthesis' );
				array_pop( $ops ); // pop '('
				$prev_was_op = false;
				$pos++;
				continue;
			}

			throw new \RuntimeException( 'Unexpected character: ' . $c );
		}

		while ( ! empty( $ops ) ) {
			$top = array_pop( $ops );
			if ( '(' === $top ) throw new \RuntimeException( 'Mismatched parenthesis' );
			$out[] = $top;
		}
		return $out;
	}

	private static function eval_rpn( array $rpn ) : float {
		$stack = array();
		foreach ( $rpn as $tok ) {
			if ( is_float( $tok ) || is_int( $tok ) ) {
				$stack[] = (float) $tok;
				continue;
			}
			$b = array_pop( $stack );
			$a = array_pop( $stack );
			if ( null === $a || null === $b ) throw new \RuntimeException( 'Underflow' );
			switch ( $tok ) {
				case '+': $stack[] = $a + $b; break;
				case '-': $stack[] = $a - $b; break;
				case '*': $stack[] = $a * $b; break;
				case '/':
					if ( (float) 0 === $b ) throw new \RuntimeException( 'Division by zero' );
					$stack[] = $a / $b; break;
				default: throw new \RuntimeException( 'Unknown op: ' . $tok );
			}
		}
		if ( 1 !== count( $stack ) ) throw new \RuntimeException( 'Bad expression' );
		return (float) $stack[0];
	}
}
