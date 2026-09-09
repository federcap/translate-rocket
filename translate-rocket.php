<?php
/**
 * Plugin Name:       TranslateRocket – Free Multilingual Translation, Unlimited Languages
 * Plugin URI:        https://translaterocket.com
 * Description:       Free, AI-powered translation for WordPress. Translate your whole site — visible text, image alt tags, titles, placeholders and SEO meta — using your own AI keys (OpenAI, Anthropic, Gemini, DeepL, Google). Untranslated strings are detected automatically as visitors browse.
 * Version:           1.1.4
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            federico_dev
 * Author URI:        https://federicocaputo.dev
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       translate-rocket
 * Domain Path:       /languages
 *
 * TranslateRocket - AI-powered translation for WordPress.
 * Copyright (C) 2026  federico_dev
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'TRROCKET_VERSION', '1.1.4' );
define( 'TRROCKET_FILE', __FILE__ );
define( 'TRROCKET_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRROCKET_URL', plugin_dir_url( __FILE__ ) );
define( 'TRROCKET_BASENAME', plugin_basename( __FILE__ ) );

/*
 * ---------------------------------------------------------------------------
 * PSR-4 autoloader: maps the TranslateRocket\ namespace to the /src folder.
 * e.g. TranslateRocket\Admin\Admin  ->  src/Admin/Admin.php
 * ---------------------------------------------------------------------------
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'TranslateRocket\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$relative = substr( $class, $len );
		$file     = TRROCKET_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Activation / deactivation hooks
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Boot the plugin once all other plugins are loaded.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	function () {
		Plugin::instance()->boot();
	}
);

/*
 * ---------------------------------------------------------------------------
 * Translations.
 * ---------------------------------------------------------------------------
 * WordPress only looks inside wp-content/languages/plugins for a plugin hosted
 * on WordPress.org, and that folder is filled by a language pack built from
 * translate.wordpress.org. Until translations there are approved by an editor
 * no pack exists, and the catalogues shipped in /languages are never read:
 * the plugin's own folder is not searched unless it is registered here.
 * Registering it means the bundled translations work now, and a language pack
 * still wins over them the day one is published (WP_LANG_DIR is checked first).
 * On 'init' because loading any earlier is flagged by core since 6.7.
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'translate-rocket', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	0
);
