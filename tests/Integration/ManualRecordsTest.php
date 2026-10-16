<?php
/**
 * Prices from the shop owner's own records.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Integration;

use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Domain\ReferenceResult;

/**
 * Day-one notices for products that were already on sale when recording started.
 */
final class ManualRecordsTest extends IntegrationTestCase {

	private function day( float $days_ago ): string {
		return (string) wp_date( 'Y-m-d', (int) ( time() - $days_ago * self::DAY ) );
	}

	public function test_owner_records_make_a_mid_sale_install_show_a_provable_notice(): void {
		$product = $this->simple( '100', '80' );
		$id      = $product->get_id();
		$this->age( array( $id ), 3 ); // Recording started 3 days ago, already on sale.

		$this->assertSame( ReferenceResult::REASON_START_UNKNOWN, $this->plugin->reference->compute( $this->fresh( $id ), time() )->reason );

		$this->assertSame( '', $this->plugin->manual->add( $id, $this->day( 60 ), '100', '' ) );
		$this->assertSame( '', $this->plugin->manual->add( $id, $this->day( 10 ), '100', '80' ) );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status );
		$this->assertSame( '100.000000', $result->price );
		$this->assertSame( ( new \DateTimeImmutable( $this->day( 10 ) . ' 00:00', wp_timezone() ) )->getTimestamp(), $result->anchor, 'The discount started on the day the owner gave.' );

		$sources = array_map( static fn ( $r ): string => $r->source, $this->records( $id ) );
		$this->assertSame( array( 'import:manual', 'import:manual' ), array_slice( $sources, 0, 2 ) );
	}

	public function test_moved_shop_product_created_recently_uses_the_owner_records_as_launch(): void {
		$product = $this->simple( '100', '80', 2 ); // Created in WooCommerce two days ago by an import.
		$id      = $product->get_id();
		$this->age( array( $id ), 1 );

		$this->assertSame( ReferenceResult::UNKNOWN, $this->plugin->reference->compute( $this->fresh( $id ), time() )->status );

		$this->plugin->manual->add( $id, $this->day( 90 ), '100', '' );
		$this->plugin->manual->add( $id, $this->day( 5 ), '100', '80' );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::KNOWN, $result->status, 'Records from the old shop prove the product was offered 90 days ago.' );
		$this->assertSame( '100.000000', $result->price );
	}

	public function test_imported_rows_of_other_plugins_do_not_move_the_launch(): void {
		$records = array(
			new \Pnscripts\Omnibus\Domain\PriceRecord( 1000, '10', null, null, null, '10', null, null, 'import:omnibus' ),
			new \Pnscripts\Omnibus\Domain\PriceRecord( 2000, '10', null, null, null, '10', null, null, 'admin' ),
		);
		$this->assertSame( 5000, \Pnscripts\Omnibus\Reference\ReferenceService::launch_with_records( 5000, $records ) );
		$records[] = new \Pnscripts\Omnibus\Domain\PriceRecord( 1500, '10', null, null, null, '10', null, null, ManualEntry::SOURCE );
		$this->assertSame( 1500, \Pnscripts\Omnibus\Reference\ReferenceService::launch_with_records( 5000, $records ) );
		$this->assertNull( \Pnscripts\Omnibus\Reference\ReferenceService::launch_with_records( null, $records ), 'Unknown launch stays unknown.' );
	}

	public function test_owner_records_that_do_not_cover_the_period_keep_the_notice_hidden(): void {
		$product = $this->simple( '100', '80' );
		$id      = $product->get_id();
		$this->age( array( $id ), 3 );

		$this->assertSame( '', $this->plugin->manual->add( $id, $this->day( 20 ), '100', '' ) );
		$this->assertSame( '', $this->plugin->manual->add( $id, $this->day( 10 ), '100', '80' ) );

		$result = $this->plugin->reference->compute( $this->fresh( $id ), time() );
		$this->assertSame( ReferenceResult::UNKNOWN, $result->status, '20 days of records cannot prove a 30-day period.' );
	}

	public function test_own_history_always_wins(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 5 );

		$this->assertSame( ManualEntry::ERROR_AFTER_RECORDED, $this->plugin->manual->add( $id, $this->day( 2 ), '90', '' ) );
		$this->assertSame( ManualEntry::ERROR_FUTURE, $this->plugin->manual->add( $id, $this->day( -2 ), '90', '' ) );
		$this->assertSame( '', $this->plugin->manual->add( $id, $this->day( 40 ), '100', '' ) );
		$this->assertSame( ManualEntry::ERROR_DUPLICATE, $this->plugin->manual->add( $id, $this->day( 40 ), '95', '' ) );
		$this->assertCount( 2, $this->records( $id ) );
	}

	public function test_variable_parent_is_refused_and_variations_are_accepted(): void {
		list( $parent, $variations ) = $this->variable( array( '30', '40' ) );
		$this->age( array( $variations[0]->get_id(), $variations[1]->get_id() ), 5 );

		$this->assertSame( ManualEntry::ERROR_PRODUCT_TYPE, $this->plugin->manual->add( $parent->get_id(), $this->day( 40 ), '30', '' ) );
		$this->assertSame( '', $this->plugin->manual->add( $variations[0]->get_id(), $this->day( 40 ), '30', '' ) );

		$rows = array_values( array_filter( $this->records( $variations[0]->get_id() ), static fn ( $r ): bool => ManualEntry::SOURCE === $r->source ) );
		$this->assertCount( 1, $rows );
		global $wpdb;
		$this->assertSame(
			(string) $parent->get_id(),
			(string) $wpdb->get_var( $wpdb->prepare( 'SELECT parent_id FROM %i WHERE id = %d', \Pnscripts\Omnibus\Storage\Schema::table(), $rows[0]->id ) )
		);
	}

	public function test_only_owner_rows_can_be_deleted(): void {
		$product = $this->simple( '100' );
		$id      = $product->get_id();
		$this->age( array( $id ), 5 );
		$this->plugin->manual->add( $id, $this->day( 40 ), '100', '' );

		$records = $this->records( $id );
		$this->assertCount( 2, $records );
		$own    = $records[1];
		$manual = $records[0];
		$this->assertSame( ManualEntry::SOURCE, $manual->source );

		$this->assertFalse( $this->plugin->repository->delete_manual( $id, $own->id ), 'Recorded history is never deleted from this screen.' );
		$this->assertFalse( $this->plugin->repository->delete_manual( $id + 999999, $manual->id ), 'The row must belong to the product.' );
		$this->assertTrue( $this->plugin->repository->delete_manual( $id, $manual->id ) );
		$this->assertCount( 1, $this->records( $id ) );
	}

	public function test_csv_import_by_sku_and_id_reports_each_refused_line(): void {
		$a = $this->simple( '50', '40' );
		$a->set_sku( 'PH-TEST-' . wp_generate_password( 5, false ) );
		$a->save();
		$b = $this->simple( '20' );
		$this->age( array( $a->get_id(), $b->get_id() ), 2 );

		$csv = "product;date;regular_price;sale_price\n"
			. $a->get_sku() . ';' . $this->day( 45 ) . ";50,00;\n"
			. $a->get_sku() . ';' . $this->day( 7 ) . ";50,00;40,00\n"
			. $b->get_id() . ';' . $this->day( 45 ) . ";20;\n"
			. 'NO-SUCH-SKU;' . $this->day( 45 ) . ";20;\n"
			. $b->get_id() . ';' . $this->day( 1 ) . ";20;\n"
			. $b->get_id() . ";2026-02-30;20;\n";

		$result = $this->plugin->manual->import_csv( $csv );

		$this->assertSame( 3, $result['added'] );
		$this->assertSame(
			array(
				array( 5, ManualEntry::ERROR_PRODUCT ),
				array( 6, ManualEntry::ERROR_AFTER_RECORDED ),
				array( 7, ManualEntry::ERROR_DATE ),
			),
			array_map( static fn ( array $e ): array => array( $e['line'], $e['error'] ), $result['errors'] )
		);
		$this->assertSame( ReferenceResult::KNOWN, $this->plugin->reference->compute( $this->fresh( $a->get_id() ), time() )->status );
		$this->assertSame( '50.000000', $this->plugin->reference->compute( $this->fresh( $a->get_id() ), time() )->price );
	}

	public function test_table_size_is_reported_on_mysql_only(): void {
		$bytes = $this->plugin->repository->table_bytes();
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			$this->assertNull( $bytes );
			return;
		}
		$this->assertIsInt( $bytes );
		$this->assertGreaterThan( 0, $bytes );
	}
}
