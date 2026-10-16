<?php
/**
 * WP-CLI commands.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Cli;

use Pnscripts\Omnibus\Admin\Labels;
use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Import\Importers;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Records\ManualRecords;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and maintain the price history.
 *
 * ## EXAMPLES
 *
 *     wp pnscripts-price-history reference 123
 *     wp pnscripts-price-history history 123
 *     wp pnscripts-price-history backfill --mode=repair
 *     wp pnscripts-price-history import omnibus
 *     wp pnscripts-price-history prune
 */
final class Command {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Services.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Show the lowest prior price of a product or variation.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation id.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function reference( array $args, array $assoc_args ): void {
		$product = $this->product( $args );
		$result  = $this->plugin->reference->compute( $product, $this->plugin->clock->now() );
		$data    = $result->to_array();

		$data['explanation']   = Labels::explain( $result );
		$data['anchor']        = null !== $result->anchor ? gmdate( 'c', $result->anchor ) : null;
		$data['window_start']  = null !== $result->window_start ? gmdate( 'c', $result->window_start ) : null;
		$data['display_price'] = $result->is_known() ? $this->plugin->reference->display_price( $product, $result ) : null;

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Show the stored price history of a product or variation (newest first).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation id.
	 *
	 * [--limit=<limit>]
	 * : Rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param string[]              $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function history( array $args, array $assoc_args ): void {
		$product = $this->product( $args );
		$rows    = array();
		foreach ( $this->plugin->repository->recent( $product->get_id(), (int) ( $assoc_args['limit'] ?? 50 ) ) as $record ) {
			$rows[] = array(
				'changed_at'    => gmdate( 'Y-m-d H:i:s', $record->changed_at ) . ' UTC',
				'regular'       => (string) $record->regular,
				'sale'          => (string) $record->sale,
				'sale_from'     => null !== $record->sale_from ? gmdate( 'Y-m-d H:i', $record->sale_from ) : '',
				'sale_to'       => null !== $record->sale_to ? gmdate( 'Y-m-d H:i', $record->sale_to ) : '',
				'price'         => (string) $record->price,
				'unknown_since' => null !== $record->unknown_since ? gmdate( 'Y-m-d H:i', $record->unknown_since ) : '',
				'source'        => $record->source,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'changed_at', 'regular', 'sale', 'sale_from', 'sale_to', 'price', 'unknown_since', 'source' ) );
	}

	/**
	 * Check every product now (synchronously): record missing baselines or repair missed changes.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : baseline or repair.
	 * ---
	 * default: repair
	 * ---
	 *
	 * @param string[]              $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		unset( $args );
		$mode     = 'baseline' === ( $assoc_args['mode'] ?? 'repair' ) ? Backfill::MODE_BASELINE : Backfill::MODE_REPAIR;
		$after    = 0;
		$total    = 0;
		$recorded = 0;
		do {
			$result    = $this->plugin->backfill->process( $after, $mode, Backfill::BATCH );
			$total    += $result['processed'];
			$recorded += $result['recorded'];
			$after     = (int) $result['next'];
		} while ( null !== $result['next'] );
		WP_CLI::success( sprintf( 'Checked %d products and variations, added %d records.', $total, $recorded ) );
	}

	/**
	 * Import history from another plugin (synchronously).
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : omnibus, wc-price-history or omnibus-by-ilabs.
	 *
	 * @param string[] $args Arguments.
	 */
	public function import( array $args ): void {
		$group = $args[0] ?? '';
		if ( ! isset( Importers::GROUPS[ $group ] ) ) {
			WP_CLI::error( 'Unknown source. Use one of: ' . implode( ', ', array_keys( Importers::GROUPS ) ) );
		}
		$reader   = 0;
		$after    = 0;
		$imported = 0;
		$skipped  = 0;
		do {
			$step      = $this->plugin->import_runner->step( $group, $reader, $after, 200 );
			$imported += $step['imported'];
			$skipped  += $step['skipped'];
			if ( null !== $step['next'] ) {
				list( $reader, $after ) = $step['next'];
			}
		} while ( null !== $step['next'] );
		HistoryRepository::bump_cache();
		WP_CLI::success( sprintf( 'Imported %d records, skipped %d entries.', $imported, $skipped ) );
	}

	/**
	 * Add a price from your own records (for the time before the plugin recorded the product).
	 *
	 * ## OPTIONS
	 *
	 * <product>
	 * : Product or variation ID, or SKU.
	 *
	 * <date>
	 * : Day the price applied from (YYYY-MM-DD, shop time zone), until the next row.
	 *
	 * <regular>
	 * : Regular price.
	 *
	 * [<sale>]
	 * : Sale price in force from that day, if any.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pnscripts-price-history add-record 123 2026-08-01 49.90
	 *     wp pnscripts-price-history add-record SKU-7 2026-09-20 49.90 39.90
	 *
	 * @subcommand add-record
	 *
	 * @param string[] $args Arguments.
	 */
	public function add_record( array $args ): void {
		$product_id = $this->plugin->manual->resolve( (string) ( $args[0] ?? '' ) );
		$error      = 0 === $product_id
			? ManualEntry::ERROR_PRODUCT
			: $this->plugin->manual->add( $product_id, (string) ( $args[1] ?? '' ), (string) ( $args[2] ?? '' ), (string) ( $args[3] ?? '' ) );
		if ( '' !== $error ) {
			WP_CLI::error( ManualRecords::message( $error ) );
		}
		WP_CLI::success( sprintf( 'Added a price from your records to product %d.', $product_id ) );
	}

	/**
	 * Import prices from your own records from a CSV file.
	 *
	 * Columns: product ID or SKU, date (YYYY-MM-DD), regular price, sale price (may be empty). A header line is
	 * allowed; comma, semicolon or tab separated.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pnscripts-price-history import-records prices-before-2026-10.csv
	 *
	 * @subcommand import-records
	 *
	 * @param string[] $args Arguments.
	 */
	public function import_records( array $args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'File not found or not readable.' );
		}
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file given on the command line.
		$result  = $this->plugin->manual->import_csv( $content );
		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( sprintf( 'Line %d (%s): %s', $error['line'], $error['product'], ManualRecords::message( $error['error'] ) ) );
		}
		WP_CLI::success( sprintf( 'Prices added: %d; lines not added: %d.', $result['added'], count( $result['errors'] ) ) );
	}

	/**
	 * Delete history older than the retention period now.
	 */
	public function prune(): void {
		$after   = 0;
		$deleted = 0;
		do {
			$result   = $this->plugin->retention->prune( $after, 200 );
			$deleted += $result['deleted'];
			$after    = (int) $result['next'];
		} while ( null !== $result['next'] );
		WP_CLI::success( sprintf( 'Deleted %d records.', $deleted ) );
	}

	/**
	 * Load the product given as first argument.
	 *
	 * @param string[] $args Arguments.
	 */
	private function product( array $args ): WC_Product {
		$product = wc_get_product( absint( $args[0] ?? 0 ) );
		if ( ! $product instanceof WC_Product ) {
			WP_CLI::error( 'Product not found.' );
			exit;
		}
		return $product;
	}
}
