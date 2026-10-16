<?php
/**
 * Plugin Name:          PN Scripts Price History for Discounts
 * Plugin URI:           https://pnscripts.com/marketplace/pnscripts-price-history
 * Description:          Records every product and variation price change and shows the lowest price in the 30 days before a discount (EU Price Indication Directive, Art. 6a). Shows nothing when the history is incomplete.
 * Version:              1.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 * Author:               PN Scripts
 * Author URI:           https://pnscripts.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          pnscripts-price-history
 * Domain Path:          /languages
 *
 * @package Pnscripts\Omnibus
 */

/*
 * Copyright (C) 2026 ПН СКРИПТС ЕООД (PN Scripts)
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'PNSCRIPTS_OMNIBUS_VERSION', '1.1.0' );
define( 'PNSCRIPTS_OMNIBUS_DB_VERSION', '1' );
define( 'PNSCRIPTS_OMNIBUS_FILE', __FILE__ );
define( 'PNSCRIPTS_OMNIBUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PNSCRIPTS_OMNIBUS_URL', plugin_dir_url( __FILE__ ) );
define( 'PNSCRIPTS_OMNIBUS_MIN_WC', '9.0' );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Pnscripts\\Omnibus\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = PNSCRIPTS_OMNIBUS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( \Pnscripts\Omnibus\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Pnscripts\Omnibus\Lifecycle::class, 'deactivate' ) );

add_action( 'before_woocommerce_init', array( \Pnscripts\Omnibus\Lifecycle::class, 'declare_compatibility' ) );
add_action( 'plugins_loaded', array( \Pnscripts\Omnibus\Plugin::class, 'boot' ), 20 );
