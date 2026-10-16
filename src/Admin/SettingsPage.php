<?php
/**
 * WooCommerce → Price History admin page (settings, coverage report, tools).
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus\Admin;

use Pnscripts\Omnibus\Display\NoticeRenderer;
use Pnscripts\Omnibus\Domain\ReferencePolicy;
use Pnscripts\Omnibus\Import\ImportRunner;
use Pnscripts\Omnibus\Import\Importers;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Jobs\Queue;
use Pnscripts\Omnibus\Jobs\Retention;
use Pnscripts\Omnibus\Plugin;
use Pnscripts\Omnibus\Records\ManualRecords;
use Pnscripts\Omnibus\Settings;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page with three tabs. Every action checks the manage_woocommerce capability and a nonce.
 */
final class SettingsPage {

	public const SLUG       = 'pnscripts-price-history';
	public const CAPABILITY = 'manage_woocommerce';
	private const GROUP     = 'pnscripts_omnibus';
	private const TOOL      = 'pnscripts_omnibus_tool';
	private const RECORDS   = 'pnscripts_omnibus_records';
	private const RESULT    = 'pnscripts_omnibus_records_result_';
	private const PER_PAGE  = 25;

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
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::TOOL, array( $this, 'handle_tool' ) );
		add_action( 'admin_post_' . self::RECORDS, array( $this, 'handle_records' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_updated' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PNSCRIPTS_OMNIBUS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability for saving the settings group.
	 */
	public function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Price History: lowest price in the 30 days before a discount', 'pnscripts-price-history' ),
			__( 'Price History', 'pnscripts-price-history' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'pnscripts-price-history' ) )
		);
		return $links;
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => static fn ( $input ): array => Settings::sanitize( is_array( $input ) ? $input : array(), true ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Settings changed: drop caches.
	 */
	public function settings_updated(): void {
		$this->plugin->settings->flush();
		HistoryRepository::bump_cache();
	}

	/**
	 * Page URL.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Extra query args.
	 */
	public static function url( string $tab = 'settings', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pnscripts-price-history' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = array(
			'settings' => __( 'Settings', 'pnscripts-price-history' ),
			'report'   => __( 'Coverage report', 'pnscripts-price-history' ),
			'tools'    => __( 'Import and tools', 'pnscripts-price-history' ),
		);

		/**
		 * Filters the admin tabs (add-ons can add their own).
		 *
		 * @param array<string, string> $tabs Tab id => label.
		 */
		$tabs = (array) apply_filters( 'pnscripts_omnibus_admin_tabs', $tabs );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap pnscripts-omnibus-admin">';
		echo '<h1>' . esc_html__( 'Price History: lowest price before a discount', 'pnscripts-price-history' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( (string) $id ) ),
				$id === $tab ? ' nav-tab-active' : '',
				esc_html( (string) $label )
			);
		}
		echo '</nav>';
		$this->render_notices();
		echo '<p class="pnscripts-omnibus-disclaimer">' . esc_html__( 'This plugin helps display prices; you remain responsible for compliance. It does not give legal advice. Check the rules that apply to your shop.', 'pnscripts-price-history' ) . '</p>';

		switch ( $tab ) {
			case 'report':
				$this->render_report();
				break;
			case 'tools':
				$this->render_tools();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				// Add-on tabs render themselves on pnscripts_omnibus_admin_tab_{tab}.
				do_action( 'pnscripts_omnibus_admin_tab_' . $tab );
		}
		echo '</div>';
	}

	/**
	 * Result notices after tool actions.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		if ( '' === $done ) {
			return;
		}
		$message = 'error' === $done
			? __( 'The action could not be started. Action Scheduler (part of WooCommerce) is not available.', 'pnscripts-price-history' )
			: __( 'Started in the background. Refresh this page to see the progress.', 'pnscripts-price-history' );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $done ? 'error' : 'success', esc_html( $message ) );
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$values = $this->plugin->settings->all();
		$name   = static fn ( string $key ): string => Settings::OPTION . '[' . $key . ']';

		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<h2>' . esc_html__( 'Rules', 'pnscripts-price-history' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pnscripts-omnibus-preset">' . esc_html__( 'Preset', 'pnscripts-price-history' ) . '</label></th><td>';
		printf( '<select id="pnscripts-omnibus-preset" name="%s">', esc_attr( $name( 'preset' ) ) );
		foreach ( Settings::preset_labels() as $id => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $id ), selected( $values['preset'], $id, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A preset fills the three rules below when you save. Presets are starting points, not legal advice: confirm the rules of the country you sell to. Choose "Custom rules" to set them yourself.', 'pnscripts-price-history' ) . '</p></td></tr>';

		$disabled = 'custom' !== $values['preset'];

		echo '<tr><th scope="row"><label for="pnscripts-omnibus-period">' . esc_html__( 'Period before the discount (days)', 'pnscripts-price-history' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pnscripts-omnibus-period" name="%1$s" value="%2$d" min="%3$d" max="%4$d" class="small-text" %5$s />',
			esc_attr( $name( 'period_days' ) ),
			(int) $values['period_days'],
			(int) ReferencePolicy::MIN_PERIOD_DAYS,
			(int) ReferencePolicy::MAX_PERIOD_DAYS,
			$disabled ? 'readonly' : ''
		);
		echo '<p class="description">' . esc_html__( 'At least 30 days. The period ends when the current discount started and begins at midnight that many days earlier. For progressive discounts it ends when the first discount started.', 'pnscripts-price-history' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Products on the market for less than the period', 'pnscripts-price-history' ) . '</th><td><fieldset>';
		foreach ( array(
			ReferencePolicy::NEW_PRODUCT_HIDE         => __( 'Hide the notice (strict)', 'pnscripts-price-history' ),
			ReferencePolicy::NEW_PRODUCT_SINCE_LAUNCH => __( 'Use the lowest price since the product was launched (only where your country allows a shorter period)', 'pnscripts-price-history' ),
		) as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s %4$s /> %5$s</label><br />',
				esc_attr( $name( 'new_product_mode' ) ),
				esc_attr( $value ),
				checked( $values['new_product_mode'], $value, false ),
				$disabled && $values['new_product_mode'] !== $value ? 'disabled' : '',
				esc_html( $label )
			);
		}
		echo '</fieldset></td></tr>';

		$this->checkbox_row(
			$name( 'perishable_exemption' ),
			__( 'Perishable goods', 'pnscripts-price-history' ),
			__( 'Do not show the notice for products (or categories) marked as perishable. Only where your country exempts perishable goods.', 'pnscripts-price-history' ),
			$values['perishable_exemption'],
			$disabled
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Display', 'pnscripts-price-history' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_row( $name( 'show_single' ), __( 'Product page', 'pnscripts-price-history' ), __( 'Show the notice under the price on product pages (classic templates and the Product Price block).', 'pnscripts-price-history' ), $values['show_single'] );
		$this->checkbox_row( $name( 'show_loop' ), __( 'Shop and category pages', 'pnscripts-price-history' ), __( 'Show the notice in product lists, related products and product blocks.', 'pnscripts-price-history' ), $values['show_loop'] );
		$this->checkbox_row( $name( 'show_variations' ), __( 'Variations', 'pnscripts-price-history' ), __( 'Show the notice for the selected variation of variable products.', 'pnscripts-price-history' ), $values['show_variations'] );
		$this->checkbox_row( $name( 'hide_when_unknown' ), __( 'Incomplete history', 'pnscripts-price-history' ), __( 'Hide the notice when the price history does not cover the whole period (recommended). When unchecked, the text below is shown instead.', 'pnscripts-price-history' ), $values['hide_when_unknown'] );

		$this->text_row( $name( 'notice_text' ), __( 'Notice text', 'pnscripts-price-history' ), $values['notice_text'], NoticeRenderer::default_text() );
		$this->text_row( $name( 'notice_text_short' ), __( 'Notice text (period since launch)', 'pnscripts-price-history' ), $values['notice_text_short'], NoticeRenderer::default_text_short() );
		$this->text_row( $name( 'unknown_text' ), __( 'Text when the history is incomplete', 'pnscripts-price-history' ), $values['unknown_text'], NoticeRenderer::default_unknown_text() );
		echo '<tr><th scope="row">' . esc_html__( 'Placeholders', 'pnscripts-price-history' ) . '</th><td><p class="description">';
		echo esc_html__( '{price} the lowest price, {days} the number of days, {date} the day the discount started. Leave a text empty to use the translated default.', 'pnscripts-price-history' );
		echo '</p><p class="description">';
		printf(
			/* translators: %s: shortcode */
			esc_html__( 'To place the notice elsewhere, use the shortcode %s.', 'pnscripts-price-history' ),
			'<code>[pnscripts_price_history]</code>'
		);
		echo '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Data', 'pnscripts-price-history' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pnscripts-omnibus-retention">' . esc_html__( 'Keep history for (days)', 'pnscripts-price-history' ) . '</label></th><td>';
		printf(
			'<input type="number" id="pnscripts-omnibus-retention" name="%1$s" value="%2$d" min="%3$d" max="3650" class="small-text" />',
			esc_attr( $name( 'retention_days' ) ),
			(int) $values['retention_days'],
			(int) Settings::MIN_RETENTION_DAYS
		);
		echo '<p class="description">' . esc_html__( 'Older prices are deleted daily, except the ones still needed for a running discount. Minimum 31 days.', 'pnscripts-price-history' ) . '</p></td></tr>';
		$this->checkbox_row(
			$name( 'trust_last_modified' ),
			__( 'First record', 'pnscripts-price-history' ),
			__( 'When recording starts for an existing product, assume its current price has applied since the product was last modified. Leave unchecked if other software changes prices without updating products (strict).', 'pnscripts-price-history' ),
			$values['trust_last_modified']
		);
		$this->checkbox_row(
			$name( 'delete_data_on_uninstall' ),
			__( 'Uninstall', 'pnscripts-price-history' ),
			__( 'Delete the price history and settings when the plugin is deleted.', 'pnscripts-price-history' ),
			$values['delete_data_on_uninstall']
		);
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Row label.
	 * @param string $description Checkbox text.
	 * @param bool   $checked     State.
	 * @param bool   $locked      Read-only (preset).
	 */
	private function checkbox_row( string $name, string $label, string $description, bool $checked, bool $locked = false ): void {
		$id = sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		printf(
			'<tr><th scope="row">%1$s</th><td><label for="%2$s"><input type="checkbox" id="%2$s" name="%3$s" value="1" %4$s %5$s /> %6$s</label>%7$s</td></tr>',
			esc_html( $label ),
			esc_attr( $id ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			$locked ? 'disabled' : '',
			esc_html( $description ),
			$locked && $checked ? sprintf( '<input type="hidden" name="%s" value="1" />', esc_attr( $name ) ) : ''
		);
	}

	/**
	 * Text row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $placeholder Default text.
	 */
	private function text_row( string $name, string $label, string $value, string $placeholder ): void {
		$id = sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="large-text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s" maxlength="300" /></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Coverage report tab: products and variations on sale with their reference status.
	 */
	private function render_report(): void {
		$ids = array_values( array_unique( array_map( 'intval', wc_get_product_ids_on_sale() ) ) );
		if ( array() !== $ids ) {
			// Variable and grouped parents have no own price; found with one query instead of loading every product.
			$parents = wc_get_products(
				array(
					'include' => $ids,
					'type'    => array( 'variable', 'grouped' ),
					'status'  => array_keys( get_post_statuses() ),
					'limit'   => -1,
					'return'  => 'ids',
				)
			);
			$ids     = array_values( array_diff( $ids, array_map( 'intval', is_array( $parents ) ? $parents : array() ) ) );
		}
		sort( $ids );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only.
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total = count( $ids );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );

		// Only the products of the current page are loaded.
		$slice = array();
		foreach ( array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof WC_Product && ! $product->is_type( array( 'variable', 'grouped' ) ) ) {
				$slice[] = $product;
			}
		}

		$results = array();
		foreach ( $slice as $product ) {
			$results[ $product->get_id() ] = $this->plugin->reference->for_product( $product );
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of products and variations */
				_n( '%d product or variation is on sale.', '%d products and variations are on sale.', $total, 'pnscripts-price-history' ),
				$total
			)
		) . ' ' . esc_html__( 'For each one: whether the lowest prior price is known and shown, and if not, why.', 'pnscripts-price-history' ) . '</p>';

		if ( 0 === $total ) {
			return;
		}

		echo '<table class="widefat striped pnscripts-omnibus-report"><thead><tr>';
		foreach ( array(
			__( 'Product', 'pnscripts-price-history' ),
			__( 'Current price', 'pnscripts-price-history' ),
			__( 'Lowest prior price', 'pnscripts-price-history' ),
			__( 'Discount started', 'pnscripts-price-history' ),
			__( 'Status', 'pnscripts-price-history' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $slice as $product ) {
			$result  = $results[ $product->get_id() ];
			$edit_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( (string) get_edit_post_link( $edit_id ) ),
				esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
			);
			printf( '<td>%s</td>', wp_kses_post( wc_price( (float) wc_get_price_to_display( $product ) ) ) );
			printf( '<td>%s</td>', $result->is_known() ? wp_kses_post( wc_price( $this->plugin->reference->display_price( $product, $result ) ) ) : '&mdash;' );
			printf( '<td>%s</td>', null !== $result->anchor ? esc_html( (string) wp_date( (string) get_option( 'date_format' ), $result->anchor ) ) : '&mdash;' );
			printf(
				'<td><span class="pnscripts-omnibus-status pnscripts-omnibus-status--%1$s">%2$s</span><br /><small>%3$s</small></td>',
				esc_attr( $result->status ),
				esc_html( Labels::status( $result->status ) ),
				esc_html( Labels::explain( $result ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'report' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Tools tab.
	 */
	private function render_tools(): void {
		$stats = $this->plugin->repository->stats();

		echo '<h2>' . esc_html__( 'Status', 'pnscripts-price-history' ) . '</h2>';
		$bytes = $this->plugin->repository->table_bytes();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of stored price records, 2: number of products and variations */
				__( '%1$d price records for %2$d products and variations.', 'pnscripts-price-history' ),
				$stats['rows'],
				$stats['products']
			)
		) . ' ' . esc_html(
			null !== $bytes
				? sprintf(
					/* translators: 1: table size such as "1 MB", 2: number of days */
					__( 'Stored in one table of its own (%1$s, no posts or post meta); history older than %2$d days is deleted automatically, except prices a running discount still needs.', 'pnscripts-price-history' ),
					size_format( $bytes ),
					$this->plugin->settings->int( 'retention_days' )
				)
				: sprintf(
					/* translators: %d: number of days */
					__( 'Stored in one table of its own (no posts or post meta); history older than %d days is deleted automatically, except prices a running discount still needs.', 'pnscripts-price-history' ),
					$this->plugin->settings->int( 'retention_days' )
				)
		) . '</p>';

		$status = get_option( Backfill::STATUS_OPTION, array() );
		if ( is_array( $status ) && isset( $status['mode'] ) ) {
			$finished = (int) ( $status['finished'] ?? 0 );
			echo '<p>' . esc_html(
				$finished > 0
					? sprintf(
						/* translators: 1: date and time, 2: number of products checked, 3: number of records added */
						__( 'Last catalogue check finished %1$s: %2$d products checked, %3$d records added.', 'pnscripts-price-history' ),
						(string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished ),
						(int) ( $status['processed'] ?? 0 ),
						(int) ( $status['recorded'] ?? 0 )
					)
					: sprintf(
						/* translators: %d: number of products checked so far */
						__( 'Catalogue check running: %d products checked so far.', 'pnscripts-price-history' ),
						(int) ( $status['processed'] ?? 0 )
					)
			) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Check the catalogue', 'pnscripts-price-history' ) . '</h2>';
		echo '<p>' . esc_html__( 'Records the current price of products that have no history yet, and marks products whose price changed without passing through WooCommerce. Runs in the background.', 'pnscripts-price-history' ) . '</p>';
		$this->tool_button( 'repair', '', __( 'Check the catalogue now', 'pnscripts-price-history' ) );

		echo '<h2>' . esc_html__( 'Import history from another plugin', 'pnscripts-price-history' ) . '</h2>';
		echo '<p>' . esc_html__( 'Reads (never changes) the price history saved by another plugin and adds the prices from before this plugin started recording each product. Only import from a plugin that was active until now; a plugin that was switched off long ago has gaps that cannot be detected. Running an import twice is safe.', 'pnscripts-price-history' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Plugin', 'pnscripts-price-history' ) . '</th><th scope="col">' . esc_html__( 'Data found', 'pnscripts-price-history' ) . '</th><th scope="col">' . esc_html__( 'Last import', 'pnscripts-price-history' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( Importers::labels() as $group => $label ) {
			$found  = $this->plugin->importers->count( $group );
			$status = ImportRunner::status( $group );
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $label ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $found ) ) );
			echo '<td>';
			if ( isset( $status['started'] ) ) {
				$finished = (int) ( $status['finished'] ?? 0 );
				echo esc_html(
					$finished > 0
						? sprintf(
							/* translators: 1: date and time, 2: imported records, 3: skipped entries */
							__( '%1$s: %2$d imported, %3$d skipped', 'pnscripts-price-history' ),
							(string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished ),
							(int) ( $status['imported'] ?? 0 ),
							(int) ( $status['skipped'] ?? 0 )
						)
						: __( 'Running…', 'pnscripts-price-history' )
				);
			} else {
				echo '&mdash;';
			}
			echo '</td><td>';
			if ( $found > 0 ) {
				$this->tool_button( 'import', $group, __( 'Import', 'pnscripts-price-history' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$this->render_records_import();

		echo '<h2>' . esc_html__( 'Background tasks', 'pnscripts-price-history' ) . '</h2>';
		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of pending background tasks */
					__( 'Pending tasks: %d.', 'pnscripts-price-history' ),
					Queue::pending( Backfill::HOOK ) + Queue::pending( ImportRunner::HOOK ) + Queue::pending( Retention::HOOK )
				)
			),
			esc_url(
				add_query_arg(
					array(
						'page'   => 'wc-status',
						'tab'    => 'action-scheduler',
						's'      => 'pnscripts_omnibus',
						'status' => 'pending',
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'View in WooCommerce → Status → Scheduled Actions', 'pnscripts-price-history' )
		);
	}

	/**
	 * "Prices from your own records" CSV upload and the result of the last upload.
	 */
	private function render_records_import(): void {
		echo '<h2 id="records">' . esc_html__( 'Add prices from your own records', 'pnscripts-price-history' ) . '</h2>';
		echo '<p>' . esc_html__( 'Products that were already on sale when this plugin started recording show no notice until the history covers the whole period. If your shop system, accounting or price lists show which prices applied before, upload them here: each line is the price that applied from a day until the next line of the same product. Lines dated after the plugin started recording a product are refused, so the recorded history always wins. You are responsible for these prices; keep the records they come from.', 'pnscripts-price-history' ) . '</p>';
		echo '<p>' . esc_html__( 'CSV columns: product ID or SKU, date (YYYY-MM-DD), regular price, sale price (empty when there was no sale). A header line is allowed. Example:', 'pnscripts-price-history' ) . '</p>';
		echo '<pre class="pnscripts-omnibus-csv-example">product,date,regular_price,sale_price' . "\n" . 'SKU-1001,2026-08-01,49.90,' . "\n" . 'SKU-1001,2026-09-20,49.90,39.90' . "\n" . '123,2026-07-15,19.00,</pre>';

		$result = get_transient( self::RESULT . get_current_user_id() );
		if ( is_array( $result ) && isset( $result['added'], $result['errors'] ) && is_array( $result['errors'] ) ) {
			delete_transient( self::RESULT . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p>',
				array() === $result['errors'] ? 'success' : 'warning',
				esc_html(
					sprintf(
						/* translators: 1: number of prices added, 2: number of lines not added */
						__( 'Prices added: %1$d; lines not added: %2$d.', 'pnscripts-price-history' ),
						(int) $result['added'],
						count( $result['errors'] )
					)
				)
			);
			if ( array() !== $result['errors'] ) {
				echo '<ul class="pnscripts-omnibus-csv-errors">';
				foreach ( array_slice( $result['errors'], 0, 50 ) as $error ) {
					printf(
						'<li>%s</li>',
						esc_html(
							sprintf(
								/* translators: 1: line number, 2: product ID or SKU, 3: reason */
								__( 'Line %1$d (%2$s): %3$s', 'pnscripts-price-history' ),
								(int) ( $error['line'] ?? 0 ),
								(string) ( $error['product'] ?? '' ),
								ManualRecords::message( (string) ( $error['error'] ?? '' ) )
							)
						)
					);
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		printf( '<form method="post" action="%s" enctype="multipart/form-data" class="pnscripts-omnibus-tool">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::RECORDS ) );
		wp_nonce_field( self::RECORDS );
		echo '<label for="pnscripts-omnibus-records-file" class="screen-reader-text">' . esc_html__( 'CSV file', 'pnscripts-price-history' ) . '</label>';
		echo '<input type="file" id="pnscripts-omnibus-records-file" name="records" accept=".csv,text/csv,text/plain" required /> ';
		submit_button( __( 'Upload and add prices', 'pnscripts-price-history' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Also on the product edit screen (one product) and with WP-CLI: wp pnscripts-price-history import-records file.csv', 'pnscripts-price-history' ) . '</p>';
	}

	/**
	 * Handle the CSV upload.
	 */
	public function handle_records(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'pnscripts-price-history' ), 403 );
		}
		check_admin_referer( self::RECORDS );

		$result = array(
			'added'  => 0,
			'errors' => array(),
		);
		$file   = isset( $_FILES['records']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['records']['tmp_name'] ) : '';
		$size   = isset( $_FILES['records']['size'] ) ? absint( $_FILES['records']['size'] ) : 0;
		if ( '' !== $file && is_uploaded_file( $file ) && $size > 0 && $size <= 2 * MB_IN_BYTES ) {
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the uploaded temporary file; it is not stored.
			$result  = $this->plugin->manual->import_csv( $content );
		} else {
			$result['errors'][] = array(
				'line'    => 0,
				'product' => '',
				'error'   => 'file',
			);
		}
		set_transient( self::RESULT . get_current_user_id(), $result, 300 );
		wp_safe_redirect( self::url( 'tools' ) . '#records' );
		exit;
	}

	/**
	 * Small POST form for a tool.
	 *
	 * @param string $tool  Tool id.
	 * @param string $group Import group.
	 * @param string $label Button label.
	 */
	private function tool_button( string $tool, string $group, string $label ): void {
		printf( '<form method="post" action="%s" class="pnscripts-omnibus-tool">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::TOOL ) );
		printf( '<input type="hidden" name="tool" value="%s" />', esc_attr( $tool ) );
		printf( '<input type="hidden" name="group" value="%s" />', esc_attr( $group ) );
		wp_nonce_field( self::TOOL . '_' . $tool );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle a tool POST.
	 */
	public function handle_tool(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'pnscripts-price-history' ), 403 );
		}
		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		check_admin_referer( self::TOOL . '_' . $tool );

		if ( ! Queue::available() ) {
			wp_safe_redirect( self::url( 'tools', array( 'done' => 'error' ) ) );
			exit;
		}

		if ( 'repair' === $tool ) {
			Backfill::start( Backfill::MODE_REPAIR );
		} elseif ( 'import' === $tool ) {
			$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
			if ( isset( Importers::GROUPS[ $group ] ) ) {
				ImportRunner::start( $group );
			}
		}
		wp_safe_redirect( self::url( 'tools', array( 'done' => $tool ) ) );
		exit;
	}
}
