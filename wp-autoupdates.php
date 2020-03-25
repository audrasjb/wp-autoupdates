<?php
/*
Plugin Name: WordPress Auto-updates
Plugin URI: https://wordpress.org/plugins/wp-autoupdates
Description: A feature plugin to integrate Plugins & Themes automatic updates in WordPress Core.
Version: 0.3.0
Requires at least: 5.3
Requires PHP: 5.6
Tested up to: 5.4
Author: The WordPress Team
Author URI: https://wordpress.org
Contributors: wordpressdotorg, audrasjb, whodunitagency, pbiron, xkon, karmatosed, mapk
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-autoupdates
*/

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}


/**
 * Enqueue styles and scripts
 */
function wp_autoupdates_enqueues( $hook ) {
	if ( ! in_array( $hook, array( 'plugins.php', 'themes.php', 'update-core.php' ) ) ) {
		return;
	}
	wp_register_style( 'wp-autoupdates', plugin_dir_url( __FILE__ ) . 'css/wp-autoupdates.css', array() );
	wp_enqueue_style( 'wp-autoupdates' );

	// Update core screen JS hack (due to lack of filters)
	if ( 'update-core.php' === $hook ) {
		$script = 'jQuery( document ).ready(function() {';

		if ( wp_autoupdates_is_themes_auto_update_enabled() ) {
			$wp_auto_update_themes = get_site_option( 'wp_auto_update_themes', array() );

			$update_message = wp_autoupdates_get_update_message();
			foreach ( $wp_auto_update_themes as $theme ) {
				$autoupdate_text = ' <span class="plugin-autoupdate-enabled"><span class="dashicons dashicons-update" aria-hidden="true"></span> ';
				$autoupdate_text .= $update_message;
				$autoupdate_text .= '</span> ';
				$script .= 'jQuery(".check-column input[value=\'' . $theme . '\']").closest("tr").find(".plugin-title > p").append(\'' . $autoupdate_text . '\');';
			}
		}

		if ( wp_autoupdates_is_plugins_auto_update_enabled() ) {
			$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );

			$update_message = wp_autoupdates_get_update_message();
			foreach ( $wp_auto_update_plugins as $plugin ) {
				$autoupdate_text = ' <span class="plugin-autoupdate-enabled"><span class="dashicons dashicons-update" aria-hidden="true"></span> ';
				$autoupdate_text .= $update_message;
				$autoupdate_text .= '</span> ';
				$script .= 'jQuery(".check-column input[value=\'' . $plugin . '\']").closest("tr").find(".plugin-title > p").append(\'' . $autoupdate_text . '\');';
			}
		}
		$script .= '});';
		wp_add_inline_script( 'jquery', $script );
	}

	// When manually updating a plugin the 'time until next update' text needs to be hidden.
	// Doesn't need to be done on the update-core.php page since that page refreshes after an update.
	if ( 'plugins.php' === $hook ) {
		$script = 'jQuery( document ).ready(function() {
			jQuery( ".update-link" ).click( function() {
				var plugin = jQuery( this ).closest("tr").data("plugin");
				var plugin_row = jQuery( "tr.update[data-plugin=\'" + plugin + "\']" );
				var plugin_auto_update_time_text = plugin_row.find("span.plugin-autoupdate-time");
				plugin_auto_update_time_text.remove();
			});
		});';
		wp_add_inline_script( 'jquery', $script );
	}

	if ( 'themes.php' === $hook ) {
		if ( wp_autoupdates_is_themes_auto_update_enabled() ) {
			$script = 'jQuery( document ).ready( function() {';

			/* translators: %s: Theme name. */
			$aria_label_enable  = sprintf( _x( 'Enable automatic update for %s', 'theme' ), '{{ data.name }}' );
 			$aria_label_disable = sprintf( _x( 'Disable automatic update for %s', 'theme' ), '{{ data.name }}' );

			// Put the enable/disable link below the author and before the update box.
			$autoupdate_text = '<p class="theme-autoupdate"> <# if ( data.autoupdate ) { #>';
			$autoupdate_text .= '<span class="theme-autoupdate-disabled">';
			$autoupdate_text .= '<a href="{{{ data.actions.autoupdate }}}" aria-label="' . $aria_label_disable . '"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . __( 'Disable automatic updates' ) . '</a>';
			$autoupdate_text .= '</span>';
			$autoupdate_text .= '<# } else { #>';
			$autoupdate_text .= '<span class="theme-autoupdate-enabled">';
			$autoupdate_text .= '<a href="{{{ data.actions.autoupdate }}}" aria-label="' . $aria_label_enable . '"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . __( 'Enable automatic updates' ) . '</a>';
			$autoupdate_text .= '</span>';
			$autoupdate_text .= '<# } #> </p>';

			$script .= '	const theme_template_single = jQuery( "#tmpl-theme-single" );

				// Pull template into new html element, manipulate, then put back.
				// Props https://stackoverflow.com/a/42248980.
				function insert_into_template(positioning_text, added_text, insert_before) {
					var template_text = theme_template_single.text();
					var position = template_text.search(positioning_text);
					if ( -1 !== position ) {
						if ( true !== insert_before ) {
							position += positioning_text.length;
						}

						const new_template_text = template_text.substr(0, position) + added_text + template_text.substr(position);
						theme_template_single.text( new_template_text );
					}
				}

				const position_beginning_of_update_box = "<# if \\\\( data.hasUpdate \\\\) { #>";
				insert_into_template(position_beginning_of_update_box, "' . str_replace('"', '\"', $autoupdate_text) . '", true);
			';

			// Put the time until next update within the data.hasUpdate block.
			$update_message = wp_autoupdates_get_update_message();
			$autoupdate_time_text = '<# if ( data.autoupdate ) { #>';
			$autoupdate_time_text .= '<p class="theme-autoupdate-enabled">' . $update_message . '</p>';
			$autoupdate_time_text .= '<# } #>';

			$script .= '
				const position_data_update = "{{{ data.update }}}";
				insert_into_template(position_data_update, "' . str_replace('"', '\"', $autoupdate_time_text) . '", false);
			';

			$script .= '});';
			wp_add_inline_script( 'jquery', $script );
		}
	}
}
add_action( 'admin_enqueue_scripts', 'wp_autoupdates_enqueues' );


/**
 * Filter the themes prepared for JavaScript, for themes.php.
 */
function wp_autoupdates_prepare_themes_for_js( $prepared_themes ) {
	$wp_auto_update_themes = get_option( 'wp_auto_update_themes', array() );
	foreach( $prepared_themes as $theme ) {
		// Set extra data for use in the template.
		$slug = $theme['id'];
		$encoded_slug = urlencode( $slug );

		$theme['autoupdate'] = in_array( $slug, $wp_auto_update_themes, true );
		$theme['actions']['autoupdate'] = current_user_can( 'update_themes' ) ? wp_nonce_url( admin_url( 'themes.php?action=autoupdate&amp;theme=' . $encoded_slug ), 'autoupdate-theme_' . $slug ) : null;

		$prepared_themes[ $slug ] = $theme;
	}

	return $prepared_themes;
}
add_action( 'wp_prepare_themes_for_js', 'wp_autoupdates_prepare_themes_for_js' );


/**
 * Checks whether plugins manual auto-update is enabled.
 */
function wp_autoupdates_is_plugins_auto_update_enabled() {
	$enabled = ! defined( 'WP_DISABLE_PLUGINS_AUTO_UPDATE' ) || ! WP_DISABLE_PLUGINS_AUTO_UPDATE;

	/**
	 * Filters whether plugins manual auto-update is enabled.
	 *
	 * @param bool $enabled True if plugins auto-update is enabled, false otherwise.
	 */
	return apply_filters( 'wp_plugins_auto_update_enabled', $enabled );
}


/**
 * Checks whether themes manual auto-update is enabled.
 */
function wp_autoupdates_is_themes_auto_update_enabled() {
	$enabled = ! defined( 'WP_DISABLE_THEMES_AUTO_UPDATE' ) || ! WP_DISABLE_THEMES_AUTO_UPDATE;
	
	/**
	 * Filters whether themes manual auto-update is enabled.
	 *
	 * @param bool $enabled True if themes auto-update is enabled, false otherwise.
	 */
	return apply_filters( 'wp_themes_auto_update_enabled', $enabled );
}


/**
 * Autoupdate selected plugins.
 */
function wp_autoupdates_selected_plugins( $update, $item ) {
	$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );
	if ( in_array( $item->plugin, $wp_auto_update_plugins, true ) && wp_autoupdates_is_plugins_auto_update_enabled() ) {
		return true;
	} else {
		return $update;
	}
}
add_filter( 'auto_update_plugin', 'wp_autoupdates_selected_plugins', 10, 2 );


/**
 * Autoupdate selected themes.
 */
function wp_autoupdates_selected_themes( $update, $item ) {
	$wp_auto_update_themes = get_site_option( 'wp_auto_update_themes', array() );
	if ( in_array( $item->theme, $wp_auto_update_themes, true ) && wp_autoupdates_is_themes_auto_update_enabled() ) {
		return true;
	} else {
		return $update;
	}
}
add_filter( 'auto_update_theme', 'wp_autoupdates_selected_themes', 10, 2 );


/**
 * Add autoupdate column to plugins screen.
 */
function wp_autoupdates_add_plugins_autoupdates_column( $columns ) {
	if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
		return $columns;
	}
	if ( ! isset( $_GET['plugin_status'] ) || ( 'mustuse' !== $_GET['plugin_status'] && 'dropins' !== $_GET['plugin_status'] ) ) {
		$columns['autoupdates_column'] = __( 'Automatic updates', 'wp-autoupdates' );
	}
	return $columns;
}
add_filter( is_multisite() ? 'manage_plugins-network_columns' : 'manage_plugins_columns', 'wp_autoupdates_add_plugins_autoupdates_column' );

/**
 * Render autoupdate column’s content.
 */
function wp_autoupdates_add_plugins_autoupdates_column_content( $column_name, $plugin_file, $plugin_data ) {
	if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
		return;
	}

	if ( 'autoupdates_column' !== $column_name ) {
		return;
	}

	$plugins = get_plugins();
	$plugins_updates = get_site_transient( 'update_plugins' );
	$page = isset( $_GET['paged'] ) && ! empty( $_GET['paged'] ) ? wp_unslash( esc_html( $_GET['paged'] ) ) : '';
	$plugin_status = isset( $_GET['plugin_status'] ) && ! empty( $_GET['plugin_status'] ) ? wp_unslash( esc_html( $_GET['plugin_status'] ) ) : '';

	if ( wp_autoupdates_is_plugins_auto_update_enabled() && wp_autoupdates_plugin_allows_auto_update( $plugin_file ) ) {
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return;
		}

		$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );

		if ( in_array( $plugin_file, $wp_auto_update_plugins, true ) ) {
			$aria_label = esc_attr(
				sprintf(
					/* translators: Plugin name. */
					_x( 'Disable automatic updates for %s', 'plugin', 'wp-autoupdates' ),
					esc_html( $plugins[ $plugin_file ]['Name'] )
				)
			);
			echo '<p>';
			echo '<span class="plugin-autoupdate-enabled">' . __( 'Auto-updates enabled', 'wp-autoupdates' ) . '</span>';
			echo '<br />';

			$update_message = wp_autoupdates_get_update_message();
			if ( isset( $plugins_updates->response[$plugin_file] ) ) {
				echo '<span class="plugin-autoupdate-time">';
				echo $update_message;
				echo '<br />';
				echo '</span>';
			}
			if ( current_user_can( 'update_plugins', $plugin_file ) ) {
				echo sprintf(
					'<a href="%s" class="plugin-autoupdate-disable" aria-label="%s">%s</a>',
					wp_nonce_url( 'plugins.php?action=autoupdate&amp;plugin=' . urlencode( $plugin_file ) . '&amp;paged=' . $page . '&amp;plugin_status=' . $plugin_status, 'autoupdate-plugin_' . $plugin_file ),
					$aria_label,
					__( 'Disable', 'wp-autoupdates' )
				);
			}
			echo '</p>';
		} else {
			if ( current_user_can( 'update_plugins', $plugin_file ) ) {
				$aria_label = esc_attr(
					sprintf(
						/* translators: Plugin name. */
						_x( 'Enable automatic updates for %s', 'plugin', 'wp-autoupdates' ),
						esc_html( $plugins[ $plugin_file ]['Name'] )
					)
				);
				echo '<p class="plugin-autoupdate-disabled">';
				echo sprintf(
					'<a href="%s" class="edit" aria-label="%s"><span class="dashicons dashicons-update" aria-hidden="true"></span> %s</a>',
					wp_nonce_url( 'plugins.php?action=autoupdate&amp;plugin=' . urlencode( $plugin_file ) . '&amp;paged=' . $page . '&amp;plugin_status=' . $plugin_status, 'autoupdate-plugin_' . $plugin_file ),
					$aria_label,
					__( 'Enable', 'wp-autoupdates' )
				);
				echo '</p>';
			}
		}
	}
}
add_action( 'manage_plugins_custom_column' , 'wp_autoupdates_add_plugins_autoupdates_column_content', 10, 3 );


/**
 * Add plugins autoupdates bulk actions
 */
function wp_autoupdates_plugins_bulk_actions( $actions ) {
    $actions['enable-autoupdate-selected']  = __( 'Enable auto-updates', 'wp-autoupdates' );
    $actions['disable-autoupdate-selected'] = __( 'Disable auto-updates', 'wp-autoupdates' );
    return $actions;
}
add_action( 'bulk_actions-plugins', 'wp_autoupdates_plugins_bulk_actions' );
add_action( 'bulk_actions-plugins-network', 'wp_autoupdates_plugins_bulk_actions' );


/**
 * Handles auto-updates enabling for plugins
 */
function wp_autoupdates_plugins_enabler() {
	$action = isset( $_GET['action'] ) && ! empty( esc_html( $_GET['action'] ) ) ? wp_unslash( esc_html( $_GET['action'] ) ) : '';
	if ( 'autoupdate' === $action ) {
		if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
			wp_die( __( 'Sorry, you are not allowed to enable plugins automatic updates.', 'wp-autoupdates' ) );
		}

		if ( is_multisite() && ! is_network_admin() ) {
			wp_die( __( 'Please connect to your network admin to manage plugins automatic updates.', 'wp-autoupdates' ) );
		}

		$plugin = ! empty( esc_html( $_GET['plugin'] ) ) ? wp_unslash( esc_html( $_GET['plugin'] ) ) : '';
		$page   = isset( $_GET['paged'] ) && ! empty( esc_html( $_GET['paged'] ) ) ? wp_unslash( esc_html( $_GET['paged'] ) ) : '';
		$status = isset( $_GET['plugin_status'] ) && ! empty( esc_html( $_GET['plugin_status'] ) ) ? wp_unslash( esc_html( $_GET['plugin_status'] ) ) : '';
		$s      = isset( $_GET['s'] ) && ! empty( esc_html( $_GET['s'] ) ) ? wp_unslash( esc_html( $_GET['s'] ) ) : '';

		if ( empty( $plugin ) ) {
			wp_redirect( self_admin_url( "plugins.php?plugin_status=$status&paged=$page&s=$s" ) );
			exit;
		}

		check_admin_referer( 'autoupdate-plugin_' . $plugin );
		$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );

		if ( in_array( $plugin, $wp_auto_update_plugins, true ) ) {
			$wp_auto_update_plugins = array_diff( $wp_auto_update_plugins, array( $plugin ) );
			$action_type = 'disable-autoupdate=true';
		} else {
			array_push( $wp_auto_update_plugins, $plugin );
			$action_type = 'enable-autoupdate=true';
		}
		update_site_option( 'wp_auto_update_plugins', $wp_auto_update_plugins );
		wp_redirect( self_admin_url( "plugins.php?$action_type&plugin_status=$status&paged=$page&s=$s" ) );
		exit;
	}
}


/**
 * Handles auto-updates enabling for themes
 */
function wp_autoupdates_themes_enabler() {
	$action = isset( $_GET['action'] ) && ! empty( esc_html( $_GET['action'] ) ) ? wp_unslash( esc_html( $_GET['action'] ) ) : '';
	if ( 'autoupdate' === $action ) {
		if ( ! current_user_can( 'update_themes' ) || ! wp_autoupdates_is_themes_auto_update_enabled() ) {
			wp_die( __( 'Sorry, you are not allowed to enable themes automatic updates.', 'wp-autoupdates' ) );
		}

		if ( is_multisite() && ! is_network_admin() ) {
			wp_die( __( 'Please connect to your network admin to manage themes automatic updates.', 'wp-autoupdates' ) );
		}

		$theme = ! empty( esc_html( $_GET['theme'] ) ) ? wp_unslash( esc_html( $_GET['theme'] ) ) : '';
		if ( empty( $theme ) ) {
			wp_redirect( self_admin_url( 'themes.php' ) );
			exit;
		}

		check_admin_referer( 'autoupdate-theme_' . $theme );
		$wp_auto_update_themes = get_site_option( 'wp_auto_update_themes', array() );

		if ( in_array( $theme, $wp_auto_update_themes, true ) ) {
			$wp_auto_update_themes = array_diff( $wp_auto_update_themes, array( $theme ) );
			$action_type = 'disable-autoupdate=true';
		} else {
			array_push( $wp_auto_update_themes, $theme );
			$action_type = 'enable-autoupdate=true';
		}

		update_site_option( 'wp_auto_update_themes', $wp_auto_update_themes );
		wp_redirect( self_admin_url( "themes.php?$action_type" ) );
		exit;
	}
}


/**
 * Handle autoupdates enabling
 */
function wp_autoupdates_enabler() {
	$pagenow = $GLOBALS['pagenow'];
	if ( 'plugins.php' === $pagenow ) {
		wp_autoupdates_plugins_enabler();
	}
	else if ( 'themes.php' === $pagenow ) {
		wp_autoupdates_themes_enabler();
	}
}
add_action( 'admin_init', 'wp_autoupdates_enabler' );


/**
 * Handle plugins autoupdates bulk actions
 */
function wp_autoupdates_plugins_bulk_actions_handle( $redirect_to, $doaction, $items ) {
	if ( 'enable-autoupdate-selected' === $doaction ) {
		if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
			wp_die( __( 'Sorry, you are not allowed to enable plugins automatic updates.', 'wp-autoupdates' ) );
		}

		if ( is_multisite() && ! is_network_admin() ) {
			wp_die( __( 'Please connect to your network admin to manage plugins automatic updates.', 'wp-autoupdates' ) );
		}

		check_admin_referer( 'bulk-plugins' );

		$plugins = ! empty( $items ) ? (array) wp_unslash( $items ) : array();
		$page    = isset( $_GET['paged'] ) && ! empty( esc_html( $_GET['paged'] ) ) ? wp_unslash( esc_html( $_GET['paged'] ) ) : '';
		$status  = isset( $_GET['plugin_status'] ) && ! empty( esc_html( $_GET['plugin_status'] ) ) ? wp_unslash( esc_html( $_GET['plugin_status'] ) ) : '';
		$s       = isset( $_GET['s'] ) && ! empty( esc_html( $_GET['s'] ) ) ? wp_unslash( esc_html( $_GET['s'] ) ) : '';

		if ( empty( $plugins ) ) {
			$redirect_to = self_admin_url( "plugins.php?plugin_status=$status&paged=$page&s=$s" );
			return $redirect_to;
		}

		// Filter can be used to remove plugins from being enabled via bulk action.
		$plugins = apply_filters( 'wp_plugin_bulk_enabled_autoupdate', $plugins );

		$previous_autoupdated_plugins = get_site_option( 'wp_auto_update_plugins', array() );

		$new_autoupdated_plugins = array_merge( $previous_autoupdated_plugins, $plugins );
		$new_autoupdated_plugins = array_unique( $new_autoupdated_plugins );

		update_site_option( 'wp_auto_update_plugins', $new_autoupdated_plugins );

		$redirect_to = self_admin_url( "plugins.php?enable-autoupdate=true&plugin_status=$status&paged=$page&s=$s" );
		return $redirect_to;
	}

	if ( 'disable-autoupdate-selected' === $doaction ) {
		if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
			wp_die( __( 'Sorry, you are not allowed to enable plugins automatic updates.', 'wp-autoupdates' ) );
		}

		if ( is_multisite() && ! is_network_admin() ) {
			wp_die( __( 'Please connect to your network admin to manage plugins automatic updates.', 'wp-autoupdates' ) );
		}

		check_admin_referer( 'bulk-plugins' );

		$plugins = ! empty( $items ) ? (array) wp_unslash( $items ) : array();
		$page    = isset( $_GET['paged'] ) && ! empty( esc_html( $_GET['paged'] ) ) ? wp_unslash( esc_html( $_GET['paged'] ) ) : '';
		$status  = isset( $_GET['plugin_status'] ) && ! empty( esc_html( $_GET['plugin_status'] ) ) ? wp_unslash( esc_html( $_GET['plugin_status'] ) ) : '';
		$s       = isset( $_GET['s'] ) && ! empty( esc_html( $_GET['s'] ) ) ? wp_unslash( esc_html( $_GET['s'] ) ) : '';

		if ( empty( $plugins ) ) {
			$redirect_to = self_admin_url( "plugins.php?plugin_status=$status&paged=$page&s=$s" );
			return $redirect_to;
		}

		// Filter can be used to remove plugins from being disabled via bulk action.
		$plugins = apply_filters( 'wp_plugin_bulk_disabled_autoupdate', $plugins );

		$previous_autoupdated_plugins = get_site_option( 'wp_auto_update_plugins', array() );

		$new_autoupdated_plugins = array_diff( $previous_autoupdated_plugins, $plugins );
		$new_autoupdated_plugins = array_unique( $new_autoupdated_plugins );

		update_site_option( 'wp_auto_update_plugins', $new_autoupdated_plugins );

		$redirect_to = self_admin_url( "plugins.php?disable-autoupdate=true&plugin_status=$status&paged=$page&s=$s" );
		return $redirect_to;
	}

}
add_action( 'handle_bulk_actions-plugins', 'wp_autoupdates_plugins_bulk_actions_handle', 10, 3 );
add_action( 'handle_bulk_actions-plugins-network', 'wp_autoupdates_plugins_bulk_actions_handle', 10, 3 );


/**
 * Handle cleanup when plugin deleted
 */
function wp_autoupdates_plugin_deleted( $plugin_file, $deleted ) {
	// Do nothing if the plugin wasn't deleted
	if ( ! $deleted ) {
		return;
	}

	// Remove settings
	$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );
	if ( in_array( $plugin_file, $wp_auto_update_plugins, true ) ) {
		$wp_auto_update_plugins = array_diff( $wp_auto_update_plugins, array( $plugin_file ) );
		update_site_option( 'wp_auto_update_plugins', $wp_auto_update_plugins );
	}
}
add_action( 'deleted_plugin', 'wp_autoupdates_plugin_deleted', 10, 2 );


/**
 * Auto-update notices for plugins
 */
function wp_autoupdates_plugins_notices() {
	if ( isset( $_GET['enable-autoupdate'] ) ) {
		echo '<div id="message" class="notice notice-success is-dismissible"><p>';
		_e( 'The selected plugins will now update automatically.', 'wp-autoupdates' );
		echo '</p></div>';
	}
	if ( isset( $_GET['disable-autoupdate'] ) ) {
		echo '<div id="message" class="notice notice-success is-dismissible"><p>';
		_e( 'The selected plugins won’t automatically update anymore.', 'wp-autoupdates' );
		echo '</p></div>';
	}
}


/**
 * Auto-update notices for themes
 */
function wp_autoupdates_themes_notices() {
	if ( isset( $_GET['enable-autoupdate'] ) ) {
		echo '<div id="message" class="notice notice-success is-dismissible"><p>';
		_e( 'The selected themes will now update automatically.', 'wp-autoupdates' );
		echo '</p></div>';
	}
	if ( isset( $_GET['disable-autoupdate'] ) ) {
		echo '<div id="message" class="notice notice-success is-dismissible"><p>';
		_e( 'The selected themes won’t automatically update anymore.', 'wp-autoupdates' );
		echo '</p></div>';
	}
}


/**
 * Auto-update notices
 */
function wp_autoupdates_notices() {
	// Plugins screen
	$pagenow = $GLOBALS['pagenow'];
	if ( 'plugins.php' === $pagenow ) {
		wp_autoupdates_plugins_notices();
	}
	else if ( 'themes.php' === $pagenow ) {
		wp_autoupdates_themes_notices();
	}	
}
add_action( 'admin_notices', 'wp_autoupdates_notices' );

/**
 * Allows a plugin to decide if auto updates is an option for user to enable.
 *
 * @param  string $plugin_file
 * @return bool   Returns true or false if auto-updates are allowed.
 */
function wp_autoupdates_plugin_allows_auto_update( $plugin_file ) {
	return apply_filters( 'wp_plugin_allows_auto_update', true, $plugin_file );
}

/**
 * Add views for auto-update enabled/disabled.
 *
 * This is modeled on `WP_Plugins_List_Table::get_views()`.  If this is merged into core,
 * then this should be encorporated there.
 *
 * @global array  $totals Counts by plugin_status, set in `WP_Plugins_List_Table::prepare_items()`.
 */
function wp_autoupdates_plugins_status_links( $status_links ) {
	global $totals;

	if ( ! current_user_can( 'update_plugins' ) || ! wp_autoupdates_is_plugins_auto_update_enabled() ) {
		return $status_links;
	}

	/** This filter is documented in wp-admin/includes/class-wp-plugins-list-table.php */
	$all_plugins           = apply_filters( 'all_plugins', get_plugins() );
	$wp_autoupdate_plugins = get_site_option( 'wp_auto_update_plugins', array() );
	$wp_autoupdate_plugins = array_intersect( $wp_autoupdate_plugins, array_keys( $all_plugins ) );
	$enabled_count         = count( $wp_autoupdate_plugins );

	// when merged, these counts will need to be set in WP_Plugins_List_Table::prepare_items().
	$counts = array(
		'autoupdate_enabled'  => $enabled_count,
		'autoupdate_disabled' => $totals['all'] - $enabled_count,
	);

	// we can't use the global $status set in WP_Plugin_List_Table::__construct() because
	// it will be 'all' for our "custom statuses".
	$status = isset( $_REQUEST['plugin_status'] ) ? $_REQUEST['plugin_status'] : 'all';

	foreach ( $counts as $type => $count ) {
		if ( 0 === $count ) {
			continue;
		}
		switch( $type ) {
			case 'autoupdate_enabled':
				/* translators: %s: Number of plugins. */
				$text = _n(
					'Auto-updates Enabled <span class="count">(%s)</span>',
					'Auto-updates Enabled <span class="count">(%s)</span>',
					$count,
					'wp-autoupdates'
				);

				break;
			case 'autoupdate_disabled':
				/* translators: %s: Number of plugins. */
				$text = _n(
					'Auto-updates Disabled <span class="count">(%s)</span>',
					'Auto-updates Disabled <span class="count">(%s)</span>',
					$count,
					'wp-autoupdates'
				);
		}

		$status_links[ $type ] = sprintf(
			"<a href='%s'%s>%s</a>",
			add_query_arg( 'plugin_status', $type, 'plugins.php' ),
			( $type === $status ) ? ' class="current" aria-current="page"' : '',
			sprintf( $text, number_format_i18n( $count ) )
		);
	}

	// make the 'all' status link not current if one of our "custom statuses" is current.
	if ( in_array( $status, array_keys( $counts ) ) ) {
		$status_links['all'] = str_replace( ' class="current" aria-current="page"', '', $status_links['all'] );
	}

	return $status_links;
}
add_action( is_multisite() ? 'views_plugins-network' : 'views_plugins', 'wp_autoupdates_plugins_status_links' );

/**
 * Filter plugins shown in the list table when status is 'auto-update-enabled' or 'auto-update-disabled'.
 *
 * This is modeled on `WP_Plugins_List_Table::prepare_items()`.  If this is merged into core,
 * then this should be encorporated there.
 *
 * This action this is hooked to is fired in `wp-admin/plugins.php`.
 *
 * @global WP_Plugins_List_Table $wp_list_table The global list table object.  Set in `wp-admin/plugins.php`.
 * @global int                   $page          The current page of plugins displayed.  Set in WP_Plugins_List_Table::__construct().
 */
function wp_autoupdates_plugins_filter_plugins_by_status( $plugins ) {
	global $wp_list_table, $page;

	$custom_statuses = array(
		'autoupdate_enabled',
		'autoupdate_disabled',
	);

	if ( ! ( isset( $_REQUEST['plugin_status'] ) &&
			in_array( $_REQUEST['plugin_status'], $custom_statuses ) ) ) {
		// current request is not for one of our statuses.
		// nothing to do, so bail.
		return;
	}

	$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );
	$_plugins = array();
	foreach ( $plugins as $plugin_file => $plugin_data ) {
		switch ( $_REQUEST['plugin_status'] ) {
			case 'autoupdate_enabled':
				if ( in_array( $plugin_file, $wp_auto_update_plugins ) ) {
					$_plugins[ $plugin_file ] = _get_plugin_data_markup_translate( $plugin_file, $plugin_data, false, true );
				}
				break;
			case 'autoupdate_disabled':
				if ( ! in_array( $plugin_file, $wp_auto_update_plugins ) ) {
					$_plugins[ $plugin_file ] = _get_plugin_data_markup_translate( $plugin_file, $plugin_data, false, true );
				}
				break;
		}
	}

	// set the list table's items array to just those plugins with our custom status.
	$wp_list_table->items = $_plugins;

	// now, update the pagination properties of the list table accordingly.
	$total_this_page = count( $_plugins );

	$plugins_per_page = $wp_list_table->get_items_per_page( str_replace( '-', '_', $wp_list_table->screen->id . '_per_page' ), 999 );

	$start = ( $page - 1 ) * $plugins_per_page;

	if ( $total_this_page > $plugins_per_page ) {
		$wp_list_table->items = array_slice( $wp_list_table->items, $start, $plugins_per_page );
	}

	$wp_list_table->set_pagination_args(
		array(
			'total_items' => $total_this_page,
			'per_page'    => $plugins_per_page,
		)
	);

	return;
}
add_action( 'pre_current_active_plugins', 'wp_autoupdates_plugins_filter_plugins_by_status' );

/*
 * Populate site health informations
 */
function wp_autoupdates_debug_information( $info ) {
	// Plugins
	if ( wp_autoupdates_is_plugins_auto_update_enabled() ) {
		// Populate plugins informations
		$wp_auto_update_plugins = get_site_option( 'wp_auto_update_plugins', array() );

		$plugins        = get_plugins();
		$plugin_updates = get_plugin_updates();

		foreach ( $plugins as $plugin_path => $plugin ) {
			$plugin_part = ( is_plugin_active( $plugin_path ) ) ? 'wp-plugins-active' : 'wp-plugins-inactive';

			$plugin_version = $plugin['Version'];
			$plugin_author  = $plugin['Author'];

			$plugin_version_string       = __( 'No version or author information is available.', 'wp-autoupdates' );
			$plugin_version_string_debug = __( 'author: (undefined), version: (undefined)', 'wp-autoupdates' );

			if ( ! empty( $plugin_version ) && ! empty( $plugin_author ) ) {
				/* translators: 1: Plugin version number. 2: Plugin author name. */
				$plugin_version_string       = sprintf( __( 'Version %1$s by %2$s', 'wp-autoupdates' ), $plugin_version, $plugin_author );
				/* translators: 1: Plugin version number. 2: Plugin author name. */
				$plugin_version_string_debug = sprintf( __( 'version: %1$s, author: %2$s', 'wp-autoupdates' ), $plugin_version, $plugin_author );
			} else {
				if ( ! empty( $plugin_author ) ) {
					/* translators: %s: Plugin author name. */
					$plugin_version_string       = sprintf( __( 'By %s', 'wp-autoupdates' ), $plugin_author );
					/* translators: %s: Plugin author name. */
					$plugin_version_string_debug = sprintf( __( 'author: %s, version: (undefined)', 'wp-autoupdates' ), $plugin_author );
				}
				if ( ! empty( $plugin_version ) ) {
					/* translators: %s: Plugin version number. */
					$plugin_version_string       = sprintf( __( 'Version %s', 'wp-autoupdates' ), $plugin_version );
					/* translators: %s: Plugin version number. */
					$plugin_version_string_debug = sprintf( __( 'author: (undefined), version: %s', 'wp-autoupdates' ), $plugin_version );
				}
			}

			if ( array_key_exists( $plugin_path, $plugin_updates ) ) {
				/* translators: %s: Latest plugin version number. */
				$plugin_version_string       .= ' ' . sprintf( __( '(Latest version: %s)', 'wp-autoupdates' ), $plugin_updates[ $plugin_path ]->update->new_version );
				/* translators: %s: Latest plugin version number. */
				$plugin_version_string_debug .= ' ' . sprintf( __( '(latest version: %s)', 'wp-autoupdates' ), $plugin_updates[ $plugin_path ]->update->new_version );
			}

			if ( in_array( $plugin_path, $wp_auto_update_plugins ) ) {
				$plugin_version_string       .= ' | ' . sprintf( __( 'Auto-updates enabled', 'wp-autoupdates' ) );
				$plugin_version_string_debug .= sprintf( __( 'auto-updates enabled', 'wp-autoupdates' ) );
			} else {
				$plugin_version_string       .= ' | ' . sprintf( __( 'Auto-updates disabled', 'wp-autoupdates' ) );
				$plugin_version_string_debug .= sprintf( __( 'auto-updates disabled', 'wp-autoupdates' ) );
			}

			$info[ $plugin_part ]['fields'][ sanitize_text_field( $plugin['Name'] ) ] = array(
				'label' => $plugin['Name'],
				'value' => $plugin_version_string,
				'debug' => $plugin_version_string_debug,
			);
		}
	}

	if ( wp_autoupdates_is_themes_auto_update_enabled() ) {
		// Populate themes informations
		$wp_auto_update_themes = get_site_option( 'wp_auto_update_themes', array() );

		$themes       = wp_get_themes();
		$active_theme = wp_get_theme();
		foreach ( $themes as $theme_path => $theme ) {
			$theme_version = sanitize_text_field( $theme['Version'] );
			$theme_author  = sanitize_text_field( $theme['Author'] );

			$is_active_theme = $theme->name === $active_theme->name;
			if ($is_active_theme) {
				$theme_part = 'wp-active-theme';

				if ( in_array( $theme_path, $wp_auto_update_themes ) ) {
					$theme_auto_update_string       = sprintf( __( 'Enabled', 'wp-autoupdates' ) );
				} else {
					$theme_auto_update_string = sprintf( __( 'Disabled', 'wp-autoupdates' ) );
				}

				$info[ $theme_part ]['fields']['Auto-update'] = array(
					'label' => __( 'Auto-update', 'wp-autoupdates' ),
					'value' => $theme_auto_update_string,
					'debug' => $theme_auto_update_string,
				);
			} else {
				$theme_part = 'wp-themes-inactive';

				$theme_version_string       = __( 'No version or author information is available.', 'wp-autoupdates' );
				$theme_version_string_debug = __( 'author: (undefined), version: (undefined)', 'wp-autoupdates' );

				if ( ! empty( $theme_version ) && ! empty( $theme_author ) ) {
					/* translators: 1: Theme version number. 2: Theme author name. */
					$theme_version_string       = sprintf( __( 'Version %1$s by %2$s', 'wp-autoupdates' ), $theme_version, $theme_author );
					/* translators: 1: Theme version number. 2: Theme author name. */
					$theme_version_string_debug = sprintf( __( 'version: %1$s, author: %2$s', 'wp-autoupdates' ), $theme_version, $theme_author );
				} else {
					if ( ! empty( $theme_author ) ) {
						/* translators: %s: Theme author name. */
						$theme_version_string       = sprintf( __( 'By %s', 'wp-autoupdates' ), $theme_author );
						/* translators: %s: Theme author name. */
						$theme_version_string_debug = sprintf( __( 'author: %s, version: (undefined)', 'wp-autoupdates' ), $theme_author );
					}
					if ( ! empty( $theme_version ) ) {
						/* translators: %s: Theme version number. */
						$theme_version_string       = sprintf( __( 'Version %s', 'wp-autoupdates' ), $theme_version );
						/* translators: %s: Theme version number. */
						$theme_version_string_debug = sprintf( __( 'author: (undefined), version: %s', 'wp-autoupdates' ), $theme_version );
					}
				}

				if ( in_array( $theme_path, $wp_auto_update_themes ) ) {
					$theme_version_string       .= ' | ' . sprintf( __( 'Auto-updates enabled', 'wp-autoupdates' ) );
					$theme_version_string_debug .= sprintf( __( 'auto-updates enabled', 'wp-autoupdates' ) );
				} else {
					$theme_version_string       .= ' | ' . sprintf( __( 'Auto-updates disabled', 'wp-autoupdates' ) );
					$theme_version_string_debug .= sprintf( __( 'auto-updates disabled', 'wp-autoupdates' ) );
				}

				$theme_name = sanitize_text_field( $theme['Name'] );
				$label_name = sprintf( __( '%1$s (%2$s)', 'wp-autoupdates' ), $theme_name, $theme_path);
				$info[ $theme_part ]['fields'][ $theme_name ] = array(
					'label' => $label_name,
					'value' => $theme_version_string,
					'debug' => $theme_version_string_debug,
				);
			}
		}
	}

	// Populate constants informations
	$plugins_enabled = defined( 'WP_DISABLE_PLUGINS_AUTO_UPDATE' ) ? WP_DISABLE_PLUGINS_AUTO_UPDATE : __( 'Undefined', 'wp-autoupdates' );
	$info['wp-constants']['fields']['WP_DISABLE_PLUGINS_AUTO_UPDATE'] = array(
		'label' => 'WP_DISABLE_PLUGINS_AUTO_UPDATE',
		'value' => $plugins_enabled,
		'debug' => strtolower( $plugins_enabled ),
	);

	$themes_enabled = defined( 'WP_DISABLE_THEMES_AUTO_UPDATE' ) ? WP_DISABLE_THEMES_AUTO_UPDATE : __( 'Undefined', 'wp-autoupdates' );
	$info['wp-constants']['fields']['WP_DISABLE_THEMES_AUTO_UPDATE'] = array(
		'label' => 'WP_DISABLE_THEMES_AUTO_UPDATE',
		'value' => $themes_enabled,
		'debug' => strtolower( $themes_enabled ),
	);

	return $info;
}
add_filter( 'debug_information', 'wp_autoupdates_debug_information' );


/**
 * If we tried to perform plugin updates, check if we should send an email.
 *
 * @param object $results The result of the plugin updates.
 */
function wp_autoupdates_automatic_updates_complete_notification( $results ) {
	$successful_updates = array();
	$failed_updates = array();
	if ( isset( $results['plugin'] ) ) {
		foreach ( $results['plugin'] as $update_result ) {
			if ( true === $update_result->result ) {
				$successful_updates[] = $update_result;
			} else {
				$failed_updates[] = $update_result;
			}
		}
		if ( empty( $successful_updates ) && empty( $failed_updates ) ) {
			return;
		}
		if ( empty( $failed_updates ) ) {
			wp_autoupdates_send_email_notification( 'success', $successful_updates, $failed_updates );
		} elseif ( empty( $successful_updates ) ) {
			wp_autoupdates_send_email_notification( 'fail', $successful_updates, $failed_updates );
		} else {
			wp_autoupdates_send_email_notification( 'mixed', $successful_updates, $failed_updates );
		}
	}
}
add_action( 'automatic_updates_complete', 'wp_autoupdates_automatic_updates_complete_notification' );


/**
 * Sends an email upon the completion or failure of a plugin background update.
 *
 * @param string $type               The type of email to send. Can be one of 'success', 'failure', 'mixed'.
 * @param array  $successful_updates A list of plugin updates that succeeded.
 * @param array  $failed_updates     A list of plugin updates that failed.
 */
function wp_autoupdates_send_email_notification( $type, $successful_updates, $failed_updates ) {
	// No updates were attempted.
	if ( empty( $successful_updates ) && empty( $failed_updates ) ) {
		return;
	}
	$body = array();

	switch ( $type ) {
		case 'success':
			/* translators: %s: Site title. */
			$subject = __( '[%s] Plugins have automatically updated', 'wp-autoupdates' );
			break;
		case 'fail':
			/* translators: %s: Site title. */
			$subject = __( '[%s] Plugins have failed to update', 'wp-autoupdates' );
			$body[]  = sprintf(
				/* translators: %s: Home URL. */
				__( 'Howdy! Failures occurred when attempting to update plugins on your site at %s.', 'wp-autoupdates' ),
				home_url()
			);
			$body[] = "\n";
			$body[] = __( 'Please check out your site now. It’s possible that everything is working. If it says you need to update, you should do so.', 'wp-autoupdates' );
			break;
		case 'mixed':
			/* translators: %s: Site title. */
			$subject = __( '[%s] Some plugins have automatically updated', 'wp-autoupdates' );
			$body[] = sprintf(
				/* translators: %s: Home URL. */
				__( 'Howdy! There were some failures while attempting to update plugins on your site at %s.', 'wp-autoupdates' ),
				home_url()
			);
			$body[] = "\n";
			$body[] = __( 'Please check out your site now. It’s possible that everything is working. If it says you need to update, you should do so.', 'wp-autoupdates' );
			$body[] = "\n";
			break;
	}

	if ( in_array( $type, array( 'fail', 'mixed' ), true ) && ! empty( $failed_updates ) ) {
		$body[] = __( 'The following plugins failed to update:' );
		// List failed updates.
		foreach ( $failed_updates as $item ) {
			/* translators: %s: Name of the related plugin. */
			$body[] = ' ' . sprintf( __( '- %s', 'wp-autoupdates' ), $item->name );
		}
		$body[] = "\n";
	}
	if ( in_array( $type, array( 'success', 'mixed' ), true ) && ! empty( $successful_updates ) ) {
		$body[] = __( 'The following plugins were successfully updated:' );
		// List successful updates.
		foreach ( $successful_updates as $plugin ) {
			/* translators: %s: Name of the related plugin. */
			$body[] = ' ' . sprintf( __( '- %s', 'wp-autoupdates' ), $plugin->name );
		}
	}
	$body[] = "\n";

	// Add a note about the support forums.
	$body[] = __( 'If you experience any issues or need support, the volunteers in the WordPress.org support forums may be able to help.', 'wp-autoupdates' );
	$body[] = __( 'https://wordpress.org/support/forums/', 'wp-autoupdates' );
	$body[] = "\n" . __( 'The WordPress Team', 'wp-autoupdates' );

	$body    = implode( "\n", $body );
	$to      = get_site_option( 'admin_email' );
	$subject = sprintf( $subject, wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
	$headers = '';

	$email = compact( 'to', 'subject', 'body', 'headers' );

	/**
	 * Filters the email sent following an automatic background plugin update.
	 * @param array $email {
	 *     Array of email arguments that will be passed to wp_mail().
	 *
	 *     @type string $to      The email recipient. An array of emails
	 *                           can be returned, as handled by wp_mail().
	 *     @type string $subject The email's subject.
	 *     @type string $body    The email message body.
	 *     @type string $headers Any email headers, defaults to no headers.
	 * }
	 * @param string $type               The type of email being sent. Can be one of
	 *                                   'success', 'fail', 'mixed'.
	 * @param object $successful_updates The updates that succeded.
	 * @param object $failed_updates     The updates that failed.
	 */
	$email = apply_filters( 'wp_autoupdates_notifications_email', $email, $type, $successful_updates, $failed_updates );
	wp_mail( $email['to'], wp_specialchars_decode( $email['subject'] ), $email['body'], $email['headers'] );
}


/**
 * Determines the appropriate update message to be displayed.
 *
 * @return string The update message to be shown.
 */
function wp_autoupdates_get_update_message() {
	$next_update_time = wp_next_scheduled( 'wp_version_check' );

	// Check if event exists.
	if ( false === $next_update_time ) {
		return __( 'There may be a problem with WP-Cron. Automatic update not scheduled.', 'wp-autoupdates' );
	}

	// See if cron is disabled
	$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	if ( $cron_disabled ) {
		return __( 'WP-Cron is disabled. Automatic updates not available.', 'wp-autoupdates' );
	}

	$time_to_next_update = human_time_diff( intval( $next_update_time ) );

	// See if cron is overdue.
	$overdue = (time() - $next_update_time) > 0;
	if ( $overdue ) {
		return sprintf(
			/* translators: Duration that WP-Cron has been overdue. */
			__( 'There may be a problem with WP-Cron. Automatic update overdue by %s.', 'wp-autoupdates' ),
			$time_to_next_update
		);
	} else {
		return sprintf(
			/* translators: Time until the next update. */
			__( 'Automatic update scheduled in %s.', 'wp-autoupdates' ),
			$time_to_next_update
		);
	}
}
