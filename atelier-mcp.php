<?php
/**
 * Plugin Name:       Atelier MCP
 * Plugin URI:        https://github.com/clyde237/atelier-mcp
 * Description:       Expose des outils MCP (Model Context Protocol) pour construire et vérifier des pages Elementor et Gutenberg depuis Claude, ChatGPT et Antigravity. <a href="https://github.com/clyde237/atelier-mcp" target="_blank" rel="noopener noreferrer">Dépôt GitHub & Mises à jour</a>
 * Version:           0.4.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            clyde237
 * Author URI:        https://github.com/clyde237
 * License:           GPL-2.0-or-later
 * Text Domain:       atelier-mcp
 * Update URI:        https://github.com/clyde237/atelier-mcp
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

define( 'ATELIER_MCP_VERSION', '0.4.1' );
define( 'ATELIER_MCP_FILE', __FILE__ );
define( 'ATELIER_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once ATELIER_MCP_DIR . 'src/autoload.php';

\Atelier\Plugin::boot();
