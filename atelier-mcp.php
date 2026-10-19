<?php
/**
 * Plugin Name:       Atelier MCP
 * Description:       Expose des outils MCP (Model Context Protocol) pour construire et vérifier des pages Elementor depuis Claude.
 * Version:           0.4.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            clyde237
 * License:           GPL-2.0-or-later
 * Text Domain:       atelier-mcp
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

define( 'ATELIER_MCP_VERSION', '0.4.0' );
define( 'ATELIER_MCP_FILE', __FILE__ );
define( 'ATELIER_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once ATELIER_MCP_DIR . 'src/autoload.php';

\Atelier\Plugin::boot();
