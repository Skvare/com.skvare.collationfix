<?php

/**
 * Standalone regression checks: php tests/regression.php
 * Database and CiviCRM boundaries are simulated; no database is accessed.
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

define('CIVICRM_LOGGING_DSN', 'mysql://user:pass@localhost/logging');

class CRM_Core_Exception extends Exception {}
class CRM_Collationfix_ExtensionUtil {
  public static function ts($text, $params = []) {
    foreach ($params as $key => $value) {
      $text = str_replace('%' . $key, $value, $text);
    }
    return $text;
  }
}
class Civi {
  public static $target = 'utf8mb4_unicode_ci';
  public static $logging = FALSE;
  public static function settings() { return new self(); }
  public function get($key) { return $key === 'logging' ? self::$logging : self::$target; }
}
class DB {
  public static $database = 'test_database';
  public static function parseDSN($dsn) { return ['database' => self::$database]; }
}
class CRM_Utils_SQL {
  public static function autoSwitchDSN($dsn) { return $dsn; }
}
class CRM_Logging_Schema {
  public static $names = [];
  public function getLogTableNames() { return self::$names; }
}
class TestRows extends stdClass {
  private $rows;
  public function __construct(array $rows) { $this->rows = $rows; }
  public function fetch() {
    $row = array_shift($this->rows);
    if ($row === NULL) { return FALSE; }
    foreach ($row as $key => $value) { $this->$key = $value; }
    return TRUE;
  }
}
class CRM_Core_DAO {
  public static $ddl;
  public static $columns;
  public static $meta;
  public static $indexes;
  public static $events;
  public static $onAlter;
  public static $onDDL;
  public static $ddlReads;
  public static $failColumns;
  public static $logTables;
  public static $logColumns;
  public static $legacy;
  public static $mainLegacyCount;
  public static $failLogStatus;
  public static $queries;
  public static function getDatabaseName() { return 'test_database'; }
  /** Rows whose $field matches a SQL LIKE pattern. */
  public static function like(array $rows, $field, $pattern) {
    $regex = '/^' . strtr(preg_quote($pattern, '/'), ['\\\\_' => '_', '%' => '.*', '_' => '.']) . '$/';
    return array_values(array_filter($rows, function ($row) use ($field, $regex) { return preg_match($regex, $row[$field]); }));
  }
  public static function escapeString($value) {
    return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ["\\\\", '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $value);
  }
  public static function singleValueQuery($sql) {
    if ($sql === 'SELECT LAST_INSERT_ID()') { return 42; }
    if (strpos($sql, 'CHARACTER_SET_NAME IN') !== FALSE) { return self::$mainLegacyCount; }
    throw new Exception('Unexpected scalar query: ' . $sql);
  }
  public static function executeQuery($sql, $params = [], ...$unused) {
    $sql = trim($sql);
    self::$queries[] = $sql;
    $schema = $params[1][0] ?? NULL;
    if (strpos($sql, 'SHOW COLLATION') === 0) {
      return new TestRows([['Collation' => 'utf8mb4_unicode_ci'], ['Collation' => 'utf8mb4_general_ci']]);
    }
    if (strpos($sql, 'SHOW TABLE STATUS FROM') === 0) {
      if (self::$failLogStatus) { throw new Exception('Access denied to logging database'); }
      return new TestRows(self::$logTables);
    }
    if (strpos($sql, 'SHOW TABLE STATUS') === 0) {
      return new TestRows([self::$meta + ['Name' => 'civicrm_probe']]);
    }
    if (strpos($sql, 'SHOW CREATE TABLE') === 0) {
      self::$ddlReads++;
      if (self::$onDDL) { call_user_func(self::$onDDL, self::$ddlReads); }
      return new TestRows(self::$ddl === NULL ? [] : [['Create_Table' => self::$ddl]]);
    }
    if (strpos($sql, 'SHOW FULL COLUMNS') === 0) {
      if (self::$failColumns) { throw new CRM_Core_Exception('Verification connection failed'); }
      if (strpos($sql, 'log_') !== FALSE && self::$logColumns !== NULL) { return new TestRows(self::$logColumns); }
      return new TestRows(self::$columns);
    }
    if (strpos($sql, 'SHOW VARIABLES') === 0) {
      $variables = ['innodb_page_size' => 16384, 'innodb_large_prefix' => 'ON'];
      return new TestRows(isset($variables[$params[1][0]]) ? [['Value' => $variables[$params[1][0]]]] : []);
    }
    if (strpos($sql, 'FROM information_schema.STATISTICS') !== FALSE) {
      return new TestRows($schema === 'test_database' ? self::like(self::$indexes, 'table_name', $params[2][0]) : []);
    }
    if (strpos($sql, 'AS size_bytes') !== FALSE) {
      $rows = [['TABLE_NAME' => 'civicrm_probe', 'size_bytes' => 100, 'TABLE_ROWS' => 1]];
      if ($schema !== 'test_database' || DB::$database === 'test_database') {
        foreach (self::$logTables as $row) { $rows[] = ['TABLE_NAME' => $row['Name'], 'size_bytes' => 200, 'TABLE_ROWS' => 2]; }
      }
      return new TestRows(self::like($rows, 'TABLE_NAME', $params[2][0]));
    }
    if (strpos($sql, 'AS legacy_columns') !== FALSE) {
      return new TestRows(self::like(self::$legacy[$schema] ?? [], 'table_name', $params[2][0]));
    }
    if (strpos($sql, 'INSERT INTO civicrm_collationfix_log') === 0 || strpos($sql, 'UPDATE civicrm_collationfix_log') === 0) {
      self::$events[] = ['operation' => strtok($sql, ' '), 'status' => $params[6][0], 'table' => $params[1][0]];
      return new TestRows([]);
    }
    if (strpos($sql, 'ALTER TABLE') === 0) {
      self::$events[] = ['operation' => 'ALTER', 'sql' => $sql];
      if (self::$onAlter) { call_user_func(self::$onAlter); }
      return new TestRows([]);
    }
    throw new Exception('Unexpected query: ' . $sql);
  }
}
class CRM_Core_Session {
  public static function getLoggedInContactID() { return NULL; }
}
class CRM_Queue_TaskContext {}
class CRM_Queue_Task {
  public $arguments;
  public function __construct($callback, $arguments, $title) { $this->arguments = $arguments; }
}
class CRM_Queue_Service {
  public static $created = 0;
  public $items = [];
  public static function singleton() { return new self(); }
  public function create($params) { self::$created++; return $this; }
  public function createItem($task) { $this->items[] = $task; }
}
class CRM_Core_Form {
  public $state = [];
  public $assigned = [];
  public function preProcess() {}
  public function get($key) { return $this->state[$key] ?? NULL; }
  public function set($key, $value) { $this->state[$key] = $value; }
  public function assign($key, $value) { $this->assigned[$key] = $value; }
  public function add(...$args) {}
  public function addButtons($buttons) {}
}
class CRM_Utils_System {
  public static function setTitle($title) {}
}
function civicrm_api3_create_success($values, ...$args) { return ['is_error' => 0, 'values' => $values]; }
function civicrm_api3_create_error($message, $extra) { return ['is_error' => 1, 'error_message' => $message] + $extra; }

require __DIR__ . '/../CRM/Collationfix/Analyzer.php';
require __DIR__ . '/../CRM/Collationfix/Converter.php';
require __DIR__ . '/../CRM/Collationfix/Form/Convert.php';
require __DIR__ . '/../api/v3/Collationfix/Convert.php';

class TestAnalyzer extends CRM_Collationfix_Analyzer {
  public static function bytes($part) { return parent::indexPartBytes($part); }
}
function expect($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}
function contains($needle, $text) {
  expect(strpos($text, $needle) !== FALSE, 'Missing ' . $needle . ' in ' . $text);
}
function refuses(callable $call) {
  try { $call(); }
  catch (CRM_Core_Exception $e) { return; }
  throw new Exception('Expected conversion to be refused');
}
function hasAlter() {
  return in_array('ALTER', array_column(CRM_Core_DAO::$events, 'operation'), TRUE);
}
function schema($definition, $suffix = '') {
  return "CREATE TABLE `civicrm_probe` (\n  " . $definition . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8" . $suffix;
}
function resetFixture() {
  $_POST = [];
  Civi::$target = 'utf8mb4_unicode_ci';
  CRM_Queue_Service::$created = 0;
  CRM_Core_DAO::$ddl = schema("`v` varchar(100) DEFAULT 'CURRENT_TIMESTAMP'");
  CRM_Core_DAO::$columns = [['Field' => 'v', 'Type' => 'varchar(100)', 'Collation' => 'utf8_unicode_ci', 'Extra' => '', 'Null' => 'YES', 'Comment' => '', 'Default' => 'CURRENT_TIMESTAMP']];
  CRM_Core_DAO::$meta = ['Engine' => 'InnoDB', 'Collation' => 'utf8_unicode_ci', 'Row_format' => 'Dynamic'];
  CRM_Core_DAO::$events = CRM_Core_DAO::$indexes = [];
  CRM_Core_DAO::$ddlReads = 0;
  CRM_Core_DAO::$failColumns = FALSE;
  CRM_Core_DAO::$onDDL = NULL;
  CRM_Core_DAO::$onAlter = function () {
    CRM_Core_DAO::$meta['Collation'] = Civi::$target;
    CRM_Core_DAO::$columns[0]['Collation'] = Civi::$target;
    CRM_Core_DAO::$ddl = str_replace('DEFAULT CHARSET=utf8', 'DEFAULT CHARSET=utf8mb4', CRM_Core_DAO::$ddl);
    foreach (CRM_Core_DAO::$logTables as &$row) { $row['Collation'] = Civi::$target; }
  };
  Civi::$logging = FALSE;
  DB::$database = 'test_database';
  CRM_Logging_Schema::$names = [];
  CRM_Core_DAO::$logTables = CRM_Core_DAO::$legacy = CRM_Core_DAO::$queries = [];
  CRM_Core_DAO::$logColumns = NULL;
  CRM_Core_DAO::$mainLegacyCount = 0;
  CRM_Core_DAO::$failLogStatus = FALSE;
}
/** Detailed logging on, with log_civicrm_probe in the given logging database. */
function enableLogging($database = 'test_logs', $engine = 'InnoDB') {
  Civi::$logging = TRUE;
  DB::$database = $database;
  CRM_Logging_Schema::$names = ['log_civicrm_probe'];
  CRM_Core_DAO::$logTables = [
    ['Name' => 'log_civicrm_probe', 'Engine' => $engine, 'Collation' => 'utf8_unicode_ci', 'Row_format' => $engine === 'ARCHIVE' ? 'Compressed' : 'Dynamic'],
    // Not listed by CRM_Logging_Schema, so never in scope.
    ['Name' => 'log_unrelated', 'Engine' => 'InnoDB', 'Collation' => 'utf8_unicode_ci', 'Row_format' => 'Dynamic'],
  ];
}
function legacyRow($name, $engine = 'InnoDB') {
  return ['table_name' => $name, 'engine' => $engine, 'table_collation' => 'utf8_unicode_ci'];
}
function analyze() { return (new CRM_Collationfix_Analyzer())->analyzeTable('civicrm_probe'); }
function part($type, $extra = []) {
  return $extra + ['table_name' => 'civicrm_probe', 'index_name' => 'mixed_key', 'column_name' => 'v', 'data_type' => $type, 'column_type' => $type, 'sub_part' => NULL, 'charset' => NULL, 'char_length' => NULL, 'octet_length' => NULL, 'numeric_precision' => NULL, 'numeric_scale' => NULL, 'datetime_precision' => NULL];
}

$tests = [];
foreach (['`v`', '"v"', 'v'] as $identifier) {
  $tests['expression default with identifier ' . $identifier] = function () use ($identifier) {
    CRM_Core_DAO::$ddl = schema($identifier . ' varchar(100) DEFAULT (uuid())');
    CRM_Core_DAO::$columns[0]['Default'] = 'uuid()';
    $item = analyze();
    expect(!$item['blocked'], 'Valid expression was blocked');
    contains('DEFAULT (uuid())', $item['alter']);
  };
}
$tests['literal timestamp remains literal'] = function () {
  contains("DEFAULT 'CURRENT_TIMESTAMP'", analyze()['alter']);
};
$tests['quotes and backslashes survive defaults and comments'] = function () {
  CRM_Core_DAO::$columns[0]['Default'] = "C:\\new\\O'Reilly";
  CRM_Core_DAO::$columns[0]['Comment'] = "C:\\new\\O'Reilly";
  $sql = analyze()['alter'];
  contains("DEFAULT 'C:\\\\new\\\\O\\'Reilly'", $sql);
  contains("COMMENT 'C:\\\\new\\\\O\\'Reilly'", $sql);
};
$tests['nested expression ignores quoted parentheses and keywords'] = function () {
  CRM_Core_DAO::$ddl = schema("`v` varchar(100) DEFAULT (concat(') DEFAULT (',uuid()))");
  CRM_Core_DAO::$columns[0]['Default'] = "concat(') DEFAULT (',uuid())";
  contains("DEFAULT (concat(') DEFAULT (',uuid()))", analyze()['alter']);
};
$tests['missing column definition blocks rather than quoting expression'] = function () {
  CRM_Core_DAO::$ddl = schema('`other` varchar(100) DEFAULT (uuid())');
  CRM_Core_DAO::$columns[0]['Default'] = 'uuid()';
  $item = analyze();
  expect($item['blocked'] && $item['needs_change'] && $item['alter'] === '', 'Missing definition must block SQL');
};
$tests['malformed expression blocks'] = function () {
  CRM_Core_DAO::$ddl = schema('`v` varchar(100) DEFAULT (concat(uuid()');
  CRM_Core_DAO::$columns[0]['Default'] = 'concat(uuid()';
  expect(analyze()['blocked'], 'Malformed expression must block');
};
$tests['unreadable schema blocks even when table default is current'] = function () {
  CRM_Core_DAO::$ddl = NULL;
  CRM_Core_DAO::$meta['Collation'] = Civi::$target;
  expect(analyze()['blocked'], 'Missing DDL must block');
};
$tests['sole skipped generated column remains visible and errors on conversion'] = function () {
  CRM_Core_DAO::$meta['Collation'] = Civi::$target;
  CRM_Core_DAO::$ddl = schema('`v` varchar(100)');
  CRM_Core_DAO::$columns[0]['Extra'] = 'VIRTUAL GENERATED';
  CRM_Core_DAO::$columns[0]['Default'] = NULL;
  $item = analyze();
  expect($item['blocked'] && $item['needs_change'] && $item['alter'] === '', 'Skipped generated column was hidden');
  $result = CRM_Collationfix_Converter::convertTable('civicrm_probe');
  expect($result['status'] === 'error' && !hasAlter(), 'Blocked table was executed or skipped');
};
$tests['generated expressions with quoted punctuation are retained'] = function () {
  CRM_Core_DAO::$ddl = schema("v varchar(100) GENERATED ALWAYS AS (concat('x)', 'y')) VIRTUAL");
  CRM_Core_DAO::$columns[0]['Extra'] = 'VIRTUAL GENERATED';
  CRM_Core_DAO::$columns[0]['Default'] = NULL;
  contains("GENERATED ALWAYS AS (concat('x)', 'y')) VIRTUAL", analyze()['alter']);
};
$tests['fingerprints ignore sequence movement but retain schema comments'] = function () {
  $analyzer = new CRM_Collationfix_Analyzer();
  CRM_Core_DAO::$ddl = schema("`v` varchar(100) COMMENT 'AUTO_INCREMENT=1'", ' AUTO_INCREMENT=10');
  $before = $analyzer->getSchemaFingerprint('civicrm_probe');
  CRM_Core_DAO::$ddl = str_replace('AUTO_INCREMENT=10', 'AUTO_INCREMENT=20', CRM_Core_DAO::$ddl);
  expect($before === $analyzer->getSchemaFingerprint('civicrm_probe'), 'Data insert invalidated plan');
  CRM_Core_DAO::$ddl = str_replace("COMMENT 'AUTO_INCREMENT=1'", "COMMENT 'AUTO_INCREMENT=2'", CRM_Core_DAO::$ddl);
  expect($before !== $analyzer->getSchemaFingerprint('civicrm_probe'), 'Schema comment change was ignored');
};
$tests['schema changes before queue creation require new review'] = function () {
  $review = ['civicrm_probe' => analyze()];
  CRM_Core_DAO::$ddl = str_replace('varchar(100)', 'varchar(200)', CRM_Core_DAO::$ddl);
  refuses(function () use ($review) { CRM_Collationfix_Converter::buildQueue(['civicrm_probe'], Civi::$target, $review); });
  expect(CRM_Queue_Service::$created === 0 && !hasAlter(), 'Stale review created a queue');
};
$tests['queued tasks reject subsequent schema changes'] = function () {
  $queue = CRM_Collationfix_Converter::buildQueue(['civicrm_probe']);
  CRM_Core_DAO::$ddl .= ' COMMENT=\'changed\'';
  CRM_Collationfix_Converter::convertTableTask(new CRM_Queue_TaskContext(), ...$queue->items[0]->arguments);
  expect(!hasAlter(), 'Stale queued plan executed');
  expect(end(CRM_Core_DAO::$events)['status'] === 'error', 'Stale queued plan did not log error');
};
$tests['queue keeps target pinned when settings change'] = function () {
  $queue = CRM_Collationfix_Converter::buildQueue(['civicrm_probe']);
  Civi::$target = 'utf8mb4_general_ci';
  expect($queue->items[0]->arguments[1] === 'utf8mb4_unicode_ci', 'Queue did not capture target');
  CRM_Core_DAO::$onAlter = function () {
    CRM_Core_DAO::$meta['Collation'] = CRM_Core_DAO::$columns[0]['Collation'] = 'utf8mb4_unicode_ci';
  };
  CRM_Collationfix_Converter::convertTableTask(new CRM_Queue_TaskContext(), ...$queue->items[0]->arguments);
  expect(hasAlter() && end(CRM_Core_DAO::$events)['status'] === 'success', 'Pinned queue did not finish');
};
$tests['old queued tasks without review fail safely'] = function () {
  CRM_Collationfix_Converter::convertTableTask(new CRM_Queue_TaskContext(), 'civicrm_probe');
  expect(!hasAlter() && end(CRM_Core_DAO::$events)['status'] === 'error', 'Unreviewed queued task executed');
};
$tests['schema changes immediately before ALTER are rejected'] = function () {
  CRM_Core_DAO::$onDDL = function ($count) {
    if ($count === 2) { CRM_Core_DAO::$ddl .= ' COMMENT=\'changed\''; }
  };
  $result = CRM_Collationfix_Converter::convertTable('civicrm_probe');
  expect($result['status'] === 'error' && !hasAlter(), 'Changed schema executed');
};
$tests['successful conversion records running before ALTER and updates same log'] = function () {
  $result = CRM_Collationfix_Converter::convertTable('civicrm_probe');
  expect($result['status'] === 'success', 'Successful conversion failed');
  expect(array_column(CRM_Core_DAO::$events, 'operation') === ['INSERT', 'ALTER', 'UPDATE'], 'Audit order incorrect');
  expect(CRM_Core_DAO::$events[0]['status'] === 'running', 'Missing running record');
};
$tests['verification exception cannot return success'] = function () {
  CRM_Core_DAO::$onAlter = function () { CRM_Core_DAO::$failColumns = TRUE; };
  $result = CRM_Collationfix_Converter::convertTable('civicrm_probe');
  expect($result['status'] === 'error', 'Unverified result reported success');
  contains('could not be verified', $result['error_message']);
};
$tests['form submission retains the reviewed snapshot'] = function () {
  $_POST = ['tables' => ['civicrm_probe']];
  $form = new CRM_Collationfix_Form_Convert();
  $form->preProcess();
  $form->buildQuickForm();
  $review = $form->get('reviewedAnalysis');
  CRM_Core_DAO::$ddl .= ' COMMENT=\'changed\'';
  $_POST = ['_qf_Convert_submit' => 1];
  $form->preProcess();
  $form->buildQuickForm();
  expect($form->get('reviewedAnalysis') === $review, 'Submission silently refreshed review');
  refuses(function () use ($form) { $form->postProcess(); });
  expect(CRM_Queue_Service::$created === 0, 'Stale form created queue');
};
$tests['dry-run API exposes blocked tables and no executable SQL'] = function () {
  CRM_Core_DAO::$ddl = schema('`other` varchar(100)');
  $result = civicrm_api3_collationfix_convert(['dry_run' => 1]);
  $item = $result['values']['civicrm_probe'];
  expect($item['blocked'] && $item['errors'] && $item['statement'] === '', 'API omitted blocked result');
};
$tests['conversion API returns error for blocked table'] = function () {
  CRM_Core_DAO::$ddl = schema('`other` varchar(100)');
  $result = civicrm_api3_collationfix_convert(['tables' => 'civicrm_probe']);
  expect($result['is_error'] === 1 && !hasAlter(), 'API did not reject blocked conversion');
};
$tests['composite index includes integer bytes at boundary'] = function () {
  CRM_Core_DAO::$indexes = [part('varchar', ['charset' => 'utf8', 'char_length' => 768, 'octet_length' => 2304]), part('int')];
  $item = (new CRM_Collationfix_Analyzer())->analyze()['civicrm_probe'];
  contains('3076 bytes', implode(' ', $item['warnings']));
};
$tests['prefix index uses prefix length and fits exactly'] = function () {
  CRM_Core_DAO::$indexes = [part('varchar', ['charset' => 'utf8', 'char_length' => 1000, 'octet_length' => 3000, 'sub_part' => 767]), part('int')];
  $item = (new CRM_Collationfix_Analyzer())->analyze()['civicrm_probe'];
  expect($item['warnings'] === [], 'Valid 3072-byte index warned');
};
$tests['binary and temporal parts contribute to index size'] = function () {
  CRM_Core_DAO::$indexes = [part('varchar', ['charset' => 'utf8', 'char_length' => 760, 'octet_length' => 2280]), part('varbinary', ['octet_length' => 32]), part('datetime', ['datetime_precision' => 6])];
  $item = (new CRM_Collationfix_Analyzer())->analyze()['civicrm_probe'];
  contains('3080 bytes', implode(' ', $item['warnings']));
};
$tests['decimal enum set and bit sizes are included'] = function () {
  expect(TestAnalyzer::bytes(part('decimal', ['numeric_precision' => 20, 'numeric_scale' => 6])) === 10, 'Decimal packing wrong');
  expect(TestAnalyzer::bytes(part('enum', ['column_type' => "enum('a,b','c')"])) === 1, 'Quoted comma counted as enum member');
  expect(TestAnalyzer::bytes(part('set', ['column_type' => "set('a','b','c','d','e','f','g','h','i')"])) === 2, 'Set size wrong');
  expect(TestAnalyzer::bytes(part('bit', ['numeric_precision' => 9])) === 2, 'Bit size wrong');
};
$tests['unknown or functional index parts warn rather than disappear'] = function () {
  CRM_Core_DAO::$indexes = [part(NULL)];
  $item = (new CRM_Collationfix_Analyzer())->analyze()['civicrm_probe'];
  contains('cannot be determined', implode(' ', $item['warnings']));
};

$tests['logging disabled leaves log tables out and never reads the logging database'] = function () {
  enableLogging();
  Civi::$logging = FALSE;
  $analysis = (new CRM_Collationfix_Analyzer())->analyze();
  expect(array_keys($analysis) === ['civicrm_probe'], 'Log table analyzed while logging is disabled');
  expect(!preg_grep('/test_logs/', CRM_Core_DAO::$queries), 'Logging database was queried while logging is disabled');
};
$tests['separate logging database tables are qualified in keys and SQL'] = function () {
  enableLogging();
  $analysis = (new CRM_Collationfix_Analyzer())->analyze();
  expect(array_keys($analysis) === ['civicrm_probe', 'test_logs.log_civicrm_probe'], 'Unexpected tables: ' . implode(', ', array_keys($analysis)));
  $log = $analysis['test_logs.log_civicrm_probe'];
  expect($log['is_log'] && $log['database'] === 'test_logs' && $log['base_table'] === 'civicrm_probe', 'Log table identity wrong');
  expect($log['size_bytes'] === 200, 'Log table size missing');
  contains("ALTER TABLE `test_logs`.`log_civicrm_probe`\n", $log['alter']);
  contains('ROW_FORMAT = Dynamic', $log['alter']);
  contains("ALTER TABLE `civicrm_probe`\n", $analysis['civicrm_probe']['alter']);
  expect(in_array('SHOW CREATE TABLE `test_logs`.`log_civicrm_probe`', CRM_Core_DAO::$queries, TRUE), 'Log schema read from the wrong database');
};
$tests['log tables in the CiviCRM database keep bare names'] = function () {
  enableLogging('test_database');
  $analysis = (new CRM_Collationfix_Analyzer())->analyze();
  expect(isset($analysis['log_civicrm_probe']) && $analysis['log_civicrm_probe']['is_log'], 'Same-database log table missing');
  contains("ALTER TABLE `log_civicrm_probe`\n", $analysis['log_civicrm_probe']['alter']);
};
$tests['tables not listed by the logging schema are ignored'] = function () {
  enableLogging();
  CRM_Logging_Schema::$names = [];
  expect(array_keys((new CRM_Collationfix_Analyzer())->getTables()) === ['civicrm_probe'], 'Unlisted log table included');
};
$tests['ARCHIVE log tables keep their engine and row format'] = function () {
  enableLogging('test_logs', 'ARCHIVE');
  $item = (new CRM_Collationfix_Analyzer())->analyzeTable('test_logs.log_civicrm_probe');
  expect(strpos($item['alter'], 'ROW_FORMAT') === FALSE, 'ARCHIVE table given a row format');
  contains('CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;', $item['alter']);
  contains('ARCHIVE engine', implode(' ', $item['warnings']));
};
$tests['queue and API convert log tables first'] = function () {
  enableLogging();
  $queue = CRM_Collationfix_Converter::buildQueue(['civicrm_probe', 'test_logs.log_civicrm_probe']);
  expect($queue->items[0]->arguments[0] === 'test_logs.log_civicrm_probe', 'Queue did not start with the log table');
  CRM_Core_DAO::$events = [];
  civicrm_api3_collationfix_convert(['tables' => 'civicrm_probe,test_logs.log_civicrm_probe']);
  expect(CRM_Core_DAO::$events[0]['table'] === 'test_logs.log_civicrm_probe', 'API did not start with the log table');
};
$tests['converting a log table alters the logging database and logs its qualified name'] = function () {
  enableLogging();
  $result = CRM_Collationfix_Converter::convertTable('test_logs.log_civicrm_probe');
  expect($result['status'] === 'success', 'Log table conversion failed: ' . $result['error_message']);
  $alter = array_values(array_filter(CRM_Core_DAO::$events, function ($e) { return $e['operation'] === 'ALTER'; }))[0]['sql'];
  contains('ALTER TABLE `test_logs`.`log_civicrm_probe`', $alter);
  expect(CRM_Core_DAO::$events[0]['table'] === 'test_logs.log_civicrm_probe', 'Audit log has the wrong table name');
};
$tests['a table needing conversion warns about its log table'] = function () {
  enableLogging();
  $analysis = (new CRM_Collationfix_Analyzer())->analyze();
  contains('Convert its log table test_logs.log_civicrm_probe with it', implode(' ', $analysis['civicrm_probe']['warnings']));
};
$tests['a log table warns when its table is already converted'] = function () {
  enableLogging();
  CRM_Core_DAO::$logColumns = CRM_Core_DAO::$columns;
  CRM_Core_DAO::$meta['Collation'] = CRM_Core_DAO::$columns[0]['Collation'] = Civi::$target;
  $analysis = (new CRM_Collationfix_Analyzer())->analyze();
  expect(!$analysis['civicrm_probe']['needs_change'], 'Converted table still needs a change');
  contains('civicrm_probe is already on utf8mb4', implode(' ', $analysis['test_logs.log_civicrm_probe']['warnings']));
};
$tests['confirmation lists unselected log tables and confirms the logging database'] = function () {
  enableLogging();
  $_POST = ['tables' => ['civicrm_probe']];
  $form = new CRM_Collationfix_Form_Convert();
  $form->preProcess();
  $form->buildQuickForm();
  expect($form->assigned['unselectedLogTables'] === ['civicrm_probe' => 'test_logs.log_civicrm_probe'], 'Unselected log table not reported');
  expect(!$form->assigned['confirmLoggingDatabase'], 'Logging database confirmation required without log tables');

  $_POST = ['tables' => ['civicrm_probe', 'test_logs.log_civicrm_probe']];
  $form = new CRM_Collationfix_Form_Convert();
  $form->preProcess();
  $form->buildQuickForm();
  expect(array_keys($form->assigned['tables']) === ['test_logs.log_civicrm_probe', 'civicrm_probe'], 'Review not in conversion order');
  expect($form->assigned['unselectedLogTables'] === [] && $form->assigned['confirmLoggingDatabase'], 'Logging confirmation missing');
  $errors = CRM_Collationfix_Form_Convert::validateConfirmation(['confirm_database' => 'test_database'], [], $form);
  expect(isset($errors['confirm_logging_database']), 'Missing logging database name accepted');
  expect(CRM_Collationfix_Form_Convert::validateConfirmation(['confirm_database' => 'test_database', 'confirm_logging_database' => 'test_logs'], [], $form) === TRUE, 'Correct names rejected');
};
$tests['API dry run names log tables and rejects bare names from a separate database'] = function () {
  enableLogging();
  $result = civicrm_api3_collationfix_convert(['dry_run' => 1]);
  expect(!empty($result['values']['test_logs.log_civicrm_probe']['is_log']), 'Dry run omitted the log table');
  refuses(function () { civicrm_api3_collationfix_convert(['tables' => 'log_civicrm_probe']); });
};
$tests['scope report explains why log tables are not converted'] = function () {
  enableLogging();
  Civi::$logging = FALSE;
  CRM_Core_DAO::$legacy = [
    'test_logs' => [legacyRow('log_civicrm_probe'), legacyRow('log_unrelated')],
    'test_database' => [legacyRow('civicrm_probe'), legacyRow('civicrm_myisam', 'MyISAM'), legacyRow('log_civicrm_old')],
  ];
  $uncovered = (new CRM_Collationfix_Analyzer())->getUncoveredTables();
  expect(array_keys($uncovered) === ['civicrm_myisam', 'test_logs.log_civicrm_probe', 'log_civicrm_old'], 'Unexpected scope report: ' . implode(', ', array_keys($uncovered)));
  contains('logging is disabled', $uncovered['test_logs.log_civicrm_probe']['reason']);
  contains('Not in the logging database (test_logs)', $uncovered['log_civicrm_old']['reason']);

  Civi::$logging = TRUE;
  CRM_Logging_Schema::$names[] = 'log_civicrm_myisam';
  CRM_Core_DAO::$legacy['test_logs'] = [legacyRow('log_civicrm_probe'), legacyRow('log_civicrm_myisam', 'MyISAM')];
  $uncovered = (new CRM_Collationfix_Analyzer())->getUncoveredTables();
  expect(!isset($uncovered['test_logs.log_civicrm_probe']), 'Converted log table listed as out of scope');
  contains('MyISAM engine', $uncovered['test_logs.log_civicrm_myisam']['reason']);
};
$tests['status check counts log tables only while logging is enabled'] = function () {
  enableLogging();
  CRM_Logging_Schema::$names[] = 'log_civicrm_myisam';
  CRM_Core_DAO::$mainLegacyCount = 2;
  CRM_Core_DAO::$legacy = ['test_logs' => [legacyRow('log_civicrm_probe'), legacyRow('log_civicrm_myisam', 'MyISAM'), legacyRow('log_unrelated')]];
  expect(CRM_Collationfix_Analyzer::countNonUtf8mb4Tables() === 3, 'Log table not counted');
  Civi::$logging = FALSE;
  expect(CRM_Collationfix_Analyzer::countNonUtf8mb4Tables() === 2, 'Log table counted while logging is disabled');
};
$tests['unreadable logging database is reported rather than fatal'] = function () {
  enableLogging();
  CRM_Core_DAO::$failLogStatus = TRUE;
  $analyzer = new CRM_Collationfix_Analyzer();
  expect(array_keys($analyzer->analyze()) === ['civicrm_probe'], 'Analysis failed with an unreadable logging database');
  contains('Access denied to logging database', implode(' ', $analyzer->getLoggingErrors()));
};

$failed = 0;
foreach ($tests as $name => $test) {
  resetFixture();
  try {
    $test();
    echo "PASS: $name\n";
  }
  catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, "FAIL: $name: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n");
  }
}
echo count($tests) . " tests, $failed failures\n";
exit($failed ? 1 : 0);
