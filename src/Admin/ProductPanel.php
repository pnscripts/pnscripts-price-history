<?php
/**
 * Product edit screen: price history metabox and perishable flag.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Domain\ManualEntry;
use Pnscripts\Omnibus\Domain\PriceRecord;
use Pnscripts\Omnibus\Domain\ReferenceResult;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Records\ManualRecords;
use Pnscripts\Omnibus\Reference\ReferenceService;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * History view, prices from the owner's records and the per-product perishable checkbox.
 */
final class ProductPanel {

	/**
	 * Per-user transient with the result of the last save.
	 */
	private const NOTICE_TRANSIENT = 'pnscripts_omnibus_manual_notice_';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Services.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'perishable_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_perishable' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_manual' ), 20 );
		add_action( 'admin_notices', array( $this, 'manual_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Admin stylesheet on product screens and the settings page.
	 *
	 * @param string $hook_suffix Screen hook.
	 */
	public function enqueue( $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ( $screen && 'product' === $screen->id ) || str_contains( (string) $hook_suffix, 'pnscripts-price-history' ) ) {
			wp_enqueue_style( 'pnscripts-omnibus-admin', PNSCRIPTS_OMNIBUS_URL . 'assets/css/admin.css', array(), PNSCRIPTS_OMNIBUS_VERSION );
		}
		if ( str_contains( (string) $hook_suffix, 'pnscripts-price-history' ) ) {
			wp_enqueue_script( 'pnscripts-omnibus-admin', PNSCRIPTS_OMNIBUS_URL . 'assets/js/admin.js', array(), PNSCRIPTS_OMNIBUS_VERSION, true );
		}
	}

	/**
	 * Register the metabox.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'pnscripts-omnibus-history',
			__( 'Price history and lowest prior price', 'pnscripts-price-history' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Product post.
	 */
	public function render_meta_box( $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo '<div class="pnscripts-omnibus-panel">';
		echo '<p class="description">' . esc_html__( 'Recorded automatically on every price change. This plugin helps display prices; you remain responsible for compliance.', 'pnscripts-price-history' ) . '</p>';

		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			if ( array() === $children ) {
				echo '<p>' . esc_html__( 'No variations yet.', 'pnscripts-price-history' ) . '</p>';
			}
			foreach ( $children as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof WC_Product ) {
					printf( '<h4>%s</h4>', esc_html( wp_strip_all_tags( $variation->get_formatted_name() ) ) );
					$this->render_product( $variation );
				}
			}
		} elseif ( $product->is_type( 'grouped' ) ) {
			echo '<p>' . esc_html__( 'Grouped products have no own price; see each child product.', 'pnscripts-price-history' ) . '</p>';
		} else {
			$this->render_product( $product );
		}
		echo '</div>';
	}

	/**
	 * Result and history table of one product or variation.
	 *
	 * @param WC_Product $product Product.
	 */
	private function render_product( WC_Product $product ): void {
		$result = $this->plugin->reference->for_product( $product );
		$this->render_result( $product, $result );

		$records = $this->plugin->repository->recent( $product->get_id(), 30 );
		if ( array() === $records ) {
			echo '<p>' . esc_html__( 'No prices recorded yet.', 'pnscripts-price-history' ) . '</p>';
			$this->render_manual_form( $product, $result );
			return;
		}
		echo '<div class="pnscripts-omnibus-scroll"><table class="widefat striped pnscripts-omnibus-history"><thead><tr>';
		foreach ( array(
			__( 'From', 'pnscripts-price-history' ),
			__( 'Regular', 'pnscripts-price-history' ),
			__( 'Sale', 'pnscripts-price-history' ),
			__( 'Sale schedule', 'pnscripts-price-history' ),
			__( 'Price', 'pnscripts-price-history' ),
			__( 'Source', 'pnscripts-price-history' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $records as $record ) {
			$this->render_row( $record, $product->get_id() );
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'Prices as entered in the shop (before the tax display setting). Newest first, up to 30 rows.', 'pnscripts-price-history' ) . '</p>';
		$this->render_manual_form( $product, $result );
	}

	/**
	 * "Add a price from your records" fields (saved with the product form).
	 *
	 * @param WC_Product      $product Product or variation.
	 * @param ReferenceResult $result  Current result.
	 */
	private function render_manual_form( WC_Product $product, ReferenceResult $result ): void {
		$id     = $product->get_id();
		$own    = $this->plugin->repository->earliest_own( $id );
		$open   = ReferenceResult::UNKNOWN === $result->status && in_array( $result->reason, array( ReferenceResult::REASON_START_UNKNOWN, ReferenceResult::REASON_INCOMPLETE, ReferenceResult::REASON_NO_HISTORY ), true );
		$name   = 'pnscripts_omnibus_manual[' . $id . ']';
		$prefix = 'pnscripts-omnibus-manual-' . $id;
		printf( '<details class="pnscripts-omnibus-manual"%s>', $open ? ' open' : '' );
		echo '<summary>' . esc_html__( 'Add a price from your own records', 'pnscripts-price-history' ) . '</summary>';
		echo '<p class="description">' . esc_html(
			null !== $own
				? sprintf(
					/* translators: %s: date when the plugin started recording this product */
					__( 'For the time before this plugin started recording this product (%s): the price that applied from a day until the next row. Add one row for the regular price and one for the day the current discount started. You are responsible for these prices; keep the records they come from.', 'pnscripts-price-history' ),
					(string) wp_date( (string) get_option( 'date_format' ), $own )
				)
				: __( 'The price that applied from a day until the next row. You are responsible for these prices; keep the records they come from.', 'pnscripts-price-history' )
		) . '</p>';
		echo '<p class="pnscripts-omnibus-manual-fields">';
		printf(
			'<label for="%1$s-date">%2$s</label> <input type="date" id="%1$s-date" name="%3$s[date]" max="%4$s" /> ',
			esc_attr( $prefix ),
			esc_html__( 'From', 'pnscripts-price-history' ),
			esc_attr( $name ),
			esc_attr( (string) wp_date( 'Y-m-d', null !== $own ? $own - 1 : $this->plugin->clock->now() ) )
		);
		printf(
			'<label for="%1$s-regular">%2$s</label> <input type="text" inputmode="decimal" size="8" id="%1$s-regular" name="%3$s[regular]" /> ',
			esc_attr( $prefix ),
			esc_html__( 'Regular price', 'pnscripts-price-history' ),
			esc_attr( $name )
		);
		printf(
			'<label for="%1$s-sale">%2$s</label> <input type="text" inputmode="decimal" size="8" id="%1$s-sale" name="%3$s[sale]" />',
			esc_attr( $prefix ),
			esc_html__( 'Sale price (optional)', 'pnscripts-price-history' ),
			esc_attr( $name )
		);
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Saved when you update the product. Many products at once: WooCommerce → Price History → Import and tools.', 'pnscripts-price-history' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Save "price from your records" rows and removals (WooCommerce verified the product form nonce).
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_manual( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		$allowed = array_map( 'intval', array_merge( array( $product->get_id() ), $product->get_children() ) );
		$notes   = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		$removals = isset( $_POST['pnscripts_omnibus_manual_delete'] ) && is_array( $_POST['pnscripts_omnibus_manual_delete'] ) ? map_deep( wp_unslash( $_POST['pnscripts_omnibus_manual_delete'] ), 'absint' ) : array();
		foreach ( $removals as $product_id => $ids ) {
			if ( ! in_array( (int) $product_id, $allowed, true ) || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $row_id ) {
				if ( $this->plugin->repository->delete_manual( (int) $product_id, (int) $row_id ) ) {
					$notes[] = array( 'success', __( 'A price from your records was removed.', 'pnscripts-price-history' ) );
				}
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		$rows = isset( $_POST['pnscripts_omnibus_manual'] ) && is_array( $_POST['pnscripts_omnibus_manual'] ) ? map_deep( wp_unslash( $_POST['pnscripts_omnibus_manual'] ), 'sanitize_text_field' ) : array();
		foreach ( $rows as $product_id => $row ) {
			if ( ! in_array( (int) $product_id, $allowed, true ) || ! is_array( $row ) ) {
				continue;
			}
			$date    = (string) ( $row['date'] ?? '' );
			$regular = (string) ( $row['regular'] ?? '' );
			$sale    = (string) ( $row['sale'] ?? '' );
			if ( '' === trim( $date . $regular . $sale ) ) {
				continue;
			}
			$error   = $this->plugin->manual->add( (int) $product_id, $date, $regular, $sale );
			$notes[] = '' === $error
				? array( 'success', __( 'The price from your records was added to the price history.', 'pnscripts-price-history' ) )
				: array( 'error', ManualRecords::message( $error ) );
		}

		if ( array() !== $notes ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $notes, 120 );
		}
	}

	/**
	 * Show the result of the last save on the product screen.
	 */
	public function manual_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		$key   = self::NOTICE_TRANSIENT . get_current_user_id();
		$notes = get_transient( $key );
		if ( ! is_array( $notes ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notes as $note ) {
			if ( is_array( $note ) && isset( $note[0], $note[1] ) ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
					'error' === $note[0] ? 'error' : 'success',
					esc_html__( 'Price History:', 'pnscripts-price-history' ),
					esc_html( (string) $note[1] )
				);
			}
		}
	}

	/**
	 * One history row.
	 *
	 * @param PriceRecord $record     Record.
	 * @param int         $product_id Product or variation id (for the remove checkbox).
	 */
	private function render_row( PriceRecord $record, int $product_id ): void {
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$schedule = '';
		if ( null !== $record->sale_from || null !== $record->sale_to ) {
			$schedule = sprintf(
				'%s – %s',
				null !== $record->sale_from ? wp_date( (string) get_option( 'date_format' ), $record->sale_from ) : '…',
				null !== $record->sale_to ? wp_date( (string) get_option( 'date_format' ), $record->sale_to ) : '…'
			);
		}
		echo '<tr>';
		printf( '<td>%s</td>', esc_html( (string) wp_date( $format, $record->changed_at ) ) );
		printf( '<td>%s</td>', null !== $record->regular ? wp_kses_post( wc_price( (float) $record->regular ) ) : '&mdash;' );
		printf( '<td>%s</td>', null !== $record->sale ? wp_kses_post( wc_price( (float) $record->sale ) ) : '&mdash;' );
		printf( '<td>%s</td>', '' !== $schedule ? esc_html( $schedule ) : '&mdash;' );
		printf( '<td>%s</td>', null !== $record->price ? wp_kses_post( wc_price( (float) $record->price ) ) : '&mdash;' );
		printf(
			'<td>%s%s',
			esc_html( Labels::source( $record->source ) ),
			null !== $record->unknown_since ? ' <span class="pnscripts-omnibus-gap">' . esc_html__( '(unknown before)', 'pnscripts-price-history' ) . '</span>' : ''
		);
		if ( ManualEntry::SOURCE === $record->source && $record->id > 0 ) {
			printf(
				' <label class="pnscripts-omnibus-remove"><input type="checkbox" name="pnscripts_omnibus_manual_delete[%1$d][]" value="%2$d" /> %3$s</label>',
				(int) $product_id,
				(int) $record->id,
				esc_html__( 'Remove', 'pnscripts-price-history' )
			);
		}
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Computed result summary.
	 *
	 * @param WC_Product      $product Product.
	 * @param ReferenceResult $result  Result.
	 */
	private function render_result( WC_Product $product, ReferenceResult $result ): void {
		echo '<p class="pnscripts-omnibus-result pnscripts-omnibus-result--' . esc_attr( $result->status ) . '"><strong>' . esc_html( Labels::status( $result->status ) ) . '</strong>';
		if ( $result->is_known() ) {
			printf(
				' — %s %s',
				esc_html(
					sprintf(
						/* translators: 1: number of days, 2: date when the discount started */
						__( 'lowest price in the %1$d days before the discount that started on %2$s:', 'pnscripts-price-history' ),
						$result->period_days,
						null !== $result->anchor ? (string) wp_date( (string) get_option( 'date_format' ), $result->anchor ) : ''
					)
				),
				wp_kses_post( wc_price( $this->plugin->reference->display_price( $product, $result ) ) )
			);
		} elseif ( '' !== Labels::explain( $result ) ) {
			echo ' — ' . esc_html( Labels::explain( $result ) );
		}
		echo '</p>';
	}

	/**
	 * Perishable checkbox in the General tab.
	 */
	public function perishable_field(): void {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => ReferenceService::PERISHABLE_META,
				'label'       => __( 'Perishable goods', 'pnscripts-price-history' ),
				'description' => __( 'Goods that perish or expire quickly. Only used when the perishable-goods exemption is enabled in the Price History settings.', 'pnscripts-price-history' ),
			)
		);
	}

	/**
	 * Save the perishable flag (WooCommerce verified the product form nonce before this action).
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_perishable( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		$checked = isset( $_POST[ ReferenceService::PERISHABLE_META ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ ReferenceService::PERISHABLE_META ] ) );
		$product->update_meta_data( ReferenceService::PERISHABLE_META, $checked ? 'yes' : 'no' );
		HistoryRepository::bump_cache();
	}
}
