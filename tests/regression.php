<?php

/**
 * Standalone regression checks: php tests/regression.php
 * Database and CiviCRM boundaries are simulated; no database is accessed.
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

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
  public static function settings() { return new self(); }
  public function get($key) { return self::$target; }
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
  public static function getDatabaseName() { return 'test_database'; }
  public static function escapeString($value) {
    return str_replace(["\\", "\0", "\n", "\r", "'", '"', "\x1a"], ["\\\\", '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $value);
  }
  public static function singleValueQuery($sql) {
    if ($sql === 'SELECT LAST_INSERT_ID()') { return 42; }
    throw new Exception('Unexpected scalar query: ' . $sql);
  }
  public static function executeQuery($sql, $params = [], ...$unused) {
    $sql = trim($sql);
    if (strpos($sql, 'SHOW COLLATION') === 0) {
      return new TestRows([['Collation' => 'utf8mb4_unicode_ci'], ['Collation' => 'utf8mb4_general_ci']]);
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
      return new TestRows(self::$columns);
    }
    if (strpos($sql, 'SHOW VARIABLES') === 0) {
      $variables = ['innodb_page_size' => 16384, 'innodb_large_prefix' => 'ON'];
      return new TestRows(isset($variables[$params[1][0]]) ? [['Value' => $variables[$params[1][0]]]] : []);
    }
    if (strpos($sql, 'FROM information_schema.STATISTICS') !== FALSE) {
      return new TestRows(self::$indexes);
    }
    if (strpos($sql, 'AS size_bytes') !== FALSE) {
      return new TestRows([['TABLE_NAME' => 'civicrm_probe', 'size_bytes' => 100, 'TABLE_ROWS' => 1]]);
    }
    if (strpos($sql, 'INSERT INTO civicrm_collationfix_log') === 0 || strpos($sql, 'UPDATE civicrm_collationfix_log') === 0) {
      self::$events[] = ['operation' => strtok($sql, ' '), 'status' => $params[6][0]];
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
  };
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
