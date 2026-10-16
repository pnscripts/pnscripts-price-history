<?php
/**
 * Reference (prior) price for WooCommerce products.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Reference;

use Pnscripts\Omnibus\Capture\PriceRecorder;
use Pnscripts\Omnibus\Capture\TaxWatcher;
use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\PriceTimeline;
use Pnscripts\Omnibus\Domain\ReferencePriceCalculator;
use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Connects products, stored history, settings and the calculator. Results are cached per product for a few
 * minutes and invalidated whenever a price is recorded.
 */
final class ReferenceService {

	public const PERISHABLE_META = '_pnscripts_omnibus_perishable';
	public const PERISHABLE_TERM = 'pnscripts_omnibus_perishable';

	/**
	 * Cache lifetime bucket in seconds (scheduled sales are re-evaluated at least this often).
	 */
	private const CACHE_BUCKET = 300;

	/**
	 * Per-request results.
	 *
	 * @var array<string, ReferenceResult>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository $repository Storage.
	 * @param Settings          $settings   Settings.
	 * @param Clock             $clock      Time.
	 */
	public function __construct(
		private readonly HistoryRepository $repository,
		private readonly Settings $settings,
		private readonly Clock $clock
	) {
	}

	/**
	 * Reference price of a simple product or a variation.
	 *
	 * @param WC_Product $product Product.
	 */
	public function for_product( WC_Product $product ): ReferenceResult {
		$now = $this->clock->now();
		$key = $this->cache_key( $product, $now );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$cached = wp_cache_get( $key, HistoryRepository::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$result = ReferenceResult::from_array( $cached );
		} else {
			$result = $this->compute( $product, $now );
			wp_cache_set( $key, $result->to_array(), HistoryRepository::CACHE_GROUP, self::CACHE_BUCKET );
		}

		/**
		 * Filters the reference price result.
		 *
		 * @param ReferenceResult $result  Result.
		 * @param WC_Product      $product Product.
		 */
		$filtered = apply_filters( 'pnscripts_omnibus_reference_result', $result, $product );
		$result   = $filtered instanceof ReferenceResult ? $filtered : $result;

		$this->memo[ $key ] = $result;
		return $result;
	}

	/**
	 * Uncached computation.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $now     UTC timestamp.
	 */
	public function compute( WC_Product $product, int $now ): ReferenceResult {
		$policy = $this->settings->policy();
		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return new ReferenceResult( ReferenceResult::UNKNOWN, null, null, null, $policy->period_days, false, ReferenceResult::REASON_UNSUPPORTED_PRODUCT );
		}
		if ( $this->settings->bool( 'perishable_exemption' ) && $this->is_perishable( $product ) ) {
			return ReferenceResult::exempt( ReferenceResult::REASON_PERISHABLE );
		}

		$records = PriceTimeline::records_in_currency( $this->repository->for_product( $product->get_id() ), PriceRecorder::currency( $product ) );

		// While the resume job has not checked this product after an inactive period, its prices in that period
		// are unknown: the latest record may predate changes nobody recorded.
		$window = PriceRecorder::resume_window();
		$latest = end( $records );
		if ( null !== $window && false !== $latest && $latest->changed_at < $window['to'] ) {
			return ReferenceResult::unknown( ReferenceResult::REASON_GAP, null, $policy->period_days );
		}

		$timeline = PriceTimeline::from_records( $records );
		$result   = ( new ReferencePriceCalculator( wp_timezone() ) )->calculate( $timeline, $now, $policy, self::launch_with_records( $this->launch_time( $product ), $records ) );

		$tax_changed = TaxWatcher::changed_at();
		if ( $result->is_known() && null !== $tax_changed && null !== $result->window_start && $tax_changed > $result->window_start ) {
			// Prices in the period were entered under other tax settings; converting them with today's is wrong.
			return ReferenceResult::unknown( ReferenceResult::REASON_TAX_CHANGED, $result->anchor, $result->period_days );
		}
		if ( ReferenceResult::NOT_ON_SALE === $result->status && $product->is_on_sale() ) {
			// WooCommerce shows a reduction that the stored prices do not explain (e.g. a price filter).
			return ReferenceResult::unknown( ReferenceResult::REASON_NOT_CAPTURED, null, $policy->period_days );
		}
		return $result;
	}

	/**
	 * A price the owner entered from their own records proves the product was offered on that day, even when
	 * WooCommerce created it later (a shop moved from another system keeps its products' history this way).
	 *
	 * @param int|null      $launch  Launch time from the product.
	 * @param PriceRecord[] $records Records of the product.
	 */
	public static function launch_with_records( ?int $launch, array $records ): ?int {
		foreach ( $records as $record ) {
			if ( ManualEntry::SOURCE === $record->source && null !== $launch && $record->changed_at < $launch ) {
				$launch = $record->changed_at;
			}
		}
		return $launch;
	}

	/**
	 * When the product was first offered: its creation date (and its parent's for variations).
	 *
	 * @param WC_Product $product Product.
	 */
	public function launch_time( WC_Product $product ): ?int {
		$created = $product->get_date_created( 'edit' );
		$launch  = $created ? $created->getTimestamp() : null;
		$parent  = $product->get_parent_id() > 0 ? wc_get_product( $product->get_parent_id() ) : null;
		if ( $parent instanceof WC_Product ) {
			$parent_created = $parent->get_date_created( 'edit' );
			if ( $parent_created ) {
				$launch = max( (int) $launch, $parent_created->getTimestamp() );
			}
		}

		/**
		 * Filters the launch time (UTC timestamp) used by the new-product rule.
		 *
		 * @param int|null   $launch  Timestamp or null.
		 * @param WC_Product $product Product.
		 */
		$filtered = apply_filters( 'pnscripts_omnibus_launch_time', $launch, $product );
		return is_int( $filtered ) && $filtered > 0 ? $filtered : null;
	}

	/**
	 * Whether a product (or its parent, or one of its categories) is marked as perishable.
	 *
	 * @param WC_Product $product Product.
	 */
	public function is_perishable( WC_Product $product ): bool {
		$base_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		if ( 'yes' === get_post_meta( $base_id, self::PERISHABLE_META, true ) ) {
			return true;
		}
		$terms = get_the_terms( $base_id, 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 'yes' === get_term_meta( $term->term_id, self::PERISHABLE_TERM, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Price as shown in the shop (tax display setting of the shop applied).
	 *
	 * @param WC_Product      $product Product.
	 * @param ReferenceResult $result  Known result.
	 */
	public function display_price( WC_Product $product, ReferenceResult $result ): float {
		$amount = (float) wc_get_price_to_display(
			$product,
			array(
				'price' => (float) $result->price,
				'qty'   => 1,
			)
		);

		/**
		 * Filters the reference price shown to shoppers (e.g. currency conversion in Pro).
		 *
		 * @param float           $amount  Amount in the shop's display terms.
		 * @param WC_Product      $product Product.
		 * @param ReferenceResult $result  Result.
		 */
		return (float) apply_filters( 'pnscripts_omnibus_display_price', $amount, $product, $result );
	}

	/**
	 * Cache key: product, history version, settings, time bucket.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $now     UTC timestamp.
	 */
	private function cache_key( WC_Product $product, int $now ): string {
		$version  = wp_cache_get_last_changed( HistoryRepository::CACHE_GROUP );
		$settings = md5( (string) wp_json_encode( $this->settings->all() ) );
		return sprintf( 'ref:%d:%s:%s:%d', $product->get_id(), $version, substr( $settings, 0, 8 ), intdiv( $now, self::CACHE_BUCKET ) );
	}
}
