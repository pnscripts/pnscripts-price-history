<?php
/**
 * Prices from the shop owner's records: validation and CSV parsing.
 *
 * @package Pnscripts\Omnibus\Tests
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Pnscripts\Omnibus\Domain\ManualEntry;

/**
 * ManualEntry.
 */
final class ManualEntryTest extends TestCase {

	/**
	 * 2026-10-03 (UTC).
	 */
	private const NOW = 1791000000;

	private function sofia(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Sofia' );
	}

	public function test_valid_row_starts_at_local_midnight_and_is_an_imported_row(): void {
		$result = ManualEntry::parse( '2026-08-01', '49.90', '', $this->sofia(), self::NOW, null );
		$record = $result['record'];
		$this->assertNotNull( $record );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( ( new \DateTimeImmutable( '2026-08-01 00:00', $this->sofia() ) )->getTimestamp(), $record->changed_at );
		$this->assertSame( '49.900000', $record->regular );
		$this->assertNull( $record->sale );
		$this->assertSame( '49.900000', $record->price );
		$this->assertSame( 'import:manual', $record->source );
		$this->assertStringStartsWith( 'import:', $record->source, 'Stored like imported history: it never outranks the plugin\'s own records.' );
	}

	public function test_sale_row_keeps_regular_and_sale(): void {
		$record = ManualEntry::parse( '2026-09-20', '49,90', '39,90', $this->sofia(), self::NOW, null )['record'];
		$this->assertNotNull( $record );
		$this->assertSame( '49.900000', $record->regular );
		$this->assertSame( '39.900000', $record->sale );
		$this->assertSame( '39.900000', $record->price );
		$state = $record->state_at( $record->changed_at + 10 );
		$this->assertTrue( $state['reduced'] );
		$this->assertTrue( $state['reduced_known'], 'An owner row says whether it was a sale, unlike unflagged imports.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: ?int, 4: string}>
	 */
	public static function invalid_rows(): array {
		$own = ( new \DateTimeImmutable( '2026-09-01 10:00', new \DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
		return array(
			'not a date'                => array( '01.08.2026', '10', '', null, ManualEntry::ERROR_DATE ),
			'impossible day'            => array( '2026-02-30', '10', '', null, ManualEntry::ERROR_DATE ),
			'empty date'                => array( '', '10', '', null, ManualEntry::ERROR_DATE ),
			'future'                    => array( '2027-01-01', '10', '', null, ManualEntry::ERROR_FUTURE ),
			'after own record'          => array( '2026-09-15', '10', '', $own, ManualEntry::ERROR_AFTER_RECORDED ),
			'no regular price'          => array( '2026-08-01', '', '', null, ManualEntry::ERROR_REGULAR ),
			'zero regular price'        => array( '2026-08-01', '0', '', null, ManualEntry::ERROR_REGULAR ),
			'negative regular price'    => array( '2026-08-01', '-5', '', null, ManualEntry::ERROR_REGULAR ),
			'text price'                => array( '2026-08-01', 'abc', '', null, ManualEntry::ERROR_REGULAR ),
			'ambiguous thousands comma' => array( '2026-08-01', '1,299', '', null, ManualEntry::ERROR_REGULAR ),
			'ambiguous thousands dot'   => array( '2026-08-01', '1.299', '', null, ManualEntry::ERROR_REGULAR ),
			'bad sale'                  => array( '2026-08-01', '10', 'x', null, ManualEntry::ERROR_SALE ),
			'sale equal to regular'     => array( '2026-08-01', '10', '10.00', null, ManualEntry::ERROR_SALE_NOT_LOWER ),
			'sale above regular'        => array( '2026-08-01', '10', '12', null, ManualEntry::ERROR_SALE_NOT_LOWER ),
		);
	}

	#[DataProvider( 'invalid_rows' )]
	public function test_invalid_rows_are_refused( string $date, string $regular, string $sale, ?int $own, string $error ): void {
		$result = ManualEntry::parse( $date, $regular, $sale, $this->sofia(), self::NOW, $own );
		$this->assertNull( $result['record'] );
		$this->assertSame( $error, $result['error'] );
	}

	public function test_days_before_own_recording_are_accepted(): void {
		$own = ( new \DateTimeImmutable( '2026-09-01 10:00', $this->sofia() ) )->getTimestamp();
		$this->assertNotNull( ManualEntry::parse( '2026-08-31', '10', '', $this->sofia(), self::NOW, $own )['record'] );
		// The same day starts at 00:00, before the first own record at 10:00: it covers those ten hours only.
		$this->assertNotNull( ManualEntry::parse( '2026-09-01', '10', '', $this->sofia(), self::NOW, $own )['record'] );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function prices(): array {
		return array(
			'dot'                   => array( '19.90', '19.900000' ),
			'comma'                 => array( '19,90', '19.900000' ),
			'integer'               => array( '20', '20.000000' ),
			'thousands and decimal' => array( '1.299,90', '1299.900000' ),
			'english thousands'     => array( '1,299.90', '1299.900000' ),
			'spaces'                => array( ' 1 299,90 ', '1299.900000' ),
			'three decimals of 0'   => array( '0.125', '0.125000' ),
			'currency sign'         => array( '€19.90', null ),
			'empty'                 => array( '', null ),
		);
	}

	#[DataProvider( 'prices' )]
	public function test_price_normalisation( string $typed, ?string $expected ): void {
		$this->assertSame( $expected, ManualEntry::price( $typed ) );
	}

	public function test_csv_with_header_comma_separator_and_bom(): void {
		$csv  = "\xEF\xBB\xBFproduct,date,regular_price,sale_price\r\nSKU-1,2026-08-01,49.90,\r\n\r\nSKU-1,2026-09-20,49.90,39.90\r\n123,2026-07-15,\"19,00\",\r\n";
		$rows = ManualEntry::parse_csv( $csv );
		$this->assertCount( 3, $rows );
		$this->assertSame(
			array(
				'line'    => 2,
				'product' => 'SKU-1',
				'date'    => '2026-08-01',
				'regular' => '49.90',
				'sale'    => '',
			),
			$rows[0]
		);
		$this->assertSame( 4, $rows[1]['line'] );
		$this->assertSame( '39.90', $rows[1]['sale'] );
		$this->assertSame( '19,00', $rows[2]['regular'] );
	}

	public function test_csv_semicolon_without_header(): void {
		$rows = ManualEntry::parse_csv( "SKU-1;2026-08-01;49,90;\nSKU-2;2026-08-02;10;8\n" );
		$this->assertCount( 2, $rows );
		$this->assertSame( 1, $rows[0]['line'] );
		$this->assertSame( '49,90', $rows[0]['regular'] );
		$this->assertSame( '8', $rows[1]['sale'] );
	}

	public function test_csv_first_line_with_a_typo_in_the_date_is_kept_as_data_so_it_is_reported(): void {
		$rows = ManualEntry::parse_csv( "SKU-1\t2026-13-01\t49.90\t\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-13-01', $rows[0]['date'] );
	}

	public function test_csv_row_limit(): void {
		$csv = str_repeat( "1,2026-08-01,10,\n", ManualEntry::MAX_CSV_ROWS + 10 );
		$this->assertCount( ManualEntry::MAX_CSV_ROWS, ManualEntry::parse_csv( $csv ) );
	}
}
