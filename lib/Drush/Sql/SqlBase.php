<?php

namespace Drush\Sql;

use Drush\Log\LogLevel;
use Webmozart\PathUtil\Path;

class SqlBase {

  // An Drupal style array containing specs for connecting to database.
  public $db_spec;

  // Default code appended to sql-query connections.
  public $query_extra = '';

  // The way you pass a sql file when issueing a query.
  public $query_file = '<';

  /**
   * Typically, SqlBase objects are constructed via drush_sql_get_class().
   */
  public function __construct($db_spec = NULL) {
    $this->db_spec = $db_spec;
  }

  /*
   * Get the current $db_spec.
   */
  public function db_spec() {
    return $this->db_spec;
  }

  /**
   * The unix command used to connect to the database.
   * @return string
   */
  public function command() {}

  /**
   * A string for connecting to a database.
   *
   * @param bool $hide_password
   *  If TRUE, DBMS should try to hide password from process list.
   *  On mysql, that means using --defaults-extra-file to supply the user+password.
   *
   * @return string
   */
  public function connect($hide_password = TRUE) {
    return trim($this->command() . ' ' . $this->creds($hide_password) . ' ' . drush_get_option('extra', $this->query_extra));
  }


  /*
   * Execute a SQL dump and return the path to the resulting dump file.
   *
   * @param array @options
   *   The options array as passed to the Annotated Command.
   */
  public function dump($options) {
    /** @var string|bool $file Path where dump file should be stored. If TRUE, generate a path based on usual backup directory and current date.*/
    $file = $options['result-file'];
    $file_suffix = '';
    $table_selection = $this->getExpandedTableSelection($options);
    $file = $this->dumpFile($file);
    $cmd = $this->dumpCmd($table_selection);
    // Gzip the output from dump command(s) if requested.
    if ($options['gzip']) {
      $cmd .= ' | gzip -f';
      $file_suffix .= '.gz';
    }
    if ($file) {
      $file .= $file_suffix;
      $cmd .= ' > ' . drush_escapeshellarg($file);
    }

    // Avoid the php memory of the $output array in drush_shell_exec().
    if (!$return = drush_op_system($cmd)) {
      if ($file) {
        drush_log(dt('Database dump saved to !path', array('!path' => $file)), LogLevel::SUCCESS);
        drush_backend_set_result($file);
      }
    }
    else {
      return drush_set_error('DRUSH_SQL_DUMP_FAIL', 'Database dump failed');
    }
  }

  /*
   * Build bash for dumping a database.
   *
   * @param array $table_selection
   *   Supported keys: 'skip', 'structure', 'tables'.
   * @return string
   *   One or more mysqldump/pg_dump/sqlite3/etc statements that are ready for executing.
   *   If multiple statements are needed, enclose in parenthesis.
   */
  public function dumpCmd($table_selection) {}

  /*
   * Generate a path to an output file for a SQL dump when needed.
   *
   * @param string|bool @file
   *   If TRUE, generate a path based on usual backup directory and current date.
   *   Otherwise, just return the path that was provided.
   */
  public function dumpFile($file) {
    $database = $this->db_spec['database'];

    // $file is passed in to us usually via --result-file.  If the user
    // has set $options['result-file'] = TRUE, then we
    // will generate an SQL dump file in the same backup
    // directory that pm-updatecode uses.
    if ($file) {
      if ($file === TRUE) {
        // User did not pass a specific value for --result-file. Make one.
        $backup = drush_include_engine('version_control', 'backup');
        $backup_dir = $backup->prepare_backup_dir($database);
        if (empty($backup_dir)) {
          $backup_dir = drush_find_tmp();
        }
        $file = Path::join($backup_dir, '@DATABASE_@DATE.sql');
      }
      $file = str_replace(array('@DATABASE', '@DATE'), array($database, gmdate('Ymd_His')), $file);
    }
    return $file;
  }

  /**
   * Execute a SQL query.
   *
   * Note: This is an API function. Try to avoid using drush_get_option() and instead
   * pass params in. If you don't want to query results to print during --debug then
   * provide a $result_file whose value can be drush_bit_bucket().
   *
   * @param string $query
   *   The SQL to be executed. Should be NULL if $input_file is provided.
   * @param string $input_file
   *   A path to a file containing the SQL to be executed.
   * @param string $result_file
   *   A path to save query results to. Can be drush_bit_bucket() if desired.
   *
   * @return
   *   TRUE on success, FALSE on failure
   */
  public function query($query, $input_file = NULL, $result_file = '') {
    $input_file_original = $input_file;
    if ($input_file && drush_file_is_tarball($input_file)) {
      if (drush_shell_exec('gzip -d %s', $input_file)) {
        $input_file = trim($input_file, '.gz');
      }
      else {
        return drush_set_error(dt('Failed to decompress input file.'));
      }
    }

    // Save $query to a tmp file if needed. We will redirect it in.
    if (!$input_file) {
      $query = $this->query_prefix($query);
      $query = $this->query_format($query);
      $input_file = drush_save_data_to_temp_file($query);
    }

    $parts = array(
      $this->command(),
      $this->creds(),
      $this->silent(), // This removes column header and various helpful things in mysql.
      drush_get_option('extra', $this->query_extra),
      $this->query_file,
      drush_escapeshellarg($input_file),
    );
    $exec = implode(' ', $parts);

    if ($result_file) {
      $exec .= ' > '. drush_escapeshellarg($result_file);
    }

    // In --verbose mode, drush_shell_exec() will show the call to mysql/psql/sqlite,
    // but the sql query itself is stored in a temp file and not displayed.
    // We show the query when --debug is used and this function created the temp file.
    if ((drush_get_context('DRUSH_DEBUG') || drush_get_context('DRUSH_SIMULATE')) && empty($input_file_original)) {
      drush_log('sql-query: ' . $query, LogLevel::INFO);
    }

    $success = drush_shell_exec($exec);

    if ($success && drush_get_option('file-delete')) {
      drush_op('drush_delete_dir', $input_file);
    }

    return $success;
  }

  /*
   * A string to add to the command when queries should not print their results.
   */
  public function silent() {}


  public function query_prefix($query) {
    // Inject table prefixes as needed.
    if (drush_has_boostrapped(DRUSH_BOOTSTRAP_DRUPAL_DATABASE)) {
      // Enable prefix processing which can be dangerous so off by default. See http://drupal.org/node/1219850.
      if (drush_get_option('db-prefix')) {
        if (drush_drupal_major_version() >= 7) {
          $query = \Database::getConnection()->prefixTables($query);
        }
        else {
          $query = db_prefix_tables($query);
        }
      }
    }
    return $query;
  }


  public function query_format($query) {
    return $query;
  }

  /**
   * Drop specified database.
   *
   * @param array $tables
   *   An array of table names
   * @return boolean
   *   True if successful, FALSE if failed.
   */
  public function drop($tables) {
    $return = TRUE;
    if ($tables) {
      $sql = 'DROP TABLE '. implode(', ', $tables);
      $return = $this->query($sql);
    }
    return $return;
  }

  /**
   * Build a SQL string for dropping and creating a database.
   *
   * @param string dbname
   *   The database name.
   * @param boolean $quoted
   *   Quote the database name. Mysql uses backticks to quote which can cause problems
   *   in a Windows shell. Set TRUE if the CREATE is not running on the bash command line.
   */
  public function createdb_sql($dbname, $quoted = FALSE) {}

  /**
   * Create a new database.
   *
   * @param boolean $quoted
   *   Quote the database name. Mysql uses backticks to quote which can cause problems
   *   in a Windows shell. Set TRUE if the CREATE is not running on the bash command line.
   * @return boolean
   *   True if successful, FALSE otherwise.
   */
  public function createdb($quoted = FALSE) {
    $dbname = $this->db_spec['database'];
    $sql = $this->createdb_sql($dbname, $quoted);
    // Adjust connection to allow for superuser creds if provided.
    $this->su();
    return $this->query($sql);
  }

  /**
   * Drop all tables (if DB exists) or CREATE target database.
   *
   * return boolean
   *   TRUE or FALSE depending on success.
   */
  public function drop_or_create() {
    if ($this->db_exists()) {
      return $this->drop($this->listTables());
    }
    else {
      return $this->createdb();
    }
  }

  /*
   * Determine if the specified DB already exists.
   *
   * @return bool
   */
  public function db_exists() {}

  public function delete() {}

  /**
   * Build a fragment connection parameters.
   *
   * @param bool $hide_password
   *  If TRUE, DBMS should try to hide password from process list.
   *  On mysql, that means using --defaults-extra-file to supply the user+password.
   * @return string
   */
  public function creds($hide_password = TRUE) {}

  /**
   * The active database driver.
   * @return string
   */
  public function scheme() {
    return $this->db_spec['driver'];
  }

  /**
   * Get a list of all table names and expand input that may contain
   * wildcards (`*`) if necessary so that the array returned only contains valid
   * table names i.e. actual tables that exist, without a wildcard.
   *
   * @param array $options An options array as passed to an Annotated Command.
   *
   * @return array
   *   An array of tables with each table name in the appropriate
   *   element of the array.
   */
  public function getExpandedTableSelection($options) {
    $table_selection = $this->getTableSelection($options);
    // Get the existing table names in the specified database.
    $db_tables = $this->listTables();
    if (isset($table_selection['skip'])) {
      $table_selection['skip'] = $this->expandAndFilterTables($table_selection['skip'], $db_tables);
    }
    if (isset($table_selection['structure'])) {
      $table_selection['structure'] = $this->expandAndFilterTables($table_selection['structure'], $db_tables);
    }
    if (isset($table_selection['tables'])) {
      $table_selection['tables'] = $this->expandAndFilterTables($table_selection['tables'], $db_tables);
    }
    return $table_selection;
  }

  /**
   * Given the table names in the input array that may contain wildcards (`*`),
   * expand the table names so that the array returned only contains table names
   * that exist in the database.
   *
   * @param array $tables
   *   An array of table names where the table names may contain the
   *   `*` wildcard character.
   * @param array $db_tables
   *   The list of tables present in a database.
   * @return array
   *   An array of tables with non-existant tables removed.
   */
  public function expandAndFilterTables($tables, $db_tables) {
    $expanded_tables = $this->ExpandWildcardTables($tables, $db_tables);
    $tables = $this->filterTables(array_merge($tables, $expanded_tables), $db_tables);
    $tables = array_unique($tables);
    sort($tables);
    return $tables;
  }

  /**
   * Expand wildcard tables.
   *
   * @param array $tables
   *   An array of table names, some of which may contain wildcards (`*`).
   * @param array $db_tables
   *   An array with all the existing table names in the current database.
   * @return
   *   $tables array with wildcards resolved to real table names.
   */
  public function expandWildcardTables($tables, $db_tables) {
    // Table name expansion based on `*` wildcard.
    $expanded_db_tables = array();
    foreach ($tables as $k => $table) {
      // Only deal with table names containing a wildcard.
      if (strpos($table, '*') !== FALSE) {
        $pattern = '/^' . str_replace('*', '.*', $table) . '$/i';
        // Merge those existing tables which match the pattern with the rest of
        // the expanded table names.
        $expanded_db_tables += preg_grep($pattern, $db_tables);
      }
    }
    return $expanded_db_tables;
  }

  /**
   * Filters tables.
   *
   * @param array $tables
   *   An array of table names to filter.
   * @param array $db_tables
   *   An array with all the existing table names in the current database.
   * @return
   *   An array with only valid table names (i.e. all of which actually exist in
   *   the database).
   */
  function filterTables($tables, $db_tables) {
    // Ensure all the tables actually exist in the database.
    foreach ($tables as $k => $table) {
      if (!in_array($table, $db_tables)) {
        unset($tables[$k]);
      }
    }

    return $tables;
  }

  /**
   * Construct an array that places table names in appropriate
   * buckets based on whether the table is to be skipped, included
   * for structure only, or have structure and data dumped.
   * The keys of the array are:
   * - skip: tables to be skipped completed in the dump
   * - structure: tables to only have their structure i.e. DDL dumped
   * - tables: tables to have structure and data dumped
   *
   * @return array
   *   An array of table names with each table name in the appropriate
   *   element of the array.
   */
  public function getTableSelection() {
    // Skip large core tables if instructed.  Used by 'sql-drop/sql-dump/sql-sync' commands.
    $skip_tables = $this->getRawTableList('skip-tables');
    // Skip any structure-tables as well.
    $structure_tables = $this->getRawTableList('structure-tables');
    // Dump only the specified tables.  Takes precedence over skip-tables and structure-tables.
    $tables = $this->getRawTableList('tables');

    return array('skip' => $skip_tables, 'structure' => $structure_tables, 'tables' => $tables);
  }

  /**
   * Consult the specified options and return the list of tables specified.
   *
   * @param option_name
   *   The option name to check: skip-tables, structure-tables
   *   or tables.  This function will check both *-key and *-list.
   * @param array $options An options array as passed to an Annotated Command.
   * @return array
   *   Returns an array of tables based on the first option
   *   found, or an empty array if there were no matches.
   */
  public function getRawTableList($option_name, $options) {
    $key_list = $options[$option_name . '-key'];
    foreach(explode(',', $key_list) as $key) {
      $all_tables = $options[$option_name] ?: [];
      if (array_key_exists($key, $all_tables)) {
        return $all_tables[$key];
      }
      if ($option_name != 'tables') {
        $all_tables = $options['tables'] ?: [];
        if (array_key_exists($key, $all_tables)) {
          return $all_tables[$key];
        }
      }
    }
    $table_list = $options[$option_name . '-list'];
    if (isset($table_list)) {
      return empty($table_list) ? [] : explode(',', $table_list);
    }
    return [];
  }

  /**
   * Extract the name of all existing tables in the given database.
   *
   * @return array|null
   *   An array of table names which exist in the current database.
   */
  public function listTables() {}

  /*
   * Helper method to turn associative array into options with values.
   *
   * @return string
   *   A bash fragment.
   */
  public function params_to_options($parameters) {
    // Turn each parameter into a valid parameter string.
    $parameter_strings = array();
    foreach ($parameters as $key => $value) {
      // Only escape the values, not the keys or the rest of the string.
      $value = drush_escapeshellarg($value);
      $parameter_strings[] = "--$key=$value";
    }

    // Join the parameters and return.
    return implode(' ', $parameter_strings);
  }

  /**
   * Adjust DB connection with superuser credentials if provided.
   *
   * The options 'db-su' and 'db-su-pw' will be retrieved from the
   * specified site alias record, if it exists and contains those items.
   * If it does not, they will be fetched via drush_get_option.
   *
   * Note that in the context of sql-sync, the site alias record will
   * be taken from the target alias (e.g. `drush sql-sync @source @target`),
   * which will be overlayed with any options that begin with 'target-';
   * therefore, the commandline options 'target-db-su' and 'target-db-su-pw'
   * may also affect the operation of this function.
   *
   * @return null
   */
  public function su() {
    $create_db_target = $this->db_spec;

    $create_db_target['database'] = '';
    $db_superuser = drush_get_option('db-su');
    if (isset($db_superuser)) {
      $create_db_target['username'] = $db_superuser;
    }
    $db_su_pw = drush_get_option('db-su-pw');
    // If --db-su-pw is not provided and --db-su is, default to empty password.
    // This way db cli command will take password from .my.cnf or .pgpass.
    if (!empty($db_su_pw)) {
      $create_db_target['password'] = $db_su_pw;
    }
    elseif (isset($db_superuser)) {
      unset($create_db_target['password']);
    }
    $this->db_spec = $create_db_target;
  }

  /**
   * @deprecated as of 9.0. Use $this->getExpandedTableSelection().
   */
  public function get_expanded_table_selection() {
    return $this->getExpandedTableSelection();
  }
}
