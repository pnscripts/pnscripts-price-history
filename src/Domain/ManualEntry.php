<?php
/**
 * Prices the shop owner enters from their own records (pure PHP, no WordPress).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Validates one "price from your records" row and parses CSV files of them.
 *
 * A row says: from this day (shop time zone, 00:00) the product had this regular price and, optionally, this sale
 * price, until the next row. Rows are stored like imported history, so they only fill the time before the plugin's
 * own first record of the product; the plugin's own recording always wins.
 */
final class ManualEntry {

	public const SOURCE = 'import:manual';

	public const ERROR_DATE           = 'invalid_date';
	public const ERROR_FUTURE         = 'future_date';
	public const ERROR_AFTER_RECORDED = 'after_recording_started';
	public const ERROR_REGULAR        = 'invalid_regular_price';
	public const ERROR_SALE           = 'invalid_sale_price';
	public const ERROR_SALE_NOT_LOWER = 'sale_not_lower';
	public const ERROR_DUPLICATE      = 'duplicate';
	public const ERROR_PRODUCT        = 'unknown_product';
	public const ERROR_PRODUCT_TYPE   = 'product_has_no_own_price';

	/**
	 * Most rows accepted from one CSV file.
	 */
	public const MAX_CSV_ROWS = 5000;

	/**
	 * Validate one row.
	 *
	 * @param string        $date         Day in Y-m-d (a leading/trailing space is ignored).
	 * @param string        $regular      Regular price as typed ("19.90", "19,90").
	 * @param string        $sale         Sale price as typed, empty for none.
	 * @param \DateTimeZone $timezone     Shop time zone.
	 * @param int           $now          Current UTC timestamp.
	 * @param int|null      $earliest_own Start of the plugin's own history of the product, null when none.
	 * @return array{record: PriceRecord|null, error: string}
	 */
	public static function parse( string $date, string $regular, string $sale, \DateTimeZone $timezone, int $now, ?int $earliest_own ): array {
		$date = trim( $date );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return self::error( self::ERROR_DATE );
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		if ( false === $day ) {
			return self::error( self::ERROR_DATE );
		}
		$at = $day->getTimestamp();
		if ( $at > $now ) {
			return self::error( self::ERROR_FUTURE );
		}
		if ( null !== $earliest_own && $at >= $earliest_own ) {
			return self::error( self::ERROR_AFTER_RECORDED );
		}

		$regular_price = self::price( $regular );
		if ( null === $regular_price || 0 === Money::compare( $regular_price, '0' ) ) {
			return self::error( self::ERROR_REGULAR );
		}
		$sale_price = null;
		if ( '' !== trim( $sale ) ) {
			$sale_price = self::price( $sale );
			if ( null === $sale_price ) {
				return self::error( self::ERROR_SALE );
			}
			if ( Money::compare( $sale_price, $regular_price ) >= 0 ) {
				return self::error( self::ERROR_SALE_NOT_LOWER );
			}
		}

		return array(
			'record' => new PriceRecord( $at, $regular_price, $sale_price, null, null, $sale_price ?? $regular_price, null, null, self::SOURCE ),
			'error'  => '',
		);
	}

	/**
	 * Parse a CSV file of rows: product (ID or SKU), date, regular price, sale price.
	 *
	 * The first line may be a header (skipped unless it looks like data: a digit in the date column and a price in
	 * the regular-price column). Comma, semicolon and tab separators are detected from the first line; with a comma
	 * separator, decimal commas must be quoted ("19,90").
	 *
	 * @param string $content File content.
	 * @return list<array{line: int, product: string, date: string, regular: string, sale: string}>
	 */
	public static function parse_csv( string $content ): array {
		$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$lines   = preg_split( '/\r\n|\r|\n/', $content );
		$lines   = false === $lines ? array() : $lines;
		$first   = '';
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				$first = $line;
				break;
			}
		}
		$separator = ',';
		$best      = substr_count( $first, ',' );
		foreach ( array( ';', "\t" ) as $candidate ) {
			if ( substr_count( $first, $candidate ) > $best ) {
				$separator = $candidate;
				$best      = substr_count( $first, $candidate );
			}
		}

		$rows  = array();
		$first = true;
		foreach ( $lines as $index => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( static fn ( ?string $cell ): string => trim( (string) $cell ), str_getcsv( $line, $separator, '"', '' ) );
			$cells = array_pad( $cells, 4, '' );
			if ( $first ) {
				$first = false;
				if ( ! self::looks_like_data( $cells ) ) {
					continue; // Header line.
				}
			}
			$rows[] = array(
				'line'    => $index + 1,
				'product' => (string) $cells[0],
				'date'    => (string) $cells[1],
				'regular' => (string) $cells[2],
				'sale'    => (string) $cells[3],
			);
			if ( count( $rows ) >= self::MAX_CSV_ROWS ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Normalise a typed price ("1 299,90", "1299.90") to the decimal string used everywhere else.
	 *
	 * @param string $value Typed value.
	 */
	public static function price( string $value ): ?string {
		$value = str_replace( array( ' ', "\u{00A0}", "\u{202F}" ), '', trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( 1 === preg_match( '/^[1-9]\d{0,2}[.,]\d{3}$/', $value ) ) {
			return null; // "1,299" or "1.299": thousands or decimals? Refuse rather than guess.
		}
		if ( str_contains( $value, ',' ) && str_contains( $value, '.' ) ) {
			// The last separator is the decimal one: "1.299,90" or "1,299.90".
			$value = strrpos( $value, ',' ) > strrpos( $value, '.' )
				? str_replace( array( '.', ',' ), array( '', '.' ), $value )
				: str_replace( ',', '', $value );
		} elseif ( str_contains( $value, ',' ) ) {
			$value = str_replace( ',', '.', $value );
		}
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return null;
		}
		return Money::normalize( $value );
	}

	/**
	 * Whether cells look like a data row (a typo in the date must still be reported, not taken for a header).
	 *
	 * @param array<int, string|null> $cells Cells.
	 */
	private static function looks_like_data( array $cells ): bool {
		return null !== self::price( (string) $cells[2] ) && 1 === preg_match( '/\d/', (string) $cells[1] );
	}

	/**
	 * Error result.
	 *
	 * @param string $code Error code.
	 * @return array{record: null, error: string}
	 */
	private static function error( string $code ): array {
		return array(
			'record' => null,
			'error'  => $code,
		);
	}
}
