<?php
/*
==================================================
Pod.class.php

http://pods.uproot.us/codex/
==================================================
*/
class Pod
{
    var $id;
    var $sql;
    var $data;
    var $result;
    var $datatype;
    var $datatype_id;
    var $total_rows;
    var $detail_page;
    var $form_count = 0;
    var $rpp = 15;
    var $page;

    var $helper_cache;

    function Pod($datatype = null, $id = null)
    {
        $this->id = pods_sanitize($id);
        $this->datatype = pods_sanitize($datatype);
        $this->page = empty($_GET['pg']) ? 1 : (int) $_GET['pg'];

        if (null != $this->datatype)
        {
            $result = pod_query("SELECT id, detail_page FROM @wp_pod_types WHERE name = '$this->datatype' LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $this->datatype_id = $row['id'];
            $this->detail_page = $row['detail_page'];

            if (null != $this->id)
            {
                return $this->getRecordById($this->id);
            }
        }
    }

    /*
    ==================================================
    Output the SQL resultset
    ==================================================
    */
    function fetchRecord()
    {
        if ($this->data = mysql_fetch_assoc($this->result))
        {
            return $this->data;
        }
        return false;
    }

    /*
    ==================================================
    Return the value of a single field (return arrays)
    ==================================================
    */
    function get_field($name, $orderby = null)
    {
        if (isset($this->data[$name]))
        {
            return $this->data[$name];
        }
        elseif ('created' == $name || 'modified' == $name)
        {
            $pod_id = $this->get_pod_id();
            $result = pod_query("SELECT created, modified FROM @wp_pod WHERE id = $pod_id LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $this->data['created'] = $row['created'];
            $this->data['modified'] = $row['modified'];
            return $this->data[$name];
        }
        elseif ('detail_url' == $name)
        {
            return $this->parse_magic_tags(array('', '', 'detail_url'));
        }
        else
        {
            // Dot-traversal
            $last_loop = false;
            $datatype_id = $this->datatype_id;
            $tbl_row_ids = $this->data['id'];

            $traverse = (false !== strpos($name, '.')) ? explode('.', $name) : array($name);
            $traverse_fields = implode("','", $traverse);

            // Get columns matching traversal names
            $result = pod_query("SELECT id, datatype, name, coltype, pickval FROM @wp_pod_fields WHERE name IN ('$traverse_fields')");
            if (0 < mysql_num_rows($result))
            {
                while ($row = mysql_fetch_assoc($result))
                {
                    $all_fields[$row['datatype']][$row['name']] = $row;
                }
            }
            // No matching columns
            else
            {
                return false;
            }

            // Loop through each traversal level
            foreach ($traverse as $key => $column_name)
            {
                $last_loop = (1 < count($traverse) - $key) ? false : true;
                $column_exists = isset($all_fields[$datatype_id][$column_name]);

                if ($column_exists)
                {
                    $col = $all_fields[$datatype_id][$column_name];
                    $field_id = $col['id'];
                    $coltype = $col['coltype'];
                    $pickval = $col['pickval'];

                    if ('pick' == $coltype || 'file' == $coltype)
                    {
                        $last_coltype = $coltype;
                        $last_pickval = $pickval;
                        $tbl_row_ids = $this->lookup_row_ids($field_id, $datatype_id, $tbl_row_ids);

                        if (false === $tbl_row_ids)
                        {
                            return false;
                        }

                        // Get datatype ID for non-WP PICK columns
                        if (
                            false === empty($pickval) &&
                            false === in_array($pickval, array('wp_taxonomy', 'wp_post', 'wp_page', 'wp_user')))
                        {
                            $result = pod_query("SELECT id FROM @wp_pod_types WHERE name = '$pickval' LIMIT 1");
                            $datatype_id = mysql_result($result, 0);
                        }
                    }
                    else
                    {
                        $last_loop = true;
                    }
                }
                // Assume last iteration
                else
                {
                    // Invalid column name
                    if (0 == $key)
                    {
                        return false;
                    }
                    $last_loop = true;
                }

                if ($last_loop)
                {
                    $table = ('file' == $last_coltype) ? 'file' : $last_pickval;

                    if (!empty($table))
                    {
                        $data = $this->rel_lookup($tbl_row_ids, $table, $orderby);
                    }

                    if (empty($data))
                    {
                        $results = false;
                    }
                    // Return entire array
                    elseif (false !== $column_exists && ('pick' == $coltype || 'file' == $coltype))
                    {
                        $results = $data;
                    }
                    // Return a single column value
                    elseif (1 == count($data))
                    {
                        $results = $data[0][$column_name];
                    }
                    // Return an array of single column values
                    else
                    {
                        foreach ($data as $key => $val)
                        {
                            $results[] = $val[$column_name];
                        }
                    }
                    return $results;
                }
            }
        }
    }

    /*
    ==================================================
    Find items related to a parent field
    ==================================================
    */
    function lookup_row_ids($field_id, $datatype_id, $tbl_row_ids)
    {
        $tbl_row_ids = empty($tbl_row_ids) ? 0 : $tbl_row_ids;

        $sql = "
        SELECT
            r.tbl_row_id
        FROM
            @wp_pod p
        INNER JOIN
            @wp_pod_rel r ON r.pod_id = p.id AND r.field_id = $field_id
        WHERE
            p.datatype = $datatype_id AND p.tbl_row_id IN ($tbl_row_ids)
        ORDER BY
            r.weight
        ";
        $result = pod_query($sql);
        if (0 < mysql_num_rows($result))
        {
            while ($row = mysql_fetch_assoc($result))
            {
                $out[] = $row['tbl_row_id'];
            }
            return implode(',', $out);
        }
        return false;
    }

    /*
    ==================================================
    Lookup values from a single relationship field
    ==================================================
    */
    function rel_lookup($tbl_row_ids, $table, $orderby = null)
    {
        $orderby = empty($orderby) ? '' : "ORDER BY $orderby";

        // WP taxonomy item
        if ('wp_taxonomy' == $table)
        {
            $result = pod_query("SELECT * FROM @wp_terms WHERE term_id IN ($tbl_row_ids) $orderby");
        }
        // WP page, post, or attachment
        elseif ('wp_page' == $table || 'wp_post' == $table || 'file' == $table)
        {
            $result = pod_query("SELECT * FROM @wp_posts WHERE ID IN ($tbl_row_ids) $orderby");
        }
        // WP user
        elseif ('wp_user' == $table)
        {
            $result = pod_query("SELECT * FROM @wp_users WHERE ID IN ($tbl_row_ids) $orderby");
        }
        // Pod table
        else
        {
            $result = pod_query("SELECT * FROM `@wp_pod_tbl_$table` WHERE id IN ($tbl_row_ids) $orderby");
        }

        // Put all related items into an array
        while ($row = mysql_fetch_assoc($result))
        {
            $data[] = $row;
        }
        return $data;
    }

    /*
    ==================================================
    Get the pod id
    ==================================================
    */
    function get_pod_id()
    {
        if (empty($this->data['pod_id']))
        {
            $this->data['pod_id'] = 0;
            $tbl_row_id = $this->data['id'];
            $result = pod_query("SELECT id FROM @wp_pod WHERE datatype = '$this->datatype_id' AND tbl_row_id = '$tbl_row_id' LIMIT 1");
            if (0 < mysql_num_rows($result))
            {
                $this->data['pod_id'] = mysql_result($result, 0);
            }
        }
        return $this->data['pod_id'];
    }

    /*
    ==================================================
    Store user-generated data
    ==================================================
    */
    function set_field($name, $data)
    {
        return $this->data[$name] = $data;
    }

    /*
    ==================================================
    Run a helper within a Pod Page or WP template
    ==================================================
    */
    function pod_helper($helper, $value = null, $name = null)
    {
        $helper = mysql_real_escape_string(trim($helper));

        if (false === isset($this->helper_cache[$helper]))
        {
            $result = pod_query("SELECT phpcode FROM @wp_pod_helpers WHERE name = '$helper' LIMIT 1");
            if (0 < mysql_num_rows($result))
            {
                $this->helper_cache[$helper] = mysql_result($result, 0);
            }
            else
            {
                $this->helper_cache[$helper] = false;
            }
        }

        $content = $this->helper_cache[$helper];

        if (false !== $content)
        {
            ob_start();
            eval("?>$content");
            return ob_get_clean();
        }
    }

    /*
    ==================================================
    Get pod or category dropdown values
    ==================================================
    */
    function get_dropdown_values($params)
    {
        foreach ($params as $key => $val)
        {
            ${$key} = $val;
        }

        $orderby = empty($pick_orderby) ? 'name ASC' : $pick_orderby;

        // WP taxonomy dropdown
        if ('wp_taxonomy' == $table)
        {
            $where = (false !== $unique_vals) ? "AND id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                if (!empty($where))
                {
                    $where .= ' AND ';
                }
                $where .= $pick_filter;
            }

            $sql = "
            SELECT
                t.term_id AS id, t.name
            FROM
                @wp_term_taxonomy tx
            INNER JOIN
                @wp_terms t ON t.term_id = tx.term_id
            WHERE
                1 $where
            ORDER BY
                $orderby
            ";
        }
        // WP page or post dropdown
        elseif ('wp_page' == $table || 'wp_post' == $table)
        {
            $post_type = substr($table, 3);
            $where = (false !== $unique_vals) ? "AND id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= " AND $pick_filter";
            }

            $sql = "SELECT ID as id, post_title AS name FROM @wp_posts WHERE post_type = '$post_type' $where ORDER BY $orderby";
        }
        // WP user dropdown
        elseif ('wp_user' == $table)
        {
            $where = (false !== $unique_vals) ? "WHERE id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= (empty($where) ? ' WHERE ' : ' AND ') . $pick_filter;
            }

            $sql = "SELECT ID as id, display_name AS name FROM @wp_users $where ORDER BY $orderby";
        }
        // Pod table dropdown
        else
        {
            $where = (false !== $unique_vals) ? "WHERE id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= (empty($where) ? ' WHERE ' : ' AND ') . $pick_filter;
            }

            $sql = "SELECT * FROM `@wp_pod_tbl_$table` $where ORDER BY $orderby";
        }

        $val = array();
        $result = pod_query($sql);
        while ($row = mysql_fetch_assoc($result))
        {
            if (!empty($tbl_row_ids))
            {
                $row['active'] = in_array($row['id'], $tbl_row_ids);
            }
            else
            {
                $row['active'] = ($row['id'] == $_GET[$field_name]) ? true : false;
            }
            $val[] = $row;
        }
        return $val;
    }

    /*
    ==================================================
    Return a single record
    ==================================================
    */
    function getRecordById($id)
    {
        $datatype = $this->datatype;
        if (!empty($datatype))
        {
            if (is_numeric($id))
            {
                $result = pod_query("SELECT * FROM `@wp_pod_tbl_$datatype` WHERE id = $id LIMIT 1");
            }
            else
            {
                // Get the slug column
                $result = pod_query("SELECT name FROM @wp_pod_fields WHERE coltype = 'slug' AND datatype = $this->datatype_id LIMIT 1");
                if (0 < mysql_num_rows($result))
                {
                    $field_name = mysql_result($result, 0);
                    $result = pod_query("SELECT * FROM `@wp_pod_tbl_$datatype` WHERE `$field_name` = '$id' LIMIT 1");
                }
            }

            if (0 < mysql_num_rows($result))
            {
                $this->data = mysql_fetch_assoc($result);
                $this->data['type'] = $datatype;
                return $this->data;
            }
            $this->data = false;
        }
        else
        {
            die('Error: Datatype not set');
        }
    }

    /*
    ==================================================
    Search and filter records
    ==================================================
    */
    function findRecords($orderby = 'id DESC', $rows_per_page = 15, $where = null, $sql = null)
    {
        $page = $this->page;
        $datatype = $this->datatype;
        $datatype_id = $this->datatype_id;
        $limit = $join = $search = '';
        if (is_int($rows_per_page) && 0 < $rows_per_page)
        {
            $limit = 'LIMIT ' . ($rows_per_page * ($page - 1)) . ',' . $rows_per_page;
        }
        elseif (false !== strpos($rows_per_page, ','))
        {
            // Custom offset
            $limit = 'LIMIT ' . $rows_per_page;
        }
        $where = empty($where) ? '' : "AND $where";
        $this->rpp = $rows_per_page;
        $i = 0;

        // Handle search
        if (!empty($_GET['search']))
        {
            $val = mysql_real_escape_string(trim($_GET['search']));
            $search = "AND (t.name LIKE '%$val%')";
        }

        // Add "t." prefix to $orderby if needed
        if (false !== strpos($orderby, ',') && false === strpos($orderby, '.'))
        {
            $orderby = 't.' . $orderby;
        }

        // Get this pod's fields
        $result = pod_query("SELECT id, name, pickval FROM @wp_pod_fields WHERE datatype = $datatype_id AND coltype = 'pick' ORDER BY weight");
        while ($row = mysql_fetch_assoc($result))
        {
            $i++;
            $field_id = $row['id'];
            $field_name = $row['name'];
            $table = $row['pickval'];

            // Handle any $_GET variables
            if (!empty($_GET[$field_name]))
            {
                $val = mysql_real_escape_string(trim($_GET[$field_name]));

                if ('wp_taxonomy' == $table)
                {
                    $where .= " AND `$field_name`.term_id = $val";
                }
                else
                {
                    $where .= " AND `$field_name`.id = $val";
                }
            }

            // Performance improvement - only use PICK columns mentioned in ($orderby, $where, $search)
            $haystack = "$orderby $where $search";
            if (false === strpos($haystack, $field_name . '.') && false === strpos($haystack, "`$field_name`."))
            {
                continue;
            }

            if ('wp_taxonomy' == $table)
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_terms `$field_name` ON `$field_name`.term_id = r$i.tbl_row_id
                ";
            }
            elseif ('wp_page' == $table || 'wp_post' == $table)
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_posts `$field_name` ON `$field_name`.ID = r$i.tbl_row_id
                ";
            }
            elseif ('wp_user' == $table)
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_users `$field_name` ON `$field_name`.ID = r$i.tbl_row_id
                ";
            }
            else
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    `@wp_pod_tbl_$table` `$field_name` ON `$field_name`.id = r$i.tbl_row_id
                ";
            }
        }

        if (empty($sql))
        {
            $sql = "
            SELECT
                SQL_CALC_FOUND_ROWS DISTINCT t.*
            FROM
                @wp_pod p
            $join
            INNER JOIN
                `@wp_pod_tbl_$datatype` t ON t.id = p.tbl_row_id
            WHERE
                p.datatype = $datatype_id
                $search
                $where
            ORDER BY
                $orderby
            $limit
            ";
        }
        $this->sql = $sql;
        $this->result = pod_query($sql);
        $this->total_rows = pod_query("SELECT FOUND_ROWS()");
    }

    /*
    ==================================================
    Fetch the total row count
    ==================================================
    */
    function getTotalRows()
    {
        if (false === is_numeric($this->total_rows))
        {
            if ($row = mysql_fetch_array($this->total_rows))
            {
                $this->total_rows = $row[0];
            }
        }
        return $this->total_rows;
    }

    /*
    ==================================================
    Display HTML for all datatype fields
    ==================================================
    */
    function showform($pod_id = null, $public_columns = null, $label = 'Save changes')
    {
        $datatype = $this->datatype;
        $datatype_id = $this->datatype_id;
        $this->coltype_counter = array();
        $this->data['pod_id'] = $pod_id;

        $where = '';
        if (!empty($public_columns))
        {
            foreach ($public_columns as $key => $val)
            {
                if (is_array($public_columns[$key]))
                {
                    $where[] = $key;
                    $attributes[$key] = $val;
                }
                else
                {
                    $where[] = $val;
                    $attributes[$val] = array();
                }
            }
            $where = "AND name IN ('" . implode("','", $where) . "')";
        }

        $result = pod_query("SELECT * FROM @wp_pod_fields WHERE datatype = $datatype_id $where ORDER BY weight ASC");
        while ($row = mysql_fetch_assoc($result))
        {
            $fields[$row['name']] = $row;
        }

        // Re-order the fields if a public form
        if (!empty($attributes))
        {
            $tmp = $fields;
            $fields = array();
            foreach ($attributes as $key => $val)
            {
                $fields[$key] = $tmp[$key];
            }
            unset($tmp);
        }

        // Edit an existing item
        if (!empty($pod_id))
        {
            $sql = "
            SELECT
                t.*
            FROM
                @wp_pod p
            INNER JOIN
                `@wp_pod_tbl_$datatype` t ON t.id = p.tbl_row_id
            WHERE
                p.id = $pod_id
            LIMIT
                1
            ";
            $result = pod_query($sql);
            if (0 < mysql_num_rows($result))
            {
                $tbl_cols = mysql_fetch_assoc($result);
            }
        }
        $uri_hash = md5($_SERVER['REQUEST_URI']);

        foreach ($fields as $key => $field)
        {
            // Replace field attributes with public form attributes
            if (!empty($attributes) && is_array($attributes[$key]))
            {
                $field = array_merge($field, $attributes[$key]);
            }

            // Replace the input helper name with the helper code
            $input_helper = $field['input_helper'];
            if (!empty($input_helper))
            {
                $result = pod_query("SELECT phpcode FROM @wp_pod_helpers WHERE name = '$input_helper' LIMIT 1");
                $field['input_helper'] = mysql_result($result, 0);
            }

            if (empty($field['label']))
            {
                $field['label'] = ucwords($key);
            }

            if (1 == $field['required'])
            {
                $field['label'] .= ' <span class="red">*</span>';
            }

            if (!empty($field['pickval']))
            {
                $val = array();
                $tbl_row_ids = array();
                $table = $field['pickval'];

                $result = pod_query("SELECT id FROM @wp_pod_fields WHERE datatype = $datatype_id AND name = '$key' LIMIT 1");
                $field_id = mysql_result($result, 0);

                $result = pod_query("SELECT tbl_row_id FROM @wp_pod_rel WHERE pod_id = $pod_id AND field_id = $field_id");
                while ($row = mysql_fetch_assoc($result))
                {
                    $tbl_row_ids[] = $row['tbl_row_id'];
                }

                // Use default values for public forms
                if (empty($tbl_row_ids) && !empty($field['default']))
                {
                    $tbl_row_ids = $field['default'];
                    if (!is_array($field['default']))
                    {
                        $tbl_row_ids = explode(',', $tbl_row_ids);
                        foreach ($tbl_row_ids as $row_key => $row_val)
                        {
                            $tbl_row_ids[$row_key] = trim($row_val);
                        }
                    }
                }

                // If the PICK column is unique, get values already chosen
                $unique_vals = false;
                if (1 == $field['unique'])
                {
                    $exclude = empty($pod_id) ? '' : "pod_id != $pod_id AND";
                    $result = pod_query("SELECT tbl_row_id FROM @wp_pod_rel WHERE $exclude field_id = $field_id");
                    if (0 < mysql_num_rows($result))
                    {
                        $unique_vals = array();
                        while ($row = mysql_fetch_assoc($result))
                        {
                            $unique_vals[] = $row['tbl_row_id'];
                        }
                        $unique_vals = implode(',', $unique_vals);
                    }
                }

                $params = array(
                    'table' => $table,
                    'field_name' => null,
                    'tbl_row_ids' => $tbl_row_ids,
                    'unique_vals' => $unique_vals,
                    'pick_filter' => $field['pick_filter'],
                    'pick_orderby' => $field['pick_orderby']
                );
                $this->data[$key] = $this->get_dropdown_values($params);
            }
            else
            {
                // Set a default value if no value is entered
                if (empty($this->data[$key]) && !empty($field['default']))
                {
                    $this->data[$key] = $field['default'];
                }
                else
                {
                    $this->data[$key] = empty($tbl_cols[$key]) ? null : $tbl_cols[$key];
                }
            }
            $this->build_field_html($field);
        }
        $uri_hash = md5($_SERVER['REQUEST_URI']);
?>
    <div>
    <input type="hidden" class="form num pod_id" value="<?php echo $pod_id; ?>" />
    <input type="hidden" class="form txt datatype" value="<?php echo $datatype; ?>" />
    <input type="hidden" class="form txt form_count" value="<?php echo $this->form_count; ?>" />
    <input type="hidden" class="form txt token" value="<?php echo pods_generate_key($datatype, $uri_hash, $public_columns, $this->form_count); ?>" />
    <input type="hidden" class="form txt uri_hash" value="<?php echo $uri_hash; ?>" />
    <input type="button" class="button btn_save" value="<?php echo $label; ?>" onclick="saveForm(<?php echo $this->form_count; ?>)" />
    </div>
<?php
    }

    /*
    ==================================================
    Display the pagination controls
    ==================================================
    */
    function getPagination($label = 'Go to page:')
    {
        if ($this->rpp < $this->getTotalRows())
        {
            include realpath(dirname(__FILE__) . '/pagination.php');
        }
    }

    /*
    ==================================================
    Display the list filters
    ==================================================
    */
    function getFilters($filters = null, $label = 'Filter', $action = '')
    {
        include realpath(dirname(__FILE__) . '/list_filters.php');
    }

    /*
    ==================================================
    Build public input form
    ==================================================
    */
    function publicForm($public_columns = null, $label = 'Save changes')
    {
        include realpath(dirname(__FILE__) . '/form.php');
    }

    /*
    ==================================================
    Build HTML for a single field
    ==================================================
    */
    function build_field_html($field)
    {
        include realpath(dirname(__FILE__) . '/input_fields.php');
    }

    /*
    ==================================================
    Display the page template
    ==================================================
    */
    function showTemplate($tpl, $code = null)
    {
        ob_start();

        if (empty($code))
        {
            $result = pod_query("SELECT code FROM @wp_pod_templates WHERE name = '$tpl' LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $code = $row['code'];
        }

        if (!empty($code))
        {
            // Only detail templates need $this->id
            if (empty($this->id))
            {
                while ($this->fetchRecord())
                {
                    $out = preg_replace_callback("/({@(.*?)})/m", array($this, "parse_magic_tags"), $code);
                    eval("?>$out");
                }
            }
            else
            {
                $out = preg_replace_callback("/({@(.*?)})/m", array($this, "parse_magic_tags"), $code);
                eval("?>$out");
            }
        }
        return ob_get_clean();
    }

    /*
    ==================================================
    Replace magic tags with their values
    ==================================================
    */
    function parse_magic_tags($in)
    {
        $name = $in[2];
        $before = $after = '';
        if (false !== strpos($name, ','))
        {
            list($name, $helper, $before, $after) = explode(',', $name);
        }
        if ('type' == $name)
        {
            return $this->datatype;
        }
        elseif ('detail_url' == $name)
        {
            return get_bloginfo('url') . '/' . preg_replace_callback("/({@(.*?)})/m", array($this, "parse_magic_tags"), $this->detail_page);
        }
        else
        {
            $value = $this->get_field($name);

            // Use helper if necessary
            if (!empty($helper))
            {
                $value = $this->pod_helper($helper, $value, $name);
            }
            if (null != $value && false !== $value)
            {
                return $before . $value . $after;
            }
        }
    }
}
