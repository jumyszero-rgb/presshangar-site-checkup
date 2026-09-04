<?php
/**
 * Uninstall routine: removes PressHangar Site Checkup's own two options and nothing
 * else. PressHangar Site Checkup never wrote anything belonging to any other plugin,
 * theme, post, or option, so there is nothing else to clean up.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress defines WP_UNINSTALL_PLUGIN when this file is loaded as part of
// a proper plugin uninstall; bail if accessed any other way.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'phcheckup_settings' );
delete_option( 'phcheckup_last_scan' );
