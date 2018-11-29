<?php
define('XDB_NEW', 'new');
define('XDB_APPROVED_NEW', 'approved_new');
define('XDB_INVALID_ID', '-1');

define('XDB_OVERRIDE_TS', true);
define('XDB_NO_OVERRIDE_TS', false); // default

define('XDB_USE_AI', true);
define('XDB_NO_USE_AI', false); // default

define('XDB_DEBUG_AREA_DISABLED', false);
define('XDB_DEBUG_AREA_ENABLED', true); // default

define('XDB_DB_NAME', 'db_name');
define('XDB_DEFAULT_DB_NAME', "ank/fizlesh.sqlite3");

define('XDB_DB_TYPE', 'db_type');

define('XDB_DB_TYPE_SQLITE3', "sqlite3");
define('XDB_DB_TYPE_PG', "pg");

define('XDB_DEFAULT_DB_TYPE', XDB_DB_TYPE_SQLITE3);
