<?php
/**
 * Price history persistence.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Storage;

use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Domain\Money;
use Pnscripts\Omnibus\Domain\PriceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows of the price history table. Every query is prepared.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; caching is handled by ReferenceService.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Same as above.
 */
final class HistoryRepository {

	public const CACHE_GROUP = 'pnscripts_omnibus';

	/**
	 * Hard cap of rows loaded for one product.
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Insert a record.
	 *
	 * @param int         $product_id Product or variation id.
	 * @param int         $parent_id  Parent product id for variations, 0 otherwise.
	 * @param PriceRecord $record     Record.
	 * @param string      $currency   ISO currency code.
	 * @return int Inserted id, 0 on failure.
	 */
	public function insert( int $product_id, int $parent_id, PriceRecord $record, string $currency ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			Schema::table(),
			array(
				'product_id'    => $product_id,
				'parent_id'     => $parent_id,
				'changed_at'    => self::to_datetime( $record->changed_at ),
				'regular_price' => $record->regular,
				'sale_price'    => $record->sale,
				'sale_from'     => self::to_datetime( $record->sale_from ),
				'sale_to'       => self::to_datetime( $record->sale_to ),
				'price'         => $record->price,
				'on_sale'       => null === $record->on_sale ? null : (int) $record->on_sale,
				'unknown_since' => self::to_datetime( $record->unknown_since ),
				'currency'      => substr( $currency, 0, 3 ),
				'source'        => substr( $record->source, 0, 40 ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return 0;
		}
		self::bump_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Latest record of a product.
	 *
	 * @param int $product_id Product id.
	 */
	public function latest( int $product_id ): ?PriceRecord {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE product_id = %d ORDER BY changed_at DESC, id DESC LIMIT 1',
				Schema::table(),
				$product_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Records of a product in time order (bounded by retention). When the product has more than MAX_ROWS rows,
	 * the newest ones are returned: the history then simply starts later, which the calculator treats as
	 * incomplete instead of missing the current price.
	 *
	 * @param int $product_id Product id.
	 * @return list<PriceRecord>
	 */
	public function for_product( int $product_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE product_id = %d ORDER BY changed_at DESC, id DESC LIMIT %d',
				Schema::table(),
				$product_id,
				self::MAX_ROWS
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? array_reverse( $rows ) : array();
		return array_values( array_map( array( self::class, 'hydrate' ), $rows ) );
	}

	/**
	 * Most recent records, newest first (admin display).
	 *
	 * @param int $product_id Product id.
	 * @param int $limit      Rows.
	 * @return list<PriceRecord>
	 */
	public function recent( int $product_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE product_id = %d ORDER BY changed_at DESC, id DESC LIMIT %d',
				Schema::table(),
				$product_id,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return array_values( array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Earliest moment captured by this plugin itself (not imported), null when none.
	 *
	 * @param int $product_id Product id.
	 */
	public function earliest_own( int $product_id ): ?int {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(changed_at) FROM %i WHERE product_id = %d AND source NOT LIKE %s',
				Schema::table(),
				$product_id,
				'import:%'
			)
		);
		return is_string( $value ) && '' !== $value ? self::from_datetime( $value ) : null;
	}

	/**
	 * Start of the latest record at or before a moment (the record in force then), null when none.
	 *
	 * @param int $product_id Product id.
	 * @param int $at         UTC timestamp (inclusive).
	 */
	public function latest_at_or_before( int $product_id, int $at ): ?int {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(changed_at) FROM %i WHERE product_id = %d AND changed_at <= %s',
				Schema::table(),
				$product_id,
				self::to_datetime( $at )
			)
		);
		return is_string( $value ) && '' !== $value ? self::from_datetime( $value ) : null;
	}

	/**
	 * Whether an imported row already exists.
	 *
	 * @param int    $product_id Product id.
	 * @param int    $changed_at UTC timestamp.
	 * @param string $source     Source.
	 */
	public function has_record_at( int $product_id, int $changed_at, string $source ): bool {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE product_id = %d AND changed_at = %s AND source = %s LIMIT 1',
				Schema::table(),
				$product_id,
				self::to_datetime( $changed_at ),
				$source
			)
		);
		return null !== $value;
	}

	/**
	 * Delete all rows of a product.
	 *
	 * @param int $product_id Product id.
	 */
	public function delete_product( int $product_id ): int {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d', Schema::table(), $product_id )
		);
		self::bump_cache();
		return (int) $deleted;
	}

	/**
	 * Delete rows of a product older than a moment.
	 *
	 * @param int $product_id Product id.
	 * @param int $before     UTC timestamp (exclusive).
	 */
	public function delete_before( int $product_id, int $before ): int {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE product_id = %d AND changed_at < %s',
				Schema::table(),
				$product_id,
				self::to_datetime( $before )
			)
		);
		self::bump_cache();
		return (int) $deleted;
	}

	/**
	 * Products with more than one row older than the cutoff (candidates for pruning).
	 *
	 * @param int $cutoff        UTC timestamp.
	 * @param int $after_product Continue after this product id.
	 * @param int $limit         Batch size.
	 * @return list<int>
	 */
	public function prunable_products( int $cutoff, int $after_product, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT product_id FROM %i WHERE changed_at < %s AND product_id > %d GROUP BY product_id HAVING COUNT(*) > 1 ORDER BY product_id ASC LIMIT %d',
				Schema::table(),
				self::to_datetime( $cutoff ),
				$after_product,
				$limit
			)
		);
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Row and product counts.
	 *
	 * @return array{rows: int, products: int}
	 */
	public function stats(): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT COUNT(*) AS row_count, COUNT(DISTINCT product_id) AS product_count FROM %i', Schema::table() ),
			ARRAY_A
		);
		return array(
			'rows'     => (int) ( $row['row_count'] ?? 0 ),
			'products' => (int) ( $row['product_count'] ?? 0 ),
		);
	}

	/**
	 * Approximate size of the history table in bytes (data + indexes), null when the database cannot tell (SQLite).
	 */
	public function table_bytes(): ?int {
		global $wpdb;
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return null;
		}
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
				Schema::table()
			)
		);
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Delete one row the shop owner entered from their records (other rows are never deleted this way).
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $id         Row id.
	 * @return bool Whether a row was deleted.
	 */
	public function delete_manual( int $product_id, int $id ): bool {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id = %d AND product_id = %d AND source = %s',
				Schema::table(),
				$id,
				$product_id,
				ManualEntry::SOURCE
			)
		);
		if ( is_int( $deleted ) && $deleted > 0 ) {
			self::bump_cache();
			return true;
		}
		return false;
	}

	/**
	 * Invalidate cached reference results.
	 */
	public static function bump_cache(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Row to record.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function hydrate( array $row ): PriceRecord {
		return new PriceRecord(
			self::from_datetime( is_string( $row['changed_at'] ?? null ) ? $row['changed_at'] : '' ) ?? 0,
			Money::normalize( $row['regular_price'] ?? null ),
			Money::normalize( $row['sale_price'] ?? null ),
			self::from_datetime( is_string( $row['sale_from'] ?? null ) ? $row['sale_from'] : '' ),
			self::from_datetime( is_string( $row['sale_to'] ?? null ) ? $row['sale_to'] : '' ),
			Money::normalize( $row['price'] ?? null ),
			isset( $row['on_sale'] ) && is_numeric( $row['on_sale'] ) ? (bool) (int) $row['on_sale'] : null,
			self::from_datetime( is_string( $row['unknown_since'] ?? null ) ? $row['unknown_since'] : '' ),
			is_string( $row['source'] ?? null ) ? $row['source'] : '',
			isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0,
			is_string( $row['currency'] ?? null ) ? $row['currency'] : ''
		);
	}

	/**
	 * UTC timestamp to MySQL datetime.
	 *
	 * @param int|null $timestamp Timestamp.
	 */
	public static function to_datetime( ?int $timestamp ): ?string {
		return null === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * MySQL datetime (UTC) to timestamp.
	 *
	 * @param string $value Datetime.
	 */
	public static function from_datetime( string $value ): ?int {
		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? null : $timestamp;
	}
}
