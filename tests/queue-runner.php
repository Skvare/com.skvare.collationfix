<?php

/** Standalone hook checks; no CiviCRM bootstrap or database connection. */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

class CRM_Queue_Page_Runner {
  public $data;
  public function getTemplateVars($name) { return $name === 'queueRunnerData' ? $this->data : NULL; }
}
class CRM_Core_Resources {
  public static $calls = [];
  public static function singleton() { return new self(); }
  public function addVars($namespace, $vars) {
    self::$calls['vars'] = [$namespace, $vars];
    return $this;
  }
  public function addScriptFile($extension, $file) {
    self::$calls['script'] = [$extension, $file];
    return $this;
  }
}
class CRM_Utils_System {
  public static function url($path, $query) { return '/' . $path . '?' . $query; }
}

require __DIR__ . '/../collationfix.php';
require __DIR__ . '/../CRM/Collationfix/Converter.php';

function expect($condition, $message) {
  if (!$condition) { throw new Exception($message); }
}

$page = new stdClass();
collationfix_civicrm_pageRun($page);
expect(CRM_Core_Resources::$calls === [], 'Unrelated page was modified');
$page = new CRM_Queue_Page_Runner();
foreach ([NULL, [], ['qrid' => 'other_queue'], ['qrid' => 'com.skvare.collationfixother_1']] as $data) {
  $page->data = $data;
  collationfix_civicrm_pageRun($page);
  expect(CRM_Core_Resources::$calls === [], 'Unrelated runner was modified');
}
$page->data = [
  'qrid' => 'com.skvare.collationfix_123',
  'runNextAjax' => '/queue/ajax/runNext',
  'skipNextAjax' => '/queue/ajax/skipNext',
  'onEndAjax' => '/queue/ajax/onEnd',
];
collationfix_civicrm_pageRun($page);
expect(CRM_Core_Resources::$calls['script'] === ['com.skvare.collationfix', 'js/queue-runner.js'], 'Runner script not loaded');
[$namespace, $vars] = CRM_Core_Resources::$calls['vars'];
expect($namespace === 'collationfixRunner' && $vars['qrid'] === $page->data['qrid'], 'Wrong queue configuration');
expect($vars['urls'] === ['/queue/ajax/runNext', '/queue/ajax/skipNext', '/queue/ajax/onEnd'], 'Wrong endpoints');
expect($vars['logUrl'] === '/civicrm/admin/collationfix/log?reset=1', 'Missing recovery link');
echo "PASS: runner hook is scoped to Collation Fix and supplies recovery links\n";
