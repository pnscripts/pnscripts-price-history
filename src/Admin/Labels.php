<?php
/**
 * Human-readable admin labels.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Domain\ReferenceResult;

defined( 'ABSPATH' ) || exit;

/**
 * Translated labels for statuses, reasons and sources.
 */
final class Labels {

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 */
	public static function status( string $status ): string {
		return match ( $status ) {
			ReferenceResult::KNOWN       => __( 'Shown', 'pnscripts-price-history' ),
			ReferenceResult::NOT_ON_SALE => __( 'Not on sale', 'pnscripts-price-history' ),
			ReferenceResult::EXEMPT      => __( 'Exempt', 'pnscripts-price-history' ),
			default                      => __( 'Unknown, hidden', 'pnscripts-price-history' ),
		};
	}

	/**
	 * Explanation of a result.
	 *
	 * @param ReferenceResult $result Result.
	 */
	public static function explain( ReferenceResult $result ): string {
		return match ( $result->reason ) {
			ReferenceResult::REASON_NO_HISTORY          => __( 'No price history has been recorded yet.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_GAP                 => __( 'Prices during part of the period are unknown (the plugin was inactive or prices were changed outside WooCommerce).', 'pnscripts-price-history' ),
			ReferenceResult::REASON_INCOMPLETE          => __( 'The recorded history does not cover the whole period before the discount yet.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_NEW_PRODUCT         => __( 'The product was launched less than the period before the discount and the current rules hide the notice for such products.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_START_UNKNOWN       => __( 'The product was already on sale when recording started, so the start of the discount is unknown.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_NO_PRIOR_PRICE      => __( 'There is no price before the discount (the product was launched on sale or had no price).', 'pnscripts-price-history' ),
			ReferenceResult::REASON_NOT_CAPTURED        => __( 'WooCommerce shows a discount that is not in the recorded prices (for example a price changed by another plugin on the fly).', 'pnscripts-price-history' ),
			ReferenceResult::REASON_PERISHABLE          => __( 'Marked as perishable and the perishable-goods exemption is enabled.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_UNSUPPORTED_PRODUCT => __( 'Variable and grouped products are evaluated per variation or child product.', 'pnscripts-price-history' ),
			ReferenceResult::REASON_TAX_CHANGED         => __( 'The tax settings or rates changed during the period, so earlier prices cannot be converted with today\'s taxes.', 'pnscripts-price-history' ),
			default                                     => '',
		};
	}

	/**
	 * Source label.
	 *
	 * @param string $source Source id.
	 */
	public static function source( string $source ): string {
		$base = explode( ':meta', $source )[0];
		$map  = array(
			'admin'                   => __( 'Product editor', 'pnscripts-price-history' ),
			'quick_edit'              => __( 'Quick edit', 'pnscripts-price-history' ),
			'bulk_edit'               => __( 'Bulk edit', 'pnscripts-price-history' ),
			'csv_import'              => __( 'CSV import', 'pnscripts-price-history' ),
			'rest'                    => __( 'REST API', 'pnscripts-price-history' ),
			'cli'                     => __( 'WP-CLI', 'pnscripts-price-history' ),
			'cron'                    => __( 'Background task', 'pnscripts-price-history' ),
			'scheduled_sale'          => __( 'Scheduled sale', 'pnscripts-price-history' ),
			'ajax'                    => __( 'AJAX', 'pnscripts-price-history' ),
			'programmatic'            => __( 'Code', 'pnscripts-price-history' ),
			'baseline'                => __( 'First record', 'pnscripts-price-history' ),
			'resume'                  => __( 'After inactivity', 'pnscripts-price-history' ),
			'repair'                  => __( 'Repair', 'pnscripts-price-history' ),
			'import:omnibus'          => __( 'Imported (Omnibus by iWorks)', 'pnscripts-price-history' ),
			'import:wc-price-history' => __( 'Imported (WC Price History)', 'pnscripts-price-history' ),
			'import:omnibus-by-ilabs' => __( 'Imported (Omnibus by iLabs)', 'pnscripts-price-history' ),
			'import:manual'           => __( 'Your records', 'pnscripts-price-history' ),
		);
		return $map[ $base ] ?? $source;
	}
}
