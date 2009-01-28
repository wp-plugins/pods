<?php
/*
Plugin Name: Pods
Plugin URI: http://pods.uproot.us/
Description: The WordPress CMS Plugin
Version: 1.4.5
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
$pods_latest = 145;

function pods_init()
{
    global $table_prefix, $pods_latest;
    $dir = WP_PLUGIN_DIR . '/pods/sql';

    // Get the installed version
    if ($installed = (int) get_option('pods_version'))
    {
        if ($installed < $pods_latest)
        {
            include("$dir/update.php");
        }
    }
    // Setup initial tables
    else
    {
        $sql = file_get_contents("$dir/dump.sql");
        $sql = explode(";\n", str_replace('wp_', $table_prefix, $sql));
        for ($i = 0, $z = count($sql) - 1; $i < $z; $i++)
        {
            pod_query($sql[$i], 'Cannot setup SQL tables');
        }
        delete_option('pods_version');
        add_option('pods_version', $pods_latest);
    }

    // Check for .htaccess
    if (!file_exists(ABSPATH . '.htaccess'))
    {
        if (!copy(WP_PLUGIN_DIR . '/pods/.htaccess', ABSPATH . '.htaccess'))
        {
            echo 'Please copy the .htaccess file from plugins/pods/ to the WordPress root folder!';
        }
    }
}

function pods_menu()
{
    global $table_prefix, $current_user;

    // Editors and Admins can add/edit items
    if (4 < intval($current_user->user_level))
    {
        $submenu = array();
        $result = pod_query("SELECT name, label, is_toplevel FROM {$table_prefix}pod_types ORDER BY name");
        if (0 < mysql_num_rows($result))
        {
            while ($row = mysql_fetch_array($result))
            {
                $name = $row['name'];
                $label = trim($row['label']);
                $label = ('' != $label) ? $label : $name;

                if (1 != $row['is_toplevel'])
                {
                    $submenu[] = $row;
                }
                else
                {
                    add_object_page($label, $label, 5, "pods-browse-$name");
                    add_submenu_page("pods-browse-$name", 'Edit', 'Edit', 5, "pods-browse-$name", 'pods_content_page');
                    add_submenu_page("pods-browse-$name", 'Add New', 'Add New', 5, "pod-$name", 'pods_content_page');
                }
            }
        }
    }

    // Admins can manage Pods
    if (7 < intval($current_user->user_level))
    {
        add_object_page('Pods', 'Pods', 8, 'pods');
        add_submenu_page('pods', 'Setup', 'Setup', 8, 'pods', 'pods_options_page');
        add_submenu_page('pods', 'Browse Content', 'Browse Content', 8, 'pods-browse', 'pods_content_page');
        add_submenu_page('pods', 'Menu Editor', 'Menu Editor', 8, 'pods-menu', 'pods_menu_page');

        foreach ($submenu as $item)
        {
            $name = $item['name'];
            add_submenu_page('pods', "Add $name", "Add $name", 8, "pod-$name", 'pods_content_page');
        }
    }
}

function pods_options_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/options.php';
}

function pods_content_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/content.php';
}

function pods_menu_page()
{
    global $pods_url, $table_prefix;
    include WP_PLUGIN_DIR . '/pods/menu.php';
}

function pods_meta()
{
    global $pods_latest;

    $pods_latest = "$pods_latest";
    $pods_latest = $pods_latest[0] . '.' . $pods_latest[1] . '.' . $pods_latest[2];
?>
<meta name="CMS" content="Pods <?php echo $pods_latest; ?>" />
<?php
}

function pods_title($title, $sep, $seplocation)
{
    if (false !== strpos($title, 'Page not found'))
    {
        global $podpage_exists;

        $page_title = trim($podpage_exists['page_title']);

        if (0 < strlen($page_title))
        {
            $title = str_replace('Page not found', $page_title, $title);
        }
        else
        {
            $uri = explode('?', $_SERVER['REQUEST_URI']);
            $uri = preg_replace("@^([/]?)(.*?)([/]?)$@", "$2", $uri[0]);
            $uri = preg_replace("@(-|_)@", "", $uri);
            $uri = explode('/', $uri);

            $title = '';
            foreach ($uri as $key => $page_title)
            {
                $title .= ('right' == $seplocation) ? ucwords($page_title) . " $sep " : " $sep " . ucwords($page_title);
            }
        }
    }
    return $title;
}

function get_content()
{
    global $phpcode, $post;

    // Cleanse the GET variables
    foreach ($_GET as $key => $val)
    {
        ${$key} = mysql_real_escape_string($val);
    }

    if (!empty($phpcode))
    {
        eval("?>$phpcode");
    }
    elseif (!empty($post))
    {
        echo $post->post_content;
    }
}

function pods_redirect()
{
    global $phpcode, $podpage_exists;

    if ($row = $podpage_exists)
    {
        $phpcode = $row['phpcode'];
        $page_template = $row['page_template'];

        include WP_PLUGIN_DIR . '/pods/router.php';
        die();
    }
}

function pods_404()
{
    return 'HTTP/1.1 200 OK';
}

function podpage_exists()
{
    global $table_prefix;

    $uri = explode('?', $_SERVER['REQUEST_URI']);
    $uri = preg_replace("@^([/]?)(.*?)([/]?)$@", "$2", $uri[0]);
    $uri = empty($uri) ? '/' : "/$uri/";

    if (false !== strpos($uri, 'wp-admin'))
    {
        return false;
    }

    // Handle subdirectory installations
    $baseuri = get_bloginfo('url');
    $baseuri = substr($baseuri, strpos($baseuri, '//') + 2);
    $baseuri = str_replace($_SERVER['HTTP_HOST'], '', $baseuri);
    $baseuri = str_replace($baseuri, '', $uri);

    // See if the custom template exists
    $result = pod_query("SELECT * FROM {$table_prefix}pod_pages WHERE uri IN('$uri', '$baseuri') LIMIT 1");
    if (1 > mysql_num_rows($result))
    {
        // Find any wildcards
        $sql = "
        SELECT
            *
        FROM
            {$table_prefix}pod_pages
        WHERE
            '$uri' LIKE REPLACE(uri, '*', '%') OR '$baseuri' LIKE REPLACE(uri, '*', '%')
        ORDER BY
            uri DESC
        LIMIT
            1
        ";
        $result = pod_query($sql);
    }

    if (0 < mysql_num_rows($result))
    {
        return mysql_fetch_assoc($result);
    }
    return false;
}

// Setup DB tables, get the gears turning
require_once WP_PLUGIN_DIR . '/pods/functions.php';
require_once WP_PLUGIN_DIR . '/pods/Pod.class.php';

pods_init();

$podpage_exists = podpage_exists();
$pods_url = WP_PLUGIN_URL . '/pods';

// Hook for admin menu
add_action('admin_menu', 'pods_menu', 9999);

// Hook for Pods branding
add_action('wp_head', 'pods_meta', 0);

// Hook for redirection
add_action('template_redirect', 'pods_redirect');

// Filters for 404 handling
if (false !== $podpage_exists)
{
    add_filter('wp_title', 'pods_title', 8, 3);
    add_filter('status_header', 'pods_404');
}

