<?php
/**
 * WP-Gistpen
 *
 * A self-hosted alternative to putting your code snippets on Gist.
 *
 * @package   WP_Gistpen
 * @author    James DiGioia <jamesorodig@gmail.com>
 * @license   GPL-2.0+
 * @link      http://jamesdigioia.com/wp-gistpen/
 * @copyright 2018 James DiGioia
 *
 * @wordpress-plugin
 * Plugin Name:       WP-Gistpen
 * Plugin URI:        http://www.jamesdigioia.com/wp-gistpen/
 * Description:       A self-hosted alternative to putting your code snippets on Gist.
 * Version:           2.0.0-alpha.3
 * Author:            James DiGioia
 * Author URI:        http://www.jamesdigioia.com/
 * Text Domain:       wp-gistpen
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/intraxia/wp-gistpen
 */

// Protect File.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Register autoloader.
$autoload = __DIR__ . '/lib/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

// Validate PHP Version.
$update_php = new WPUpdatePhp( '5.6.0' );
if ( ! $update_php->does_it_meet_required_php_version( PHP_VERSION ) ) {
	return;
}

// Boot!
Intraxia\Gistpen\boot();
