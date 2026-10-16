<?php
/**
 * Prices from the shop owner's own records: add, delete, CSV import.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Records;

use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Stores rows entered by the shop owner as imported history (before the plugin's own first record only).
 */
final class ManualRecords {

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository $repository Storage.
	 * @param Clock             $clock      Clock.
	 */
	public function __construct(
		private readonly HistoryRepository $repository,
		private readonly Clock $clock
	) {
	}

	/**
	 * Add one row.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $date       Day (Y-m-d, shop time zone).
	 * @param string $regular    Regular price.
	 * @param string $sale       Sale price or empty.
	 * @return string Empty on success, else a ManualEntry::ERROR_* code.
	 */
	public function add( int $product_id, string $date, string $regular, string $sale ): string {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return ManualEntry::ERROR_PRODUCT;
		}
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return ManualEntry::ERROR_PRODUCT_TYPE;
		}

		$parsed = ManualEntry::parse( $date, $regular, $sale, wp_timezone(), $this->clock->now(), $this->repository->earliest_own( $product_id ) );
		$record = $parsed['record'];
		if ( null === $record ) {
			return $parsed['error'];
		}
		if ( $this->repository->has_record_at( $product_id, $record->changed_at, ManualEntry::SOURCE ) ) {
			return ManualEntry::ERROR_DUPLICATE;
		}
		$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
		$this->repository->insert( $product_id, $parent, $record, (string) get_option( 'woocommerce_currency', '' ) );
		return '';
	}

	/**
	 * Import a CSV file: product ID or SKU, date, regular price, sale price.
	 *
	 * @param string $content File content.
	 * @return array{added: int, errors: list<array{line: int, product: string, error: string}>}
	 */
	public function import_csv( string $content ): array {
		$added  = 0;
		$errors = array();
		foreach ( ManualEntry::parse_csv( $content ) as $row ) {
			$product_id = $this->resolve( $row['product'] );
			$error      = 0 === $product_id ? ManualEntry::ERROR_PRODUCT : $this->add( $product_id, $row['date'], $row['regular'], $row['sale'] );
			if ( '' === $error ) {
				++$added;
				continue;
			}
			$errors[] = array(
				'line'    => $row['line'],
				'product' => $row['product'],
				'error'   => $error,
			);
		}
		return array(
			'added'  => $added,
			'errors' => $errors,
		);
	}

	/**
	 * Product id from an id or a SKU, 0 when not found.
	 *
	 * @param string $value Id or SKU.
	 */
	public function resolve( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$by_sku = (int) wc_get_product_id_by_sku( $value );
		if ( $by_sku > 0 ) {
			return $by_sku;
		}
		if ( ctype_digit( $value ) ) {
			$type = get_post_type( (int) $value );
			return 'product' === $type || 'product_variation' === $type ? (int) $value : 0;
		}
		return 0;
	}

	/**
	 * Translated message for an error code.
	 *
	 * @param string $code Error code.
	 */
	public static function message( string $code ): string {
		return match ( $code ) {
			ManualEntry::ERROR_DATE           => __( 'The date must be a real day written as YYYY-MM-DD.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_FUTURE         => __( 'The date is in the future.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_AFTER_RECORDED => __( 'The plugin was already recording this product on that day; its own history is kept and cannot be overwritten.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_REGULAR        => __( 'The regular price is missing or not a number (write 1299.90 or 1299,90, without thousands separators).', 'pnscripts-price-history' ),
			ManualEntry::ERROR_SALE           => __( 'The sale price is not a number (write 1299.90 or 1299,90, without thousands separators).', 'pnscripts-price-history' ),
			ManualEntry::ERROR_SALE_NOT_LOWER => __( 'The sale price must be lower than the regular price.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_DUPLICATE      => __( 'A price from your records already exists for that day.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_PRODUCT        => __( 'No product or variation with this ID or SKU.', 'pnscripts-price-history' ),
			ManualEntry::ERROR_PRODUCT_TYPE   => __( 'Variable and grouped products have no own price; enter the price of each variation or child product.', 'pnscripts-price-history' ),
			'file'                            => __( 'No CSV file was received, or it is larger than 2 MB.', 'pnscripts-price-history' ),
			default                           => __( 'The price could not be saved.', 'pnscripts-price-history' ),
		};
	}
}
