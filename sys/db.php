<?php

/**
  * @author Mikhail Veltishchev <dichlofos-mv@yandex.ru>
  * Database management module
  **/
global $full_xengine_dir;
global $xengine_dir;

require_once("${full_xengine_dir}sys/db_const.php");
require_once("${full_xengine_dir}sys/string.php");
require_once("${full_xengine_dir}sys/auth.php");

/**
  * Helper for obtaining keys that are DB ids from request. Filters all
  * non-numeric characters from input, returns XDB_INVALID_ID (-1) by default
  * or when all characters were filtered.
  **/
function xdb_get_idvar($key, $default_value = XDB_INVALID_ID, $allowed_values = array())
{
    $value = xcms_get_key_or($_GET, $key, '');
    if ($value == XDB_NEW || array_search($value, $allowed_values) !== false) {
        return $value;
    }
    $value = xcms_filter_nondigits($value);
    if (xu_empty($value)) {
        return $default_value;
    }
    return $value;
}


/**
  * Proper escaping, even for NULL values.
  **/
function xdb_quote($db, $value)
{
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return "'".$db->escapeString($value)."'";
    } else {
        if ($value === null) {
            return "NULL";
        }
        return "'".pg_escape_string($db, $value)."'";
    }
}


/**
  * Proper value encoding for TRUE condition
  **/
function xdb_true()
{
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return " (1) ";
    } else {
        return " true ";
    }
}


/**
  * Proper value encoding for FALSE condition
  **/
function xdb_false()
{
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return " (0) ";
    } else {
        return " false ";
    }
}


/**
  * Helper for obtaining enum values from request. Filters all
  * invalid characters from input.
  **/
function xdb_get_enumvar($key)
{
    $value = xcms_get_key_or($_GET, $key, "");
    $value = preg_replace('/[^a-zA-Z0-9-]/', "", $value);
    return $value;
}

/**
  * Obtain database type
  **/
function xdb_get_type()
{
    global $SETTINGS;
    return xcms_get_key_or($SETTINGS, XDB_DB_TYPE, XDB_DEFAULT_DB_TYPE);
}

/**
  * Postgress-specific DB connection creator.
  **/
function xdb_get_pg($rel_db_name)
{
    global $XDB_CONNECTION;
    if ($XDB_CONNECTION) {
        return $XDB_CONNECTION;
    }
    $XDB_CONNECTION = pg_connect($rel_db_name);
    return $XDB_CONNECTION;
}


/**
  * Obtains database handle in read-only mode
  **/
function xdb_get()
{
    global $SETTINGS;
    global $content_dir;
    $rel_db_name = xcms_get_key_or($SETTINGS, XDB_DB_NAME, XDB_DEFAULT_DB_NAME);
    $db_type = xdb_get_type();
    if ($db_type == XDB_DB_TYPE_SQLITE3) {
        $db_name = $content_dir.$rel_db_name;
        $db = new SQlite3($db_name, SQLITE3_OPEN_READONLY);
        // enhance LIKE immediately to obtain proper UTF-8 support
        $db->createFunction('LIKE', 'xdb_like', 2);
        return $db;
    } else {
        return xdb_get_pg($rel_db_name);
    }
}


/**
  * Obtains database handle in writeable mode
  * @return db database handle
  **/
function xdb_get_write()
{
    global $SETTINGS;
    global $content_dir;
    $rel_db_name = xcms_get_key_or($SETTINGS, XDB_DB_NAME, XDB_DEFAULT_DB_NAME);
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        $db_name = $content_dir.$rel_db_name;
        xcms_log(XLOG_INFO, "[DB] Obtaining db write lock");
        return new SQlite3($db_name, SQLITE3_OPEN_READWRITE);
    } else {
        return xdb_get_pg($rel_db_name);
    }
}

/**
  * Close existing database connection
  * @param db existing database connection
  **/
function xdb_close($db)
{
    xcms_log(XLOG_INFO, "[DB] Closing database");
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        $db->close();
    } else {
        // pg_close($db);
    }
}


/**
  * Obtains database last error
  **/
function xdb_last_error($db)
{
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return $db->lastErrorMsg();
    } else {
        return pg_last_error($db);
    }
}

/**
  * Execute arbitrary statement (string)
  * @param db existing database connection
  * @param query query string, properly escaped and well-formed
  **/
function xdb_query($db, $query)
{
    xcms_log(XLOG_INFO, "[DB] Executing query: $query");
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return $db->query($query);
    } else {
        return pg_query($db, $query);
    }
}


/**
  * Fetch one row from selector
  **/
function xdb_fetch($selector)
{
    if (xdb_get_type() == XDB_DB_TYPE_SQLITE3) {
        return $selector->fetchArray(SQLITE3_ASSOC);
    } else {
        return pg_fetch_assoc($selector);
    }
}

/**
  * Inserts or updates DB record
  * @param $table_name table name to update/insert into
  * @param $primary_keys KV-array of table primary keys
  * @note If PK value has the special value XDB_NEW, the insertion is performed
  * and table should have AI key
  * @param $values KV-array of row values
  * @param $allowed_keys only these keys will be taken from $values
  * @param $outer_db use given external database (not used by default)
  * @return true on successful update, false on error
  * and autoincremented field id on insertion
  **/
function xdb_insert_or_update($table_name, $primary_keys, $values, $allowed_keys, $outer_db = null)
{
    $autoincrement = false;
    $pk_name = null;
    // detect autoincrement key
    foreach ($primary_keys as $key => $value) {
        if ($value == XDB_NEW) {
            // autoincrement detected, key should be unuque
            $autoincrement = true;
            $pk_name = $key;
            // in case of autoincrement keys, $primary_keys should be one-element
            if (count($primary_keys) != 1) {
                xcms_log(XLOG_ERROR, "Autoincremented key array should be one-element");
                throw new Exception("Autoincremented key array should be one-element for $table_name");
            }
        }
    }
    if ($autoincrement) {
        return xdb_insert_ai(
            $table_name,
            $pk_name,
            $values,
            $allowed_keys,
            XDB_OVERRIDE_TS,
            XDB_USE_AI,
            $outer_db
        );
    } else {
        return xdb_update($table_name, $primary_keys, $values, $allowed_keys, XDB_OVERRIDE_TS, $outer_db);
    }
}


/**
  * Insert single record into autoincrement table
  * @param $table_name Table name to insert into
  * @param $pk_name primary key name (autoincrement)
  * @param $keys_values KV-array of row values
  * @param $allowed_keys only these keys will be taken from $values
  * @param $override_ts override timestamps (true by default)
  * @param $ignore_ai ignore autoincrement keys, use value from $keys_values
  * @param $outer_db use given external database (not used by default)
  *
  * Special fields,
  * ${table_name}_created,
  * ${table_name}_modifed,
  * are filled using current UTC time value in human-readable form
  * (that can be converted back to timestamp, though)
  * and ${table_name}_changedby representing last user name
  * so they should always present in any table.
  **/
function xdb_insert_ai(
    $table_name,
    $pk_name,
    $keys_values,
    $allowed_keys,
    $override_ts = XDB_OVERRIDE_TS,
    $use_ai = XDB_USE_AI,
    $outer_db = null
) {
    if (is_array($pk_name)) {
        throw new Exception("xdb_insert_ai supports only string PKs. ");
    }
    $db_type = xdb_get_type();

    $db = null;
    if ($outer_db === null) {
        $db = xdb_get_write();
    } else {
        $db = $outer_db;
    }
    $keys = "";
    $values = "";

    if ($override_ts) {
        if ($db_type == XDB_DB_TYPE_PG) {
            $keys_values["${table_name}_created"] = xcms_datetime()."+03";
            $keys_values["${table_name}_modified"] = null;
        } else {
            $keys_values["${table_name}_created"] = xcms_datetime();
            $keys_values["${table_name}_modified"] = "";
        }
    }
    // for audit purposes
    $keys_values["${table_name}_changedby"] = xcms_user()->login();

    foreach ($allowed_keys as $key => $unused) {
        $value = xcms_get_key_or($keys_values, $key);
        if ($use_ai and $key == $pk_name) {
            continue; // skip autoincremented keys
        }
        $keys .= "$key, ";
        $quoted_value = xdb_quote($db, $value);
        $values .= "$quoted_value, ";
    }
    $keys = substr($keys, 0, strlen($keys) - 2);
    $values = substr($values, 0, strlen($values) - 2);

    $query = "INSERT INTO $table_name ($keys) VALUES ($values)";
    if ($db_type == XDB_DB_TYPE_PG) {
        // last_inserted_id replacement for pg
        $query = "$query RETURNING $pk_name";
    }
    $result = null;
    $selector = xdb_query($db, $query);
    if ($selector) {
        if ($db_type == XDB_DB_TYPE_SQLITE3) {
            $result = $db->lastInsertRowid();
        } else {
            $fetched_result = pg_fetch_assoc($selector);
            $result = $fetched_result[$pk_name];
        }
        xcms_log(XLOG_INFO, "[DB] $query [OUT $result]");
    } else {
        $result = false;
        $error_message = xdb_last_error($db);
        xcms_log(XLOG_ERROR, "[DB] $query. Error: $error_message");
    }
    if ($outer_db === null) {
        xdb_close($db);
    }
    return $result;
}


/**
  * Updates one row using given primary keys' values,
  * also updates ${table_name}_modified timestamp
  * @param $table_name Table name to update
  * @param $primary_keys KV-array of table primary keys
  * @param $keys_values KV-array of row values
  * @param $allowed_keys only these keys will be taken from $values
  * @param $override_ts override timestamps (true by default)
  * @param $outer_db use given external database (not used by default)
  *
  * A special field, ${table_name}_modified, will be updated
  * using current UTC time value in human-readable form
  * @sa xdb_insert_ai
  * @return true in case of success, false otherwise
  **/
function xdb_update(
    $table_name,
    $primary_keys,
    $keys_values,
    $allowed_keys,
    $override_ts = XDB_OVERRIDE_TS,
    $outer_db = null
) {
    $db = null;
    if ($outer_db === null) {
        $db = xdb_get_write();
    } else {
        $db = $outer_db;
    }
    $values = "";
    if ($override_ts) {
        $keys_values["${table_name}_modified"] = xcms_datetime();
    }
    // for audit purposes
    $keys_values["${table_name}_changedby"] = xcms_user()->login();

    foreach ($keys_values as $key => $value) {
        if (!array_key_exists($key, $allowed_keys)) {
            continue; // skip keys that are not in scheme
        }

        if ($key == "${table_name}_created") {
            continue; // never update 'created' field
        }

        if (array_key_exists($key, $primary_keys)) {
            continue; // skip primary keys
        }

        $quoted_value = xdb_quote($db, $value);
        $values .= "$key = $quoted_value, ";
    }
    $values = substr($values, 0, strlen($values) - 2);

    $cond = "";
    foreach ($primary_keys as $key => $value) {
        $value = xdb_quote($db, $value);
        $cond .= "($key = $value) AND ";
    }
    $cond = substr($cond, 0, strlen($cond) - 5);
    $query = "UPDATE $table_name SET $values WHERE $cond";
    $result = xdb_query($db, $query);
    if ($result) {
        xcms_log(XLOG_INFO, "[DB] $query");
    } else {
        $error_message = xdb_last_error($db);
        xcms_log(XLOG_ERROR, "[DB] $query. Error: $error_message");
    }
    if ($outer_db === null) {
        xdb_close($db);
    }
    return $result ? true : false;
}

/**
  * Retrieves KV-array of values for the given ID from table.
  * @param $table_name Table name to get data from
  * @param $id primary key value (compound keys are not supported)
  * @param string_key boolean flag indicating that key should not be filtered
  * by numeric rules (for SQL injections safety).
  * @return KV-array of row values
  * A convention states that primary keys in our tables have
  * special names obtained from table name: ${table_name}_id
  *
  * If the $id has the magic value XDB_NEW, the empty record is
  * returned
  **/
function xdb_get_entity_by_id($table_name, $id, $string_key = false)
{
    $key_name = "${table_name}_id";

    if ($id != XDB_NEW) {
        $db = xdb_get();
        $idf = $id;
        if (!$string_key) {
            $idf = preg_replace('/[^-0-9]/', '', $id);
        }
        if (strlen($idf) == 0) {
            xcms_log(
                XLOG_ERROR,
                "[DB] It's not possible to fetch entity from '$table_name' with empty or filtered id '$id'."
            );
            return array();
        }
        $id = $idf;
        if ($string_key) {
            $id = xdb_quote($db, $id);
        }

        $query = "SELECT * FROM $table_name WHERE $key_name = $id";
        $sel = xdb_query($db, $query);
        if (!($ev = xdb_fetch($sel))) {
            // FIXME(mvel): last error call
            $error_message = xdb_last_error($db);
            xcms_log(
                XLOG_WARNING,
                "[DB] No entity from '$table_name' with id: '$id'. Query: $query. Error: $error_message"
            );
            return array();
        }
        xdb_close($db);
    } else {
        // new record
        $ev = array(
            $key_name => $id,
        );
    }
    return $ev;
}

// FIXME(mvel): get rid of this function, replace with SELECT COUNT(*)
function xdb_query_length($selector)
{
    $selector_copy = $selector;
    $count = 0;
    if (!$selector_copy) {
        return 0;
    }
    while (xdb_fetch($selector_copy)) {
        ++$count;
    }
    return $count;
}

// FIXME(mvel): fix style and tell Yarik
function resultSetToArray($queryResultSet)
{
    $multiArray = array();
    $count = 0;
    if (!$queryResultSet) {
        return array();
    }
    while ($row = xdb_fetch($queryResultSet)) {
        foreach ($row as $i => $value) {
            $multiArray[$count][$i] = $value;
        }
        $count++;
    }
    return $multiArray;
}
// End of code style fixes

function xdb_get_filtered($table_name, $keys)
{
    $db = xdb_get();
    $filter = "1=1";
    foreach ($keys as $key => $value) {
        $filter = "$filter AND $key=\"$value\"";
    }
    $query = "SELECT * FROM $table_name WHERE $filter";
    $sel = xdb_query($db, $query);
    if (!($ev = resultSetToArray($sel))) {
        $error_message = xdb_last_error($db);
        xcms_log(
            XLOG_WARNING,
            "[DB] No entries from '$table_name' with keys: '$keys'. Query: $query. Last error: $error_message"
        );
        xdb_close($db);
        return array();
    }
    xdb_close($db);
    return $ev;
}

/**
  * Deletes record with the given ID from table.
  * @param $table_name Table name to delete data from
  * @param $id primary key value (compound keys are not supported)
  * @param $outer_db use given external database (not used by default)
  * @return operation result (BUG: db-specific!)
  *
  * A convention states that primary keys in our tables have
  * special names obtained from table name: ${table_name}_id
  *
  * If the $id has the magic value XDB_NEW, the empty record is
  * returned
  **/
function xdb_delete($table_name, $key_value, $outer_db = null)
{
    $db = ($outer_db === null) ? xdb_get_write() : $outer_db;
    $key_value = xdb_quote($db, $key_value);
    $cond = "${table_name}_id = $key_value";
    $query = "DELETE FROM $table_name WHERE $cond";
    $result = xdb_query($db, $query);
    if ($result) {
        xcms_log(XLOG_INFO, "[DB] $query");
    } else {
        xcms_log(XLOG_ERROR, "[DB] $query");
    }
    if ($outer_db === null) {
        xdb_close($db);
    }
    return $result;
}

/**
  * Selects first fetched object.
  * Useful when query should typicaly return one record or less.
  * @param $db database handle
  * @param $query query
  **/
function xdb_fetch_one($db, $query)
{
    $sel = xdb_query($db, $query);
    if (!($obj = xdb_fetch($sel))) {
        $error_message = xdb_last_error($db);
        xcms_log(XLOG_WARNING, "[DB] No objects fetched using query: $query. Last error: $error_message");
        return array();
    }
    return $obj;
}


/**
  * Shortcut for SELECT COUNT(*) requests
  **/
function xdb_count($db, $query)
{
    $obj = xdb_fetch_one($db, $query);
    if (!$obj) {
        return 0;
    }
    return (integer)(xcms_get_key_or($obj, "cnt", "0"));
}


/**
  * UTF8-friendly LIKE operator for SQLite database
  * @note this function is for INTERNAL use only
  * @param $mask LIKE operator mask
  * @param $value value to match
  * @return matches or not (boolean value)
  **/
function xdb_like($mask, $value)
{
    $mask = str_replace(
        array("%", "_"),
        array(".*?", "."),
        preg_quote($mask, "/")
    );
    $mask = "/^$mask$/ui";
    return preg_match($mask, $value);
}


/**
  * Returns whole content of the table in array
  * using given filter (WHERE condition) and order
  * @param table_name table name
  * @param filter WHERE clause
  * @param order ORDER BY clause
  **/
function xdb_get_table($table_name, $filter = '', $order = '')
{
    $db = xdb_get();
    $query = "SELECT * FROM $table_name";
    if (strlen($filter)) {
        $query .= " WHERE $filter ";
    }
    if (strlen($order)) {
        $query .= " ORDER BY $order ";
    }
    $sel = xdb_query($db, $query);
    $ans = array();
    while ($obj = xdb_fetch($sel)) {
        $ans[] = $obj;
    }
    xdb_close($db);
    return $ans;
}

/**
  * Returns whole content of the table in associative array by PK
  * using given filter (WHERE condition) and order
  * @param table_name table name
  * @param filter WHERE clause
  * @param order ORDER BY clause
  **/
function xdb_get_table_by_pk($table_name, $filter = '', $order = '')
{
    $ans = xdb_get_table($table_name, $filter, $order);
    $result = array();
    $key_name = "${table_name}_id";
    foreach ($ans as $obj) {
        $key = $obj[$key_name];
        $result[$key] = $obj;
    }
    return $result;
}



function xdb_open_db_write($db_name)
{
    xcms_log(XLOG_INFO, "[DB] Open database '$db_name' for WRITING");
    return new SQlite3($db_name, SQLITE3_OPEN_READWRITE);
}


function xdb_get_selector($db, $table_name)
{
    $query = "SELECT * FROM $table_name";
    return xdb_query($db, $query);
}


function xdb_drop_column($db, $table_name, $column_name, $create_table)
{
    // create new table
    xdb_query($db, $create_table);
    // copy data
    $sel = xdb_get_selector($db, $table_name);
    $objects = 0;
    while ($obj = xdb_fetch($sel)) {
        $idn = "${table_name}_id";
        $object_id = $obj[$idn];
        unset($obj[$column_name]); // specific code
        xdb_insert_ai("${table_name}_new", $idn, $obj, $obj, XDB_OVERRIDE_TS, XDB_NO_USE_AI, $db);
        ++$objects;
    }

    // rename table
    xdb_query($db, "DROP TABLE $table_name");
    xdb_query($db, "ALTER TABLE ${table_name}_new RENAME TO $table_name");
    xcms_log(XLOG_INFO, "[DB] Dropped $column_name from $table_name, processed $objects objects");
}


function xdb_vacuum($db)
{
    xdb_query($db, "VACUUM");
    xcms_log(XLOG_INFO, "[DB] Database vacuumed");
}

function xdb_unit_test()
{
    xut_begin("db");
    $db_type = xdb_get_type();

    $db = xdb_get_write();

    // clean all test tables
    $cleanup_query = "DROP TABLE IF EXISTS test";
    xdb_query($db, $cleanup_query);

    // create tables
    if ($db_type == XDB_DB_TYPE_PG) {
        $create_table_query = "CREATE TABLE test (
            test_id serial primary key,
            test_title text,
            test_created timestamp with time zone DEFAULT now(),
            test_modified timestamp with time zone DEFAULT now(),
            test_changedby text
        )";
    } else {
        $create_table_query = "CREATE TABLE test (
            test_id integer primary key autoincrement,
            test_title text,
            test_created text,
            test_modified text,
            test_changedby text
        )";
    }
    xdb_query($db, $create_table_query);

    // test select from empty table
    $selected = false;
    $selector = xdb_query($db, "SELECT * FROM test");
    while ($test_object = xdb_fetch($selector)) {
        $selected = true;
    }
    xut_check(!$selected, "Table should be empty. ");

    // test autoincrement insertion
    $values = array("test_title"=>"test1 title");
    $result = xdb_insert_ai("test", "test_id", $values, $values);
    xut_equal("$result", "1", "Inserted ID should be 1. ");

    // refresh selector
    $selected = false;
    $selector = xdb_query($db, "SELECT * FROM test");
    while ($test_object = xdb_fetch($selector)) {
        $selected = true;
    }
    xut_check($selected, "Table should not be empty. ");

    $selector = xdb_query($db, "DELETE FROM test");
    $tz_offset = ($db_type == XDB_DB_TYPE_PG) ? "+03" : "";
    $keys_values_override_ts = array(
        "test_title" => "override_ts",
        "test_created" => "2018-01-02 03:04:05$$tz_offset",
        "test_modified" => "2018-01-02 03:04:05$tz_offset",
    );
    xdb_insert_ai("test", "test_id", $keys_values_override_ts, $keys_values_override_ts);

    $keys_values_no_override_ts = array(
        "test_title" => "no_override_ts",
        "test_created" => "2018-01-02 03:04:05$tz_offset",
        "test_modified" => "2018-01-02 03:04:05$tz_offset",
    );
    xdb_insert_ai("test", "test_id", $keys_values_no_override_ts, $keys_values_no_override_ts, XDB_NO_OVERRIDE_TS);

    $selector = xdb_query($db, "SELECT * FROM test");
    $selected_data = array();
    while ($test_object = xdb_fetch($selector)) {
        if ($test_object["test_title"] == "override_ts") {
            xut_check(
                $test_object["test_created"] != $keys_values_override_ts["test_created"],
                "Override should override creation ts"
            );
        }
        if ($test_object["test_title"] == "no_override_ts") {
            xut_equal(
                $test_object["test_created"],
                $keys_values_no_override_ts["test_created"],
                "NoOverrideTs should not override creation timestamp"
            );
        }
        $selected_data[] = $test_object;
        //print_r($test_object);
    }
    xut_equal(count($selected_data), 2, "Invalid row count selected");

    xut_end();
}

/**
  * Embedded query debugger
  **/
function xdb_debug_area($query, $enabled = XDB_DEBUG_AREA_ENABLED)
{
    $query = str_replace("\n", " ", $query);
    $query = str_replace("\r", " ", $query);
    $query = str_replace("\t", " ", $query);
    $query = preg_replace("/ +/", " ", $query);
    ?><textarea rows="5" cols="120" style="display: <?php echo ($enabled ? "" : "none"); ?>;"
        id="person-query-debug"><?php echo $query; ?></textarea><?php
}
