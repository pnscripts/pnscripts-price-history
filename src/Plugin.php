<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package Pnscripts\Omnibus
 */

declare(strict_types=1);

namespace Pnscripts\Omnibus;

use Pnscripts\Omnibus\Admin\CategoryFields;
use Pnscripts\Omnibus\Admin\ProductPanel;
use Pnscripts\Omnibus\Admin\SettingsPage;
use Pnscripts\Omnibus\Capture\PriceRecorder;
use Pnscripts\Omnibus\Capture\SourceDetector;
use Pnscripts\Omnibus\Capture\TaxWatcher;
use Pnscripts\Omnibus\Cli\Command;
use Pnscripts\Omnibus\Display\NoticeRenderer;
use Pnscripts\Omnibus\Display\PriceDisplay;
use Pnscripts\Omnibus\Display\Shortcode;
use Pnscripts\Omnibus\Domain\Clock;
use Pnscripts\Omnibus\Domain\FixedClock;
use Pnscripts\Omnibus\Domain\SystemClock;
use Pnscripts\Omnibus\Import\ImportRunner;
use Pnscripts\Omnibus\Import\Importers;
use Pnscripts\Omnibus\Jobs\Backfill;
use Pnscripts\Omnibus\Jobs\Retention;
use Pnscripts\Omnibus\Licensing\FreeLicense;
use Pnscripts\Omnibus\Licensing\LicenseInterface;
use Pnscripts\Omnibus\Records\ManualRecords;
use Pnscripts\Omnibus\Reference\ReferenceService;
use Pnscripts\Omnibus\Storage\HistoryRepository;
use Pnscripts\Omnibus\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services. Access from add-ons: the pnscripts_omnibus_loaded action passes the instance.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service.
	 *
	 * @var Settings
	 */
	public readonly Settings $settings;

	/**
	 * Service.
	 *
	 * @var HistoryRepository
	 */
	public readonly HistoryRepository $repository;

	/**
	 * Service.
	 *
	 * @var Clock
	 */
	public readonly Clock $clock;

	/**
	 * Service.
	 *
	 * @var PriceRecorder
	 */
	public readonly PriceRecorder $recorder;

	/**
	 * Service.
	 *
	 * @var ReferenceService
	 */
	public readonly ReferenceService $reference;

	/**
	 * Service.
	 *
	 * @var NoticeRenderer
	 */
	public readonly NoticeRenderer $renderer;

	/**
	 * Service.
	 *
	 * @var Backfill
	 */
	public readonly Backfill $backfill;

	/**
	 * Service.
	 *
	 * @var Retention
	 */
	public readonly Retention $retention;

	/**
	 * Service.
	 *
	 * @var Importers
	 */
	public readonly Importers $importers;

	/**
	 * Service.
	 *
	 * @var ImportRunner
	 */
	public readonly ImportRunner $import_runner;

	/**
	 * Service.
	 *
	 * @var ManualRecords
	 */
	public readonly ManualRecords $manual;

	/**
	 * Build services.
	 */
	private function __construct() {
		$this->settings      = new Settings();
		$this->repository    = new HistoryRepository();
		$this->clock         = self::make_clock();
		$this->recorder      = new PriceRecorder( $this->repository, $this->clock, new SourceDetector() );
		$this->reference     = new ReferenceService( $this->repository, $this->settings, $this->clock );
		$this->renderer      = new NoticeRenderer( $this->settings, $this->reference );
		$this->backfill      = new Backfill( $this->recorder, $this->repository, $this->settings, $this->clock );
		$this->retention     = new Retention( $this->repository, $this->settings, $this->clock );
		$this->importers     = new Importers();
		$this->import_runner = new ImportRunner( $this->importers, $this->repository );
		$this->manual        = new ManualRecords( $this->repository, $this->clock );
	}

	/**
	 * Instance (null before boot or when WooCommerce is missing).
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Boot on plugins_loaded.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		if ( ! self::woocommerce_ready() ) {
			add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			return;
		}

		self::$instance = new self();
		self::$instance->register();

		/**
		 * Fires when the plugin is ready. Add-ons receive the service container.
		 *
		 * @param Plugin $plugin Plugin.
		 */
		do_action( 'pnscripts_omnibus_loaded', self::$instance );
	}

	/**
	 * Register hooks.
	 */
	private function register(): void {
		add_action( 'init', array( $this, 'on_init' ), 5 );
		add_action( 'admin_init', array( Lifecycle::class, 'maybe_schedule' ) );
		add_action( 'action_scheduler_init', array( $this, 'maybe_schedule_in_cron' ) );

		$this->recorder->register();
		( new TaxWatcher() )->register();
		$this->backfill->register();
		$this->retention->register();
		$this->import_runner->register();

		( new PriceDisplay( $this->settings, $this->renderer ) )->register();
		( new Shortcode( $this->renderer ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new ProductPanel( $this ) )->register();
			( new CategoryFields() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'pnscripts-price-history', new Command( $this ) );
		}
	}

	/**
	 * Load bundled translations and make sure the table exists (also on network sites).
	 */
	public function on_init(): void {
		self::load_bundled_translations();
		if ( (string) get_option( Schema::VERSION_OPTION, '' ) !== PNSCRIPTS_OMNIBUS_DB_VERSION ) {
			Lifecycle::install_site();
		}
	}

	/**
	 * Bundled translations (bg_BG, pl_PL, de_DE) are used only when no language pack from
	 * translate.wordpress.org is installed, so community translations always win.
	 */
	private static function load_bundled_translations(): void {
		$locale   = determine_locale();
		$official = WP_LANG_DIR . '/plugins/pnscripts-price-history-' . $locale;
		if ( is_readable( $official . '.mo' ) || is_readable( $official . '.l10n.php' ) ) {
			return;
		}
		$bundled = PNSCRIPTS_OMNIBUS_DIR . 'languages/pnscripts-price-history-' . $locale . '.mo';
		if ( is_readable( $bundled ) ) {
			load_textdomain( 'pnscripts-price-history', $bundled, $locale );
		}
	}

	/**
	 * Schedule pending work from cron requests too (shops where nobody opens wp-admin).
	 */
	public function maybe_schedule_in_cron(): void {
		if ( wp_doing_cron() ) {
			Lifecycle::maybe_schedule();
		}
	}

	/**
	 * Active licence implementation (free no-op unless an add-on provides one).
	 */
	public function license(): LicenseInterface {
		$license = apply_filters( 'pnscripts_omnibus_license', null );
		return $license instanceof LicenseInterface ? $license : new FreeLicense();
	}

	/**
	 * Clock, overridable with the pnscripts_omnibus_now filter (tests and demos).
	 */
	private static function make_clock(): Clock {
		$now = apply_filters( 'pnscripts_omnibus_now', null );
		return is_int( $now ) && $now > 0 ? new FixedClock( $now ) : new SystemClock();
	}

	/**
	 * WooCommerce active in a supported version.
	 */
	private static function woocommerce_ready(): bool {
		return class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) && version_compare( (string) WC_VERSION, PNSCRIPTS_OMNIBUS_MIN_WC, '>=' );
	}

	/**
	 * Admin notice when WooCommerce is missing or too old.
	 */
	public static function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum WooCommerce version */
					__( 'PN Scripts Price History needs WooCommerce %s or newer to be active.', 'pnscripts-price-history' ),
					PNSCRIPTS_OMNIBUS_MIN_WC
				)
			)
		);
	}
}
