<?php
/*
Plugin Name: Pods
Plugin URI: http://pods.uproot.us/
Description: The Wordpress CMS Plugin
Version: 1.3.2
Author: Matt Gibbs
Author URI: http://pods.uproot.us/

Copyright 2008  Matt Gibbs  (email : logikal16@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

function initialize()
{
    global $pods_url, $table_prefix;

    $latest = 132;
    $installed = 0;

    // Get the installed version
    $result = pod_query("SELECT option_value FROM {$table_prefix}options WHERE option_name = 'pods_version' ORDER BY option_value DESC LIMIT 1");
    if (0 < mysql_num_rows($result))
    {
        $row = mysql_fetch_assoc($result);
        $installed = $row['option_value'];

        // Update tables
        if ($installed < $latest)
        {
            include("$pods_url/sql/update.php");
        }
    }
    // Setup initial tables
    else
    {
        $sql = file_get_contents("$dir/dump.sql");
        $sql = explode(";\n", str_replace('wp_', $table_prefix, $sql));
        for ($i = 0, $z = count($sql); $i < $z - 1; $i++)
        {
            pod_query($sql[$i]);
        }
        pod_query("INSERT INTO {$table_prefix}options (option_name, option_value) VALUES ('pods_version', '$latest')");
    }

    // Check for .htaccess
    if (!file_exists(ABSPATH . '.htaccess'))
    {
        if (!copy(WP_PLUGIN_DIR . '/pods/.htaccess', ABSPATH . '.htaccess'))
        {
            echo 'Please copy the .htaccess file from plugins/pods/ to the Wordpress root folder!';
        }
    }
}

function adminMenu()
{
    global $menu, $table_prefix;

    $menu[30] = array('Pods', 8, 'pods', 'Pods', 'menu-top toplevel_page_pods', 'toplevel_page_pods', 'images/generic.png');
    add_submenu_page('pods', 'Setup', 'Setup', 8, 'pods', 'edit_options_page');
    add_submenu_page('pods', 'Layout Editor', 'Layout Editor', 8, 'pods-layout', 'edit_layout_page');
    add_submenu_page('pods', 'Browse Content', 'Browse Content', 8, 'pods-browse', 'edit_content_page');

    $result = mysql_query("SELECT name FROM {$table_prefix}pod_types ORDER BY name");
    if (0 < mysql_num_rows($result))
    {
        while ($row = mysql_fetch_array($result))
        {
            add_submenu_page('pods', "Add $row[0]", "Add $row[0]", 8, "pod-$row[0]", 'edit_content_page');
        }
    }
}

function edit_options_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/options.php';
}

function edit_layout_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/layout.php';
}

function edit_content_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/content.php';
}

function pods_title($title, $sep, $seplocation)
{
    $pieces = explode('?', $_SERVER['REQUEST_URI']);
    $pieces = preg_replace("@^([/]?)(.*?)([/]?)$@", "$2", $pieces[0]);
    $pieces = str_replace('_', ' ', $pieces);
    $pieces = str_replace('-', ' ', $pieces);
    $pieces = explode('/', $pieces);
    $title = str_replace(" $sep Page not found", '', $title);

    foreach ($pieces as $key => $page_title)
    {
        $title .= " $sep " . ucwords($page_title);
    }
    return $title;
}

function redirect()
{
    global $table_prefix;

    $uri = explode('?', $_SERVER['REQUEST_URI']);
    $uri = preg_replace("@^([/]?)(.*?)([/]?)$@", "$2", $uri[0]);
    $uri = empty($uri) ? '/' : "/$uri/";

    // See if the custom template exists
    $result = mysql_query("SELECT phpcode FROM {$table_prefix}pod_pages WHERE uri = '$uri' LIMIT 1");
    if (1 > mysql_num_rows($result))
    {
        // Find any wildcards
        $sql = "
        SELECT
            phpcode
        FROM
            {$table_prefix}pod_pages
        WHERE
            '$uri' LIKE REPLACE(uri, '*', '%')
        ORDER BY
            uri DESC
        LIMIT
            1
        ";
        $result = mysql_query($sql) or die(mysql_error());
    }

    if (0 < mysql_num_rows($result))
    {
        if (is_404())
        {
            add_filter('wp_title', 'pods_title', 8, 3);
        }

        $row = mysql_fetch_assoc($result);
        $phpcode = $row['phpcode'];

        include WP_PLUGIN_DIR . '/pods/router.php';
        return;
    }
}

// Setup DB tables, get the gears turning
require_once WP_PLUGIN_DIR . '/pods/pod_query.php';

initialize();

$pods_url = WP_PLUGIN_URL . '/pods';

// Hook for admin menu
add_action('admin_menu', 'adminMenu');

// Hook for redirection
add_action('template_redirect', 'redirect');

