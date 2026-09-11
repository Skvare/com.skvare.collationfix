<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Analyzes database tables/columns for utf8/utf8mb3 -> utf8mb4 conversion.
 *
 * Ported from Skvare's standalone conversion.php CLI script, with fixes:
 * - Does not quote CURRENT_TIMESTAMP / expression defaults.
 * - Escapes quotes inside DEFAULT values (not just COMMENT).
 * - Skips generated columns (plain MODIFY would fail on them).
 * - Handles both utf8_* (MySQL 5.7) and utf8mb3_* (MySQL 8) collation names.
 * - Adds size / index-length pre-flight warnings.
 */
class CRM_Collationfix_Analyzer {

  const NEW_CHARSET = 'utf8mb4';
  const NEW_COLLATION = 'utf8mb4_unicode_ci';
  const NEW_BINARY_COLLATION = 'utf8mb4_bin';
  const CIVICRM_TABLE_PREFIX = 'civicrm\\_';

  /**
   * Table size (in bytes) above which a "large table" warning is raised.
   */
  const LARGE_TABLE_BYTES = 524288000; // 500 MB

  /**
   * @var string
   */
  protected $database;

  public function __construct() {
    $dsn = defined('CIVICRM_DSN') ? CIVICRM_DSN : NULL;
    $this->database = CRM_Core_DAO::getDatabaseName();
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
   * Get MySQL/MariaDB server version string.
   *
   * @return string
   */
  public function getServerVersion() {
    return (string) CRM_Core_DAO::singleValueQuery('SELECT VERSION()');
  }

  /**
   * @return bool
   */
  public function isMariaDb() {
    return stripos($this->getServerVersion(), 'mariadb') !== FALSE;
  }

  /**
   * Get server variables relevant to a utf8mb4 conversion (charset/collation
   * defaults, InnoDB row-format/prefix settings, packet size). Variables that
   * don't exist on this server/version (e.g. innodb_large_prefix was removed
   * in newer MySQL/MariaDB) are simply omitted rather than erroring.
   *
   * @return array
   *   Variable name => value.
   */
  public function getServerDetails() {
    $variables = [
      'character_set_server',
      'collation_server',
      'innodb_file_per_table',
      'innodb_large_prefix',
      'innodb_default_row_format',
      'max_allowed_packet',
    ];
    $details = [];
    foreach ($variables as $name) {
      $dao = CRM_Core_DAO::executeQuery("SHOW VARIABLES LIKE %1", [1 => [$name, 'String']]);
      if ($dao->fetch()) {
        $details[$name] = $dao->Value;
      }
    }
    return $details;
  }

  /**
   * Analyze all InnoDB tables in the CiviCRM database.
   *
   * @param array|null $onlyTables
   *   Optional list of table names to restrict analysis to.
   *
   * @return array
   *   Keyed by table name. Each item contains:
   *   - table, engine, current_collation, target_collation
   *   - size_bytes, size_formatted, row_count
   *   - columns: list of columns needing change
   *   - skipped_columns: generated columns that were skipped
   *   - warnings: list of warning strings
   *   - needs_change: bool
   *   - alter: full ALTER statement (empty when needs_change is FALSE)
   */
  public function analyze($onlyTables = NULL) {
    $result = [];
    $tables = $this->getTables();
    $sizes = $this->getTableSizes();

    foreach ($tables as $table => $meta) {
      if ($onlyTables !== NULL && !in_array($table, $onlyTables, TRUE)) {
        continue;
      }
      $item = $this->analyzeTable($table, $meta);
      $item['size_bytes'] = $sizes[$table]['size'] ?? 0;
      $item['size_formatted'] = self::formatBytes($item['size_bytes']);
      $item['row_count'] = $sizes[$table]['rows'] ?? 0;
      if ($item['size_bytes'] > self::LARGE_TABLE_BYTES) {
        $item['warnings'][] = E::ts('Large table (%1). The ALTER may take several minutes and will lock the table.', [1 => $item['size_formatted']]);
      }
      $result[$table] = $item;
    }

    $this->addIndexWarnings($result);

    return $result;
  }

  /**
   * Analyze a single table and build its ALTER statement.
   *
   * @param string $table
   * @param array|null $meta
   *   Table meta from SHOW TABLE STATUS (Engine, Collation). Fetched when NULL.
   *
   * @return array
   */
  public function analyzeTable($table, $meta = NULL) {
    if ($meta === NULL) {
      $tables = $this->getTables();
      if (!isset($tables[$table])) {
        throw new CRM_Core_Exception("Table not found or not InnoDB: {$table}");
      }
      $meta = $tables[$table];
    }

    $item = [
      'table' => $table,
      'engine' => $meta['Engine'],
      'current_collation' => $meta['Collation'],
      'target_collation' => self::NEW_COLLATION,
      'columns' => [],
      'skipped_columns' => [],
      'warnings' => [],
      'needs_change' => FALSE,
      'alter' => '',
    ];

    $modifyClauses = [];
    $generatedClauses = [];
    $generationExpressions = NULL;

    $dao = CRM_Core_DAO::executeQuery("SHOW FULL COLUMNS FROM `{$table}`");
    while ($dao->fetch()) {
      $collation = $dao->Collation;
      $extra = (string) $dao->Extra;

      // No collation (numeric/blob/etc.) or already at a target collation.
      if (!$collation || $collation === self::NEW_COLLATION || $collation === self::NEW_BINARY_COLLATION) {
        continue;
      }

      // Only convert utf8 family (utf8_*, utf8mb3_*, other utf8mb4_* variants).
      if (strpos($collation, 'utf8') !== 0) {
        continue;
      }

      // Preserve binary collations as binary.
      $columnCollation = (strpos($collation, '_bin') !== FALSE)
        ? self::NEW_BINARY_COLLATION
        : self::NEW_COLLATION;

      $null = ($dao->Null === 'YES') ? 'NULL' : 'NOT NULL';
      $comment = str_replace("'", "''", (string) $dao->Comment);

      // Generated columns must be redefined with their generation expression,
      // otherwise they stay on the old charset and reject 4-byte input coming
      // from the columns they derive from.
      if (stripos($extra, 'GENERATED') !== FALSE && stripos($extra, 'DEFAULT_GENERATED') === FALSE) {
        if ($generationExpressions === NULL) {
          $generationExpressions = $this->getGenerationExpressions($table);
        }
        $expression = $generationExpressions[$dao->Field] ?? '';
        if ($expression === '') {
          $item['skipped_columns'][] = [
            'field' => $dao->Field,
            'reason' => E::ts('Generated column - expression could not be read from information_schema.'),
          ];
          continue;
        }
        $storage = (stripos($extra, 'VIRTUAL') !== FALSE) ? 'VIRTUAL' : 'STORED';
        // Generated columns go last so their base columns convert first.
        // Note: no NULL/NOT NULL clause - MariaDB rejects it on generated
        // columns, and NULL is the default in both MySQL and MariaDB.
        $generatedClauses[] = "MODIFY `{$dao->Field}` {$dao->Type} CHARACTER SET " . self::NEW_CHARSET .
          " COLLATE {$columnCollation} GENERATED ALWAYS AS ({$expression}) {$storage} COMMENT '{$comment}'";
        $item['columns'][] = [
          'field' => $dao->Field,
          'type' => $dao->Type . ' (generated)',
          'current_collation' => $collation,
          'target_collation' => $columnCollation,
        ];
        continue;
      }

      $default = $this->buildDefaultClause($dao->Default, $dao->Null, $extra);
      $extraSql = trim(str_ireplace('DEFAULT_GENERATED', '', $extra));

      $clause = "MODIFY `{$dao->Field}` {$dao->Type} CHARACTER SET " . self::NEW_CHARSET .
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
      : self::NEW_COLLATION;
    $item['target_collation'] = $tableIsUtf8 ? $tableCollation : $meta['Collation'];

    if (empty($modifyClauses) && (!$tableIsUtf8 || $meta['Collation'] === $tableCollation)) {
      return $item;
    }

    $item['needs_change'] = TRUE;
    $lines = $modifyClauses;
    if ($tableIsUtf8) {
      // Stay binary only when the table itself is currently on a binary
      // collation. A single binary column (e.g. a hash) must not flip the
      // table default - CiviCRM core tables keep it at unicode_ci.
      $lines[] = 'CHARACTER SET = ' . self::NEW_CHARSET . " COLLATE = {$tableCollation}" .
        ($meta['Engine'] === 'InnoDB' ? ' ROW_FORMAT = Dynamic KEY_BLOCK_SIZE = 0' : '');
    }
    $item['alter'] = "ALTER TABLE `{$table}`\n  " . implode(",\n  ", $lines) . ';';

    return $item;
  }

  /**
   * Build the DEFAULT clause for a column, handling expression defaults.
   *
   * @param string|null $default
   * @param string $nullable
   * @param string $extra
   *
   * @return string
   */
  protected function buildDefaultClause($default, $nullable, $extra) {
    if ($default === NULL) {
      return ($nullable === 'YES') ? 'DEFAULT NULL' : '';
    }
    // CURRENT_TIMESTAMP (with or without precision) and MySQL 8 expression
    // defaults (Extra = DEFAULT_GENERATED) must not be quoted.
    if (stripos($extra, 'DEFAULT_GENERATED') !== FALSE
      || preg_match('/^current_timestamp(\(\d*\))?$/i', $default)
    ) {
      return "DEFAULT {$default}";
    }
    $escaped = str_replace("'", "''", $default);
    return "DEFAULT '{$escaped}'";
  }

  /**
   * Get generation expressions for generated columns of a table.
   *
   * @param string $table
   *
   * @return array
   *   Field name => generation expression.
   */
  protected function getGenerationExpressions($table) {
    $expressions = [];
    $dao = CRM_Core_DAO::executeQuery("
      SELECT COLUMN_NAME, GENERATION_EXPRESSION
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = %1
        AND GENERATION_EXPRESSION IS NOT NULL
        AND GENERATION_EXPRESSION != ''
    ", [1 => [$table, 'String']]);
    while ($dao->fetch()) {
      $expressions[$dao->COLUMN_NAME] = $dao->GENERATION_EXPRESSION;
    }
    return $expressions;
  }

  /**
   * Get all InnoDB tables with their engine and collation.
   *
   * @return array
   */
  public function getTables() {
    $tables = [];
    $dao = CRM_Core_DAO::executeQuery("SHOW TABLE STATUS WHERE Name LIKE '" . self::CIVICRM_TABLE_PREFIX . "%' AND Engine = 'InnoDB'");
    while ($dao->fetch()) {
      $tables[$dao->Name] = [
        'Engine' => $dao->Engine,
        'Collation' => $dao->Collation,
      ];
    }
    return $tables;
  }

  /**
   * Get data+index size and row estimates from information_schema.
   *
   * @return array
   */
  protected function getTableSizes() {
    $sizes = [];
    $dao = CRM_Core_DAO::executeQuery("
      SELECT TABLE_NAME, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes, TABLE_ROWS
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME LIKE '" . self::CIVICRM_TABLE_PREFIX . "'
        AND ENGINE = 'InnoDB'
    ");
    while ($dao->fetch()) {
      $sizes[$dao->TABLE_NAME] = [
        'size' => (int) $dao->size_bytes,
        'rows' => (int) $dao->TABLE_ROWS,
      ];
    }
    return $sizes;
  }

  /**
   * Flag indexed varchar columns longer than 191 chars, which can exceed
   * the 767-byte index prefix limit on older row formats once 4-byte.
   *
   * @param array $result
   *   Analysis result, modified by reference.
   */
  protected function addIndexWarnings(&$result) {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT s.TABLE_NAME, s.COLUMN_NAME, s.INDEX_NAME, c.CHARACTER_MAXIMUM_LENGTH
      FROM information_schema.STATISTICS s
      INNER JOIN information_schema.COLUMNS c
        ON c.TABLE_SCHEMA = s.TABLE_SCHEMA
        AND c.TABLE_NAME = s.TABLE_NAME
        AND c.COLUMN_NAME = s.COLUMN_NAME
      WHERE s.TABLE_SCHEMA = DATABASE()
        AND s.TABLE_NAME LIKE '" . self::CIVICRM_TABLE_PREFIX . "'
        AND c.DATA_TYPE = 'varchar'
        AND c.CHARACTER_MAXIMUM_LENGTH > 191
        AND c.CHARACTER_SET_NAME IN ('utf8', 'utf8mb3')
    ");
    while ($dao->fetch()) {
      if (isset($result[$dao->TABLE_NAME]) && $result[$dao->TABLE_NAME]['needs_change']) {
        $result[$dao->TABLE_NAME]['warnings'][] = E::ts(
          'Index %1 covers varchar(%2) column %3 - verify index length limits before converting (ROW_FORMAT=Dynamic usually resolves this).',
          [1 => $dao->INDEX_NAME, 2 => $dao->CHARACTER_MAXIMUM_LENGTH, 3 => $dao->COLUMN_NAME]
        );
      }
    }
  }

  /**
   * Quick count of tables not yet on utf8mb4 (used by the status check).
   *
   * @return int
   */
  public static function countNonUtf8mb4Tables() {
    return (int) CRM_Core_DAO::singleValueQuery("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME LIKE '" . self::CIVICRM_TABLE_PREFIX . "'
        AND ENGINE = 'InnoDB'
        AND (TABLE_COLLATION LIKE 'utf8\_%' OR TABLE_COLLATION LIKE 'utf8mb3\_%')
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
