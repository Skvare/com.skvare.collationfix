<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Analyzes database tables/columns for utf8/utf8mb3 -> utf8mb4 conversion.
 *
 * Ported from Skvare's standalone conversion.php CLI script, with fixes:
 * - Tells expression defaults from literal ones by how SHOW CREATE TABLE
 *   writes them, so neither is rewritten as the other.
 * - Escapes DEFAULT and COMMENT values with the connection's escaper.
 * - Redefines generated columns with their generation expression, read
 *   from SHOW CREATE TABLE.
 * - Handles both utf8_* (MySQL 5.7) and utf8mb3_* (MySQL 8) collation names.
 * - Adds size / index-key-length pre-flight warnings.
 */
class CRM_Collationfix_Analyzer {

  const NEW_CHARSET = 'utf8mb4';
  const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';
  const NEW_BINARY_COLLATION = 'utf8mb4_bin';
  const CIVICRM_TABLE_PREFIX = 'civicrm\\_';
  const LOG_TABLE_PREFIX = 'log\\_';
  const TARGET_COLLATION_SETTING = 'collationfix_target_collation';

  /**
   * Engines of detailed-logging tables that are converted. ARCHIVE was the
   * default for log tables before InnoDB.
   */
  const LOG_TABLE_ENGINES = ['InnoDB', 'ARCHIVE'];

  /**
   * Table size (in bytes) above which a "large table" warning is raised.
   */
  const LARGE_TABLE_BYTES = 524288000; // 500 MB

  /**
   * @var string
   */
  protected $database;

  /**
   * Collation that non-binary utf8 columns and table defaults convert to.
   *
   * @var string
   */
  protected $targetCollation;

  /**
   * @var string|null
   */
  protected $serverVersion;

  /**
   * @var array|null
   */
  protected $serverDetails;

  /**
   * @var string|null
   */
  protected $loggingDatabase;

  /**
   * Log table name => TRUE.
   *
   * @var array|null
   */
  protected $logTableNames;

  /**
   * Problems reading the logging database, keyed to avoid repeats.
   *
   * @var array
   */
  protected $loggingErrors = [];

  /**
   * @param string|null $targetCollation
   *   Collation to convert to; defaults to the target collation setting. A
   *   queued run passes the collation that was confirmed, so a setting change
   *   mid-run cannot give its tables different collations.
   *
   * @throws CRM_Core_Exception
   */
  public function __construct($targetCollation = NULL) {
    $this->database = CRM_Core_DAO::getDatabaseName();
    if ($targetCollation === NULL) {
      $this->targetCollation = self::resolveTargetCollation();
    }
    elseif (self::validateTargetCollation($targetCollation)) {
      $this->targetCollation = $targetCollation;
    }
    else {
      throw new CRM_Core_Exception("Unsupported target collation: {$targetCollation}");
    }
  }

  /**
   * Get the name of the connected database.
   *
   * @return string
   */
  public function getDatabaseName() {
    return $this->database;
  }

  /**
   * @return string
   */
  public function getTargetCollation() {
    return $this->targetCollation;
  }

  /**
   * Read the target collation setting, falling back to the default when it
   * is unset or not a valid option on this server. The value is interpolated
   * into ALTER statements, so it is never used unvalidated.
   *
   * @return string
   */
  protected static function resolveTargetCollation() {
    $collation = (string) Civi::settings()->get(self::TARGET_COLLATION_SETTING);
    return isset(self::getTargetCollationOptions()[$collation]) ? $collation : self::DEFAULT_COLLATION;
  }

  /**
   * Options for the target collation setting: every non-binary utf8mb4
   * collation the server supports. Binary collations are excluded because
   * _bin columns always map to utf8mb4_bin, and a binary table default would
   * make every text comparison case-sensitive.
   *
   * @return array
   */
  public static function getTargetCollationOptions() {
    $options = [];
    $dao = CRM_Core_DAO::executeQuery("SHOW COLLATION WHERE Charset = 'utf8mb4'");
    while ($dao->fetch()) {
      if (substr($dao->Collation, -4) !== '_bin') {
        $options[$dao->Collation] = $dao->Collation;
      }
    }
    ksort($options);
    return $options;
  }

  /**
   * Setting validate_callback for the target collation.
   *
   * @param mixed $value
   *
   * @return bool
   */
  public static function validateTargetCollation(&$value) {
    return is_string($value) && isset(self::getTargetCollationOptions()[$value]);
  }

  /**
   * Get MySQL/MariaDB server version string.
   *
   * @return string
   */
  public function getServerVersion() {
    if ($this->serverVersion === NULL) {
      $this->serverVersion = (string) CRM_Core_DAO::singleValueQuery('SELECT VERSION()');
    }
    return $this->serverVersion;
  }

  /**
   * @return bool
   */
  public function isMariaDb() {
    return stripos($this->getServerVersion(), 'mariadb') !== FALSE;
  }

  /**
   * Get server, database and connection variables relevant to a utf8mb4
   * conversion (charset/collation defaults, InnoDB row-format/prefix/page
   * settings, packet size). Variables that don't exist on this server/version
   * (e.g. innodb_large_prefix was removed in newer MySQL/MariaDB) are simply
   * omitted rather than erroring.
   *
   * @return array
   *   Variable name => value.
   */
  public function getServerDetails() {
    if ($this->serverDetails !== NULL) {
      return $this->serverDetails;
    }
    $variables = [
      'character_set_server',
      'collation_server',
      'character_set_database',
      'collation_database',
      'character_set_connection',
      'collation_connection',
      'innodb_file_per_table',
      'innodb_large_prefix',
      'innodb_default_row_format',
      'innodb_page_size',
      'max_allowed_packet',
    ];
    $details = [];
    foreach ($variables as $name) {
      $dao = CRM_Core_DAO::executeQuery("SHOW VARIABLES LIKE %1", [1 => [$name, 'String']]);
      if ($dao->fetch()) {
        $details[$name] = $dao->Value;
      }
    }
    $this->serverDetails = $details;
    return $details;
  }

  /**
   * Whether CiviCRM detailed logging is enabled. Log tables are converted
   * only then, because only then do logging triggers write to them.
   *
   * @return bool
   */
  public function isLoggingEnabled() {
    return (bool) Civi::settings()->get('logging');
  }

  /**
   * Name of the database holding the detailed-logging tables, resolved like
   * CRM_Logging_Schema does: from CIVICRM_LOGGING_DSN when defined, otherwise
   * the CiviCRM database. Core reaches it through the CiviCRM connection
   * (its triggers write to `database`.log_*), and so does this tool.
   *
   * @return string
   */
  public function getLoggingDatabaseName() {
    if ($this->loggingDatabase === NULL) {
      $this->loggingDatabase = $this->database;
      if (defined('CIVICRM_LOGGING_DSN')) {
        $dsn = DB::parseDSN(CRM_Utils_SQL::autoSwitchDSN(CIVICRM_LOGGING_DSN));
        if (!empty($dsn['database'])) {
          $this->loggingDatabase = (string) $dsn['database'];
        }
      }
    }
    return $this->loggingDatabase;
  }

  /**
   * @return bool
   */
  public function hasSeparateLoggingDatabase() {
    return $this->getLoggingDatabaseName() !== $this->database;
  }

  /**
   * Problems met while reading the logging database.
   *
   * @return array
   */
  public function getLoggingErrors() {
    return array_values($this->loggingErrors);
  }

  /**
   * Log tables in the logging database, as CRM_Logging_Schema lists them:
   * log_civicrm_* plus the log tables of tables added through
   * hook_civicrm_alterLogTables.
   *
   * @return array
   *   Table name => TRUE.
   */
  protected function getLogTableNames() {
    if ($this->logTableNames === NULL) {
      $this->logTableNames = array_fill_keys((new CRM_Logging_Schema())->getLogTableNames(), TRUE);
    }
    return $this->logTableNames;
  }

  /**
   * Name a table goes by in the analysis, queue, API and audit log: its own
   * name in the CiviCRM database, "database.table" in a separate logging
   * database.
   *
   * @param string $schema
   * @param string $name
   *
   * @return string
   */
  public function getTableKey($schema, $name) {
    return $schema === $this->database ? $name : "{$schema}.{$name}";
  }

  /**
   * @param string $key
   *
   * @return array
   *   [database, table name].
   */
  protected function splitTableKey($key) {
    $loggingDb = $this->getLoggingDatabaseName();
    if ($loggingDb !== $this->database && strpos($key, $loggingDb . '.') === 0) {
      return [$loggingDb, substr($key, strlen($loggingDb) + 1)];
    }
    return [$this->database, $key];
  }

  /**
   * SQL identifier for a table key, qualified with its database when that is
   * not the CiviCRM database.
   *
   * @param string $key
   *
   * @return string
   */
  protected function quoteTable($key) {
    [$schema, $name] = $this->splitTableKey($key);
    $quoted = '`' . str_replace('`', '``', $name) . '`';
    return $schema === $this->database ? $quoted : '`' . str_replace('`', '``', $schema) . '`.' . $quoted;
  }

  /**
   * Whether a table key names a log table this tool converts.
   *
   * @param string $key
   *
   * @return bool
   */
  public function isLogTable($key) {
    if (!$this->isLoggingEnabled()) {
      return FALSE;
    }
    [$schema, $name] = $this->splitTableKey($key);
    return $schema === $this->getLoggingDatabaseName() && isset($this->getLogTableNames()[$name]);
  }

  /**
   * Order tables for conversion with log tables first. A log table on
   * utf8mb4 accepts everything its trigger copies from a utf8 table, while a
   * converted table's trigger cannot copy 4-byte characters into a log table
   * that is still on utf8.
   *
   * @param array $keys
   *
   * @return array
   */
  public function orderForConversion(array $keys) {
    $logs = array_filter($keys, [$this, 'isLogTable']);
    return array_values(array_merge($logs, array_diff($keys, $logs)));
  }

  /**
   * Analyze all InnoDB tables in the CiviCRM database, and the log tables
   * when detailed logging is enabled.
   *
   * @param array|null $onlyTables
   *   Optional list of table keys to restrict analysis to. The result keeps
   *   their order.
   *
   * @return array
   *   Keyed by table key (see getTableKey()). Each item contains:
   *   - table, engine, current_collation, target_collation
   *   - database, is_log, base_table: the table's database, whether it is a
   *     log table, and for a log table the table it logs
   *   - size_bytes, size_formatted, row_count
   *   - columns: list of columns needing change
   *   - skipped_columns: columns whose definitions cannot be converted safely
   *   - warnings: list of warning strings
   *   - blocked, errors: whether conversion is unsafe, and the reasons
   *   - schema_fingerprint: hash of DDL excluding its sequence counter
   *   - row_format: row format the table will have after conversion
   *   - needs_change: bool
   *   - alter: full ALTER statement (empty when blocked or no change is needed)
   */
  public function analyze($onlyTables = NULL) {
    $result = [];
    $tables = $this->getTables();
    $sizes = $this->getTableSizes();

    $keys = $onlyTables === NULL ? array_keys($tables) : array_intersect($onlyTables, array_keys($tables));
    foreach ($keys as $table) {
      $item = $this->analyzeTable($table, $tables[$table]);
      $item['size_bytes'] = $sizes[$table]['size'] ?? 0;
      $item['size_formatted'] = self::formatBytes($item['size_bytes']);
      $item['row_count'] = $sizes[$table]['rows'] ?? 0;
      if ($item['size_bytes'] > self::LARGE_TABLE_BYTES) {
        $item['warnings'][] = E::ts('Large table (%1). The ALTER may take several minutes and will lock the table.', [1 => $item['size_formatted']]);
      }
      $result[$table] = $item;
    }

    $this->addIndexWarnings($result);
    $this->addLoggingWarnings($result);

    return $result;
  }

  /**
   * Warn when a table and its log table are not converted together. The
   * logging trigger copies every saved value into the log table, so a
   * 4-byte character saved to a converted table is rejected there (or
   * stored as "?" without strict SQL mode) while the log table is on utf8.
   *
   * @param array $result
   *   Analysis result, modified by reference.
   */
  protected function addLoggingWarnings(array &$result) {
    foreach ($result as $key => $item) {
      $base = $item['base_table'];
      if (!$item['is_log'] || !$item['needs_change'] || !isset($result[$base])) {
        continue;
      }
      if ($result[$base]['needs_change']) {
        $result[$base]['warnings'][] = E::ts('Convert its log table %1 with it. Once this table is on utf8mb4, 4-byte characters saved to it are rejected by the log table (or stored as "?" without strict SQL mode) until the log table is converted too.', [1 => $key]);
      }
      else {
        $result[$key]['warnings'][] = E::ts('%1 is already on utf8mb4, so 4-byte characters saved to it are rejected by this log table (or stored as "?" without strict SQL mode) until it is converted.', [1 => $base]);
      }
    }
  }

  /**
   * Log tables that need conversion but are left out of a selection that
   * includes the table they log.
   *
   * @param array $analysis
   *   Analysis of the selected tables, keyed by table key.
   *
   * @return array
   *   Table key => key of its log table.
   */
  public function findUnselectedLogTables(array $analysis) {
    $missing = [];
    if (!$this->isLoggingEnabled()) {
      return $missing;
    }
    $tables = $this->getTables();
    foreach ($analysis as $key => $item) {
      if ($item['is_log']) {
        continue;
      }
      $logKey = $this->getTableKey($this->getLoggingDatabaseName(), 'log_' . $key);
      if (isset($tables[$logKey]) && !isset($analysis[$logKey])
        && $this->analyzeTable($logKey, $tables[$logKey])['needs_change']) {
        $missing[$key] = $logKey;
      }
    }
    return $missing;
  }

  /**
   * Analyze a single table and build its ALTER statement.
   *
   * @param string $table
   *   Table key, see getTableKey().
   * @param array|null $meta
   *   Table meta from getTables(). Fetched when NULL.
   *
   * @return array
   */
  public function analyzeTable($table, $meta = NULL) {
    if ($meta === NULL) {
      $tables = $this->getTables();
      if (!isset($tables[$table])) {
        throw new CRM_Core_Exception("Table not found or not convertible: {$table}");
      }
      $meta = $tables[$table];
    }
    $isLog = !empty($meta['Is_log']);

    $item = [
      'table' => $table,
      'database' => $meta['Schema'] ?? $this->database,
      'is_log' => $isLog,
      // A log table is named log_<table>; the table it logs is always in the
      // CiviCRM database.
      'base_table' => $isLog ? substr($meta['Name'], 4) : '',
      'engine' => $meta['Engine'],
      'current_collation' => $meta['Collation'],
      'target_collation' => $this->targetCollation,
      'columns' => [],
      'skipped_columns' => [],
      'warnings' => [],
      'blocked' => FALSE,
      'errors' => [],
      'schema_fingerprint' => '',
      'row_format' => (string) ($meta['Row_format'] ?? ''),
      'needs_change' => FALSE,
      'alter' => '',
    ];

    $modifyClauses = [];
    $generatedClauses = [];

    // Capture the schema used for this analysis. Parse column definitions
    // from this same snapshot only when a column needs conversion.
    try {
      $createTable = $this->getCreateTable($table);
      $item['schema_fingerprint'] = self::fingerprint($createTable);
    }
    catch (Exception $e) {
      $item['blocked'] = $item['needs_change'] = TRUE;
      $item['errors'][] = $e->getMessage();
      return $item;
    }
    $definitions = NULL;
    $definitionOf = function ($field) use ($table, $createTable, &$definitions) {
      if ($definitions === NULL) {
        $definitions = $this->getColumnDefinitions($table, $createTable);
      }
      if (!isset($definitions[$field])) {
        throw new CRM_Core_Exception(E::ts('Cannot parse the definition of column %1. Conversion blocked.', [1 => $field]));
      }
      return $definitions[$field];
    };

    $quotedTable = $this->quoteTable($table);
    $dao = CRM_Core_DAO::executeQuery("SHOW FULL COLUMNS FROM {$quotedTable}");
    while ($dao->fetch()) {
      $collation = $dao->Collation;
      $extra = (string) $dao->Extra;

      // No collation (numeric/blob/etc.) or already at a target collation.
      if (!$collation || $collation === $this->targetCollation || $collation === self::NEW_BINARY_COLLATION) {
        continue;
      }

      // Only convert utf8 family (utf8_*, utf8mb3_*, other utf8mb4_* variants).
      if (strpos($collation, 'utf8') !== 0) {
        continue;
      }

      // Preserve binary collations as binary.
      $columnCollation = (strpos($collation, '_bin') !== FALSE)
        ? self::NEW_BINARY_COLLATION
        : $this->targetCollation;

      $null = ($dao->Null === 'YES') ? 'NULL' : 'NOT NULL';
      $comment = CRM_Core_DAO::escapeString((string) $dao->Comment);
      $quotedField = str_replace('`', '``', $dao->Field);

      try {
        $definition = $definitionOf($dao->Field);
        $default = $this->buildDefaultClause($dao->Default, $dao->Null, $definition);
      }
      catch (Exception $e) {
        $item['errors'][] = $e->getMessage();
        $item['skipped_columns'][] = ['field' => $dao->Field, 'reason' => $e->getMessage()];
        continue;
      }

      // Generated columns must be redefined with their generation expression,
      // otherwise they stay on the old charset and reject 4-byte input coming
      // from the columns they derive from.
      if (stripos($extra, 'GENERATED') !== FALSE && stripos($extra, 'DEFAULT_GENERATED') === FALSE) {
        $expression = (string) ($definition['generated'] ?? '');
        if ($expression === '') {
          $item['skipped_columns'][] = [
            'field' => $dao->Field,
            'reason' => E::ts('Generated column - expression could not be read from SHOW CREATE TABLE.'),
          ];
          $item['errors'][] = E::ts('Cannot read the generation expression for %1. Conversion blocked.', [1 => $dao->Field]);
          continue;
        }
        $storage = (stripos($extra, 'VIRTUAL') !== FALSE) ? 'VIRTUAL' : 'STORED';
        // Generated columns go last so their base columns convert first.
        // Note: no NULL/NOT NULL clause - MariaDB rejects it on generated
        // columns, and NULL is the default in both MySQL and MariaDB.
        $generatedClauses[] = "MODIFY `{$quotedField}` {$dao->Type} CHARACTER SET " . self::NEW_CHARSET .
          " COLLATE {$columnCollation} GENERATED ALWAYS AS ({$expression}) {$storage} COMMENT '{$comment}'";
        $item['columns'][] = [
          'field' => $dao->Field,
          'type' => $dao->Type . ' (generated)',
          'current_collation' => $collation,
          'target_collation' => $columnCollation,
        ];
        continue;
      }

      $extraSql = trim(str_ireplace('DEFAULT_GENERATED', '', $extra));

      $clause = "MODIFY `{$quotedField}` {$dao->Type} CHARACTER SET " . self::NEW_CHARSET .
        " COLLATE {$columnCollation} {$null}";
      if ($default !== '') {
        $clause .= " {$default}";
      }
      if ($extraSql !== '') {
        $clause .= " {$extraSql}";
      }
      $clause .= " COMMENT '{$comment}'";

      $modifyClauses[] = $clause;
      $item['columns'][] = [
        'field' => $dao->Field,
        'type' => $dao->Type,
        'current_collation' => $collation,
        'target_collation' => $columnCollation,
      ];
    }

    $modifyClauses = array_merge($modifyClauses, $generatedClauses);

    // Only change a table default when it is itself in the utf8 family. A
    // table may have one legacy utf8 column while its default is latin1; in
    // that case convert the column without silently changing the table's
    // default (and therefore the charset of future columns).
    $tableIsUtf8 = $this->isUtf8Collation($meta['Collation']);
    $tableCollation = (strpos((string) $meta['Collation'], '_bin') !== FALSE)
      ? self::NEW_BINARY_COLLATION
      : $this->targetCollation;
    $item['target_collation'] = $tableIsUtf8 ? $tableCollation : $meta['Collation'];

    // InnoDB tables move to the Dynamic row format for its 3072-byte index
    // key limit. Compressed tables already have that limit, and switching
    // them would silently decompress them.
    $setDynamic = $tableIsUtf8 && $meta['Engine'] === 'InnoDB'
      && strcasecmp((string) ($meta['Row_format'] ?? ''), 'Compressed') !== 0;
    $item['row_format'] = $setDynamic ? 'Dynamic' : (string) ($meta['Row_format'] ?? '');

    if ($item['errors']) {
      $item['blocked'] = $item['needs_change'] = TRUE;
      return $item;
    }

    if (empty($modifyClauses) && (!$tableIsUtf8 || $meta['Collation'] === $tableCollation)) {
      return $item;
    }

    $item['needs_change'] = TRUE;
    if ($meta['Engine'] === 'ARCHIVE') {
      $item['warnings'][] = E::ts('ARCHIVE engine: the ALTER rewrites the whole table, which is slow for a large log table. To move log tables to InnoDB first, run the System.updatelogtables API with forceEngineMigration=1.');
    }
    $lines = $modifyClauses;
    if ($tableIsUtf8) {
      // Stay binary only when the table itself is currently on a binary
      // collation. A single binary column (e.g. a hash) must not flip the
      // table default - CiviCRM core tables keep it at the target collation.
      $lines[] = 'CHARACTER SET = ' . self::NEW_CHARSET . " COLLATE = {$tableCollation}" .
        ($setDynamic ? ' ROW_FORMAT = Dynamic KEY_BLOCK_SIZE = 0' : '');
    }
    $item['alter'] = "ALTER TABLE {$quotedTable}\n  " . implode(",\n  ", $lines) . ';';

    return $item;
  }

  /**
   * Build the DEFAULT clause for a column.
   *
   * Only string columns get here (TIMESTAMP/DATETIME have no collation).
   * Whether a default is a literal or an expression comes from how SHOW
   * CREATE TABLE writes it, never from its text: a literal default may well
   * read "CURRENT_TIMESTAMP".
   *
   * @param string|null $default
   *   Default from SHOW FULL COLUMNS (an unquoted literal value).
   * @param string $nullable
   * @param array $definition
   *   The column's entry from getColumnDefinitions().
   *
   * @return string
   */
  protected function buildDefaultClause($default, $nullable, array $definition) {
    if (!empty($definition['error'])) {
      throw new CRM_Core_Exception($definition['error']);
    }
    if ($default === NULL) {
      return ($nullable === 'YES') ? 'DEFAULT NULL' : '';
    }
    if (!empty($definition['default_is_expression'])) {
      // MySQL 8 requires parentheses around expression defaults on string
      // columns; MariaDB accepts them. MariaDB writes simple expressions
      // unparenthesized, and its SHOW COLUMNS has them verbatim.
      return 'DEFAULT (' . ($definition['default_expression'] ?? $default) . ')';
    }
    if (empty($definition['has_default'])) {
      throw new CRM_Core_Exception(E::ts('Cannot identify a non-NULL column default. Conversion blocked.'));
    }
    return "DEFAULT '" . CRM_Core_DAO::escapeString($default) . "'";
  }

  /**
   * Parse SHOW CREATE TABLE for each column's generation expression and how
   * its default is written. information_schema and SHOW COLUMNS can't be used
   * for expressions: MySQL 8 returns them with quotes and backslashes escaped
   * a second time, which is not valid SQL.
   *
   * @param string $table
   *
   * @return array
   *   Field name => [
   *     'generated' => generation expression, or NULL,
   *     'default_is_expression' => bool,
   *     'default_expression' => default expression from inside its
   *       parentheses, or NULL when it is written unparenthesized (MariaDB),
   *   ].
   */
  protected function getColumnDefinitions($table, $createTable = NULL) {
    $createTable = $createTable ?? $this->getCreateTable($table);
    $definitions = [];
    foreach (explode("\n", $createTable) as $line) {
      // SHOW CREATE can use backticks, ANSI quotes, or unquoted identifiers.
      if (!preg_match('/^\s+(?:`((?:[^`]|``)+)`|"((?:[^"]|"")+)"|([\p{L}\p{N}_$]+))\s+/u', $line, $m)) {
        continue;
      }
      $field = ($m[1] ?? '') !== '' ? str_replace('``', '`', $m[1])
        : (($m[2] ?? '') !== '' ? str_replace('""', '"', $m[2]) : $m[3]);
      // Look for keywords and parentheses only outside quoted text.
      $masked = self::maskQuoted($line);
      $definition = [
        'generated' => NULL,
        'default_is_expression' => FALSE,
        'default_expression' => NULL,
        'has_default' => FALSE,
      ];
      if (preg_match('/\sAS\s*\(/i', $masked, $as, PREG_OFFSET_CAPTURE)) {
        $definition['generated'] = self::parenthesized($line, $masked, $as[0][1] + strlen($as[0][0]) - 1);
        if ($definition['generated'] === NULL) {
          $definition['error'] = E::ts('Cannot parse the generation expression for %1.', [1 => $field]);
        }
      }
      if (preg_match('/\sDEFAULT\s+/i', $masked, $match, PREG_OFFSET_CAPTURE)) {
        $definition['has_default'] = TRUE;
        $start = $match[0][1] + strlen($match[0][0]);
        $next = $line[$start] ?? '';
        if ($next === '(') {
          $definition['default_is_expression'] = TRUE;
          $definition['default_expression'] = self::parenthesized($line, $masked, $start);
          if ($definition['default_expression'] === NULL) {
            $definition['error'] = E::ts('Cannot parse the default expression for %1.', [1 => $field]);
          }
        }
        elseif ($next !== "'" && !preg_match('/^NULL\b/i', substr($line, $start))) {
          $definition['default_is_expression'] = TRUE;
        }
      }
      $definitions[$field] = $definition;
    }
    return $definitions;
  }

  /** Read canonical DDL; never substitute an empty definition on failure. */
  protected function getCreateTable($table) {
    $dao = CRM_Core_DAO::executeQuery("SHOW CREATE TABLE " . $this->quoteTable($table));
    if (!$dao->fetch() || empty($dao->Create_Table)) {
      throw new CRM_Core_Exception(E::ts('Cannot read the schema of %1.', [1 => $table]));
    }
    return (string) $dao->Create_Table;
  }

  public function getSchemaFingerprint($table) {
    return self::fingerprint($this->getCreateTable($table));
  }

  /** Ignore the mutable sequence counter, but preserve all schema details. */
  protected static function fingerprint($ddl) {
    if (preg_match('/\bAUTO_INCREMENT\s*=\s*\d+/i', self::maskQuoted($ddl), $match, PREG_OFFSET_CAPTURE)) {
      $ddl = substr_replace($ddl, 'AUTO_INCREMENT=0', $match[0][1], strlen($match[0][0]));
    }
    return hash('sha256', $ddl);
  }

  /**
   * Replace quoted strings and identifiers in SQL with "x", keeping offsets,
   * so keywords and parentheses can be found outside them.
   *
   * @param string $sql
   *
   * @return string
   */
  protected static function maskQuoted($sql) {
    $masked = $sql;
    $quote = NULL;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      if ($quote === NULL) {
        if ($char === "'" || $char === '"' || $char === '`') {
          $quote = $char;
          $masked[$i] = 'x';
        }
        continue;
      }
      $masked[$i] = 'x';
      if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
        $masked[++$i] = 'x';
      }
      elseif ($char === $quote) {
        if (($sql[$i + 1] ?? '') === $quote) {
          $masked[++$i] = 'x';
        }
        else {
          $quote = NULL;
        }
      }
    }
    return $masked;
  }

  /**
   * Text inside the parenthesis that opens at $open, up to its match.
   *
   * @param string $sql
   * @param string $masked
   *   $sql with quoted text masked out, see maskQuoted().
   * @param int $open
   *   Offset of the opening parenthesis.
   *
   * @return string|null
   *   NULL when the parenthesis is never closed.
   */
  protected static function parenthesized($sql, $masked, $open) {
    $depth = 0;
    $length = strlen($masked);
    for ($i = $open; $i < $length; $i++) {
      if ($masked[$i] === '(') {
        $depth++;
      }
      elseif ($masked[$i] === ')' && --$depth === 0) {
        return substr($sql, $open + 1, $i - $open - 1);
      }
    }
    return NULL;
  }

  /**
   * Get the tables this tool converts, keyed by table key: InnoDB civicrm_*
   * tables in the CiviCRM database and, while detailed logging is enabled,
   * InnoDB and ARCHIVE log tables in the logging database.
   *
   * @return array
   *   Table key => [Schema, Name, Is_log, Engine, Collation, Row_format].
   */
  public function getTables() {
    $tables = [];
    // The engine filter also leaves out views, which have none, such as the
    // civicrm_<table>_<locale> views multilingual CiviCRM creates. A view's
    // columns follow its base table, so converting the table converts them.
    $dao = CRM_Core_DAO::executeQuery("SHOW TABLE STATUS WHERE Name LIKE '" . self::CIVICRM_TABLE_PREFIX . "%' AND Engine = 'InnoDB'");
    while ($dao->fetch()) {
      $tables[$dao->Name] = self::tableMeta($dao, $this->database, FALSE);
    }
    return $tables + $this->getLogTables();
  }

  /**
   * Log tables to convert, keyed by table key. Empty while detailed logging
   * is disabled. A logging database that cannot be read is reported through
   * getLoggingErrors() rather than failing the whole analysis.
   *
   * @return array
   */
  protected function getLogTables() {
    $tables = [];
    if (!$this->isLoggingEnabled()) {
      return $tables;
    }
    $schema = $this->getLoggingDatabaseName();
    try {
      $names = $this->getLogTableNames();
      if (!$names) {
        return $tables;
      }
      $dao = CRM_Core_DAO::executeQuery("SHOW TABLE STATUS FROM `" . str_replace('`', '``', $schema) . "`
        WHERE Name LIKE '" . self::LOG_TABLE_PREFIX . "%' AND Engine IN ('" . implode("', '", self::LOG_TABLE_ENGINES) . "')");
      while ($dao->fetch()) {
        if (isset($names[$dao->Name])) {
          $tables[$this->getTableKey($schema, $dao->Name)] = self::tableMeta($dao, $schema, TRUE);
        }
      }
    }
    catch (Exception $e) {
      $this->loggingErrors[$e->getMessage()] = E::ts('Cannot read the log tables in the logging database %1, so they are not listed: %2', [
        1 => $schema,
        2 => $e->getMessage(),
      ]);
    }
    return $tables;
  }

  /**
   * @param object $dao
   *   A SHOW TABLE STATUS row.
   * @param string $schema
   * @param bool $isLog
   *
   * @return array
   */
  protected static function tableMeta($dao, $schema, $isLog) {
    return [
      'Schema' => $schema,
      'Name' => $dao->Name,
      'Is_log' => $isLog,
      'Engine' => $dao->Engine,
      'Collation' => $dao->Collation,
      'Row_format' => $dao->Row_format,
    ];
  }

  /**
   * CiviCRM tables outside this tool's scope that still use utf8/utf8mb3:
   * non-InnoDB civicrm_* tables; log tables while detailed logging is
   * disabled, or on an engine other than InnoDB/ARCHIVE; and log_civicrm_*
   * tables left in the CiviCRM database when logging uses another one.
   *
   * @return array
   *   Table key => ['engine' => ..., 'collation' => ..., 'reason' => ...].
   */
  public function getUncoveredTables() {
    $tables = [];
    foreach ($this->findLegacyTables($this->database, self::CIVICRM_TABLE_PREFIX . '%') as $name => $info) {
      if ($info['engine'] !== 'InnoDB') {
        $tables[$name] = $info + ['reason' => E::ts('%1 engine; only InnoDB tables are converted.', [1 => $info['engine']])];
      }
    }

    $loggingDb = $this->getLoggingDatabaseName();
    $logNames = $this->getLogTableNames();
    foreach ($this->findLegacyTables($loggingDb, self::LOG_TABLE_PREFIX . '%') as $name => $info) {
      if (!isset($logNames[$name])) {
        continue;
      }
      if (!$this->isLoggingEnabled()) {
        $reason = E::ts('Detailed logging is disabled, so log tables are not converted. If logging is enabled again, CiviCRM writes to this table, and 4-byte characters are rejected until it is converted.');
      }
      elseif (!in_array($info['engine'], self::LOG_TABLE_ENGINES, TRUE)) {
        $reason = E::ts('%1 engine; only InnoDB and ARCHIVE log tables are converted. Logging triggers copy every change here, so 4-byte characters are still rejected until it is converted.', [1 => $info['engine']]);
      }
      else {
        continue;
      }
      $tables[$this->getTableKey($loggingDb, $name)] = $info + ['reason' => $reason];
    }

    if ($loggingDb !== $this->database) {
      foreach ($this->findLegacyTables($this->database, self::LOG_TABLE_PREFIX . self::CIVICRM_TABLE_PREFIX . '%') as $name => $info) {
        $tables[$name] = $info + ['reason' => E::ts('Not in the logging database (%1), so CiviCRM does not write to it. It is not converted.', [1 => $loggingDb])];
      }
    }
    return $tables;
  }

  /**
   * Tables in a database whose default or any column is still utf8/utf8mb3.
   *
   * @param string $schema
   * @param string $nameLike
   *   LIKE pattern for the table names.
   *
   * @return array
   *   Table name => ['engine' => ..., 'collation' => ...].
   */
  protected function findLegacyTables($schema, $nameLike) {
    $tables = [];
    $dao = CRM_Core_DAO::executeQuery("
      SELECT t.TABLE_NAME AS table_name, t.ENGINE AS engine, t.TABLE_COLLATION AS table_collation,
        SUM(c.CHARACTER_SET_NAME IN ('utf8', 'utf8mb3')) AS legacy_columns
      FROM information_schema.TABLES t
      LEFT JOIN information_schema.COLUMNS c
        ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
        AND c.TABLE_NAME = t.TABLE_NAME
      WHERE t.TABLE_SCHEMA = %1
        AND t.TABLE_TYPE = 'BASE TABLE'
        AND t.TABLE_NAME LIKE %2
      GROUP BY t.TABLE_NAME, t.ENGINE, t.TABLE_COLLATION
      HAVING legacy_columns > 0 OR table_collation LIKE 'utf8\_%' OR table_collation LIKE 'utf8mb3\_%'
      ORDER BY t.TABLE_NAME
    ", [1 => [$schema, 'String'], 2 => [$nameLike, 'String']]);
    while ($dao->fetch()) {
      $tables[$dao->table_name] = [
        'engine' => $dao->engine,
        'collation' => $dao->table_collation,
      ];
    }
    return $tables;
  }

  /**
   * Get data+index size and row estimates from information_schema, keyed by
   * table key.
   *
   * @return array
   */
  protected function getTableSizes() {
    $sizes = [];
    foreach ($this->getSchemaPatterns() as [$schema, $nameLike]) {
      $dao = CRM_Core_DAO::executeQuery("
        SELECT TABLE_NAME, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes, TABLE_ROWS
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = %1
          AND TABLE_NAME LIKE %2
      ", [1 => [$schema, 'String'], 2 => [$nameLike, 'String']]);
      while ($dao->fetch()) {
        $sizes[$this->getTableKey($schema, $dao->TABLE_NAME)] = [
          'size' => (int) $dao->size_bytes,
          'rows' => (int) $dao->TABLE_ROWS,
        ];
      }
    }
    return $sizes;
  }

  /**
   * Where the converted tables are: [database, table name LIKE pattern].
   *
   * @return array
   */
  protected function getSchemaPatterns() {
    $patterns = [[$this->database, self::CIVICRM_TABLE_PREFIX . '%']];
    if ($this->isLoggingEnabled()) {
      $patterns[] = [$this->getLoggingDatabaseName(), self::LOG_TABLE_PREFIX . '%'];
    }
    return $patterns;
  }

  /**
   * Flag indexes whose key will exceed the InnoDB limit once their utf8
   * columns take 4 bytes per character. The ALTER fails outright for these.
   *
   * Include every declared key part. Unknown/functional parts produce an
   * explicit warning instead of being silently counted as zero bytes.
   *
   * @param array $result
   *   Analysis result, modified by reference.
   */
  protected function addIndexWarnings(&$result) {
    $indexes = [];
    foreach ($this->getSchemaPatterns() as [$schema, $nameLike]) {
      $dao = CRM_Core_DAO::executeQuery("
        SELECT s.TABLE_NAME AS table_name, s.INDEX_NAME AS index_name,
          s.COLUMN_NAME AS column_name, s.SUB_PART AS sub_part,
          c.DATA_TYPE AS data_type, c.COLUMN_TYPE AS column_type,
          c.CHARACTER_SET_NAME AS charset, c.CHARACTER_MAXIMUM_LENGTH AS char_length,
          c.CHARACTER_OCTET_LENGTH AS octet_length,
          c.NUMERIC_PRECISION AS numeric_precision, c.NUMERIC_SCALE AS numeric_scale,
          c.DATETIME_PRECISION AS datetime_precision
        FROM information_schema.STATISTICS s
        LEFT JOIN information_schema.COLUMNS c
          ON c.TABLE_SCHEMA = s.TABLE_SCHEMA
          AND c.TABLE_NAME = s.TABLE_NAME
          AND c.COLUMN_NAME = s.COLUMN_NAME
        WHERE s.TABLE_SCHEMA = %1
          AND s.TABLE_NAME LIKE %2
          AND s.INDEX_TYPE = 'BTREE'
        ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
      ", [1 => [$schema, 'String'], 2 => [$nameLike, 'String']]);
      while ($dao->fetch()) {
        $key = $this->getTableKey($schema, $dao->table_name);
        if (empty($result[$key]['needs_change'])) {
          continue;
        }
        $indexes[$key][$dao->index_name][] = (array) $dao;
      }
    }
    foreach ($indexes as $table => $tableIndexes) {
      foreach ($tableIndexes as $name => $parts) {
        $bytes = 0;
        $unknown = FALSE;
        foreach ($parts as $part) {
          $size = self::indexPartBytes($part);
          $unknown = $unknown || $size === NULL;
          $bytes += $size ?? 0;
        }
        $limit = $this->getIndexKeyLimit($result[$table]['row_format']);
        if ($bytes > $limit) {
          $result[$table]['warnings'][] = E::ts(
            'Index %1 will need at least %2 bytes once converted, over the %3-byte InnoDB index key limit. Review its columns and prefix lengths before converting.',
            [1 => $name, 2 => $bytes, 3 => $limit]
          );
        }
        if ($unknown) {
          $result[$table]['warnings'][] = E::ts('Index %1 contains a key part whose size cannot be determined. Verify its full key length manually before converting.', [1 => $name]);
        }
      }
    }
  }

  /** Maximum bytes in a declared index part, or NULL if unsupported. */
  protected static function indexPartBytes(array $part) {
    $type = strtolower((string) $part['data_type']);
    if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], TRUE)) {
      if (empty($part['char_length']) || empty($part['octet_length'])) {
        return NULL;
      }
      $width = strpos((string) $part['charset'], 'utf8') === 0 ? 4 : $part['octet_length'] / $part['char_length'];
      return (int) ceil(($part['sub_part'] ?? $part['char_length']) * $width);
    }
    if (in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], TRUE)) {
      return isset($part['sub_part']) || isset($part['octet_length']) ? (int) ($part['sub_part'] ?? $part['octet_length']) : NULL;
    }
    $fixed = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 8, 'float' => 4, 'double' => 8, 'real' => 8, 'date' => 3, 'year' => 1];
    if (isset($fixed[$type])) {
      return $fixed[$type];
    }
    if ($type === 'bit') {
      return isset($part['numeric_precision']) ? (int) ceil($part['numeric_precision'] / 8) : NULL;
    }
    if (in_array($type, ['decimal', 'numeric'], TRUE)) {
      if (!isset($part['numeric_precision'], $part['numeric_scale'])) {
        return NULL;
      }
      $digits = [0, 1, 1, 2, 2, 3, 3, 4, 4];
      $integer = (int) $part['numeric_precision'] - (int) $part['numeric_scale'];
      $fraction = (int) $part['numeric_scale'];
      return intdiv($integer, 9) * 4 + $digits[$integer % 9] + intdiv($fraction, 9) * 4 + $digits[$fraction % 9];
    }
    $temporal = ['time' => 3, 'datetime' => 5, 'timestamp' => 4];
    if (isset($temporal[$type])) {
      return $temporal[$type] + (int) ceil(($part['datetime_precision'] ?? 0) / 2);
    }
    if (in_array($type, ['enum', 'set'], TRUE)) {
      $masked = self::maskQuoted((string) $part['column_type']);
      $members = substr_count($masked, ',') + 1;
      if (strpos($masked, '(') === FALSE) {
        return NULL;
      }
      if ($type === 'enum') {
        return $members > 255 ? 2 : 1;
      }
      $bytes = (int) ceil($members / 8);
      return $bytes > 4 ? 8 : $bytes;
    }
    return NULL;
  }

  /**
   * Largest InnoDB index key, in bytes, for a row format on this server.
   *
   * @param string $rowFormat
   *
   * @return int
   */
  protected function getIndexKeyLimit($rowFormat) {
    $details = $this->getServerDetails();
    $largePrefix = in_array(strtolower($rowFormat), ['dynamic', 'compressed'], TRUE)
      && !in_array(strtoupper($details['innodb_large_prefix'] ?? 'ON'), ['OFF', '0'], TRUE);
    if (!$largePrefix) {
      return 767;
    }
    // 3072 bytes on 16 KB and larger pages, scaled down on smaller ones.
    $pageSize = (int) ($details['innodb_page_size'] ?? 16384);
    return (int) min(3072, $pageSize * 3 / 16);
  }

  /**
   * Quick count of tables with a utf8/utf8mb3 default or any utf8/utf8mb3
   * column (used by the status check), including the log tables this tool
   * converts while detailed logging is enabled.
   *
   * @return int
   */
  public static function countNonUtf8mb4Tables() {
    $analyzer = new self();
    $logTables = 0;
    if ($analyzer->isLoggingEnabled()) {
      $logNames = $analyzer->getLogTableNames();
      foreach ($analyzer->findLegacyTables($analyzer->getLoggingDatabaseName(), self::LOG_TABLE_PREFIX . '%') as $name => $info) {
        $logTables += (int) (isset($logNames[$name]) && in_array($info['engine'], self::LOG_TABLE_ENGINES, TRUE));
      }
    }
    return $logTables + (int) CRM_Core_DAO::singleValueQuery("
      SELECT COUNT(*) FROM (
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME LIKE '" . self::CIVICRM_TABLE_PREFIX . "%'
          AND ENGINE = 'InnoDB'
          AND (TABLE_COLLATION LIKE 'utf8\_%' OR TABLE_COLLATION LIKE 'utf8mb3\_%')
        UNION
        SELECT c.TABLE_NAME
        FROM information_schema.COLUMNS c
        INNER JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
          AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE()
          AND t.TABLE_SCHEMA = DATABASE()
          AND c.TABLE_NAME LIKE '" . self::CIVICRM_TABLE_PREFIX . "%'
          AND t.ENGINE = 'InnoDB'
          AND c.CHARACTER_SET_NAME IN ('utf8', 'utf8mb3')
      ) legacy
    ");
  }

  /**
   * @param int $bytes
   * @return string
   */
  public static function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
      return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
      return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
      return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
  }

  /**
   * @param string|null $collation
   * @return bool
   */
  protected function isUtf8Collation($collation) {
    return strpos((string) $collation, 'utf8') === 0;
  }

}
