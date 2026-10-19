<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Collationfix.Convert API specification.
 *
 * @param array $spec
 */
function _civicrm_api3_collationfix_convert_spec(&$spec) {
  $spec['tables'] = [
    'title' => E::ts('Tables'),
    'description' => E::ts('One or more civicrm_* InnoDB tables, or log tables while detailed logging is enabled ("database.log_civicrm_*" when they are in a separate logging database), as an array or a comma-separated string. Required unless dry_run is set; a dry run without tables analyzes every table.'),
  ];
  $spec['dry_run'] = [
    'title' => E::ts('Dry run'),
    'description' => E::ts('Return the generated ALTER statements without executing them.'),
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => 0,
  ];
}

/**
 * Collationfix.Convert API.
 *
 * Converts the given tables one after another in the current process, with
 * the same analysis, post-conversion verification and audit log as the UI
 * queue. Meant for the command line (cv, drush, wp-cli), where a large table
 * cannot hit a web request timeout.
 *
 * Example:
 *   cv api Collationfix.convert tables=civicrm_contact,civicrm_activity
 *
 * @param array $params
 *
 * @return array
 * @throws CRM_Core_Exception
 */
function civicrm_api3_collationfix_convert($params) {
  $tables = $params['tables'] ?? [];
  if (!is_array($tables)) {
    $tables = explode(',', (string) $tables);
  }
  $tables = array_values(array_unique(array_filter(array_map('trim', $tables), 'strlen')));
  if (!$tables && empty($params['dry_run'])) {
    throw new CRM_Core_Exception(E::ts('No tables given.'));
  }

  // Check every name before converting anything, so a typo cannot leave the
  // run half-done.
  $analyzer = new CRM_Collationfix_Analyzer();
  $unknown = array_diff($tables, array_keys($analyzer->getTables()));
  if ($unknown) {
    throw new CRM_Core_Exception(E::ts('Not a table this tool converts: %1. Use the names a dry run lists; log tables are included only while detailed logging is enabled.', [1 => implode(', ', $unknown)]));
  }

  $values = [];
  if (!empty($params['dry_run'])) {
    foreach ($analyzer->analyze($tables ?: NULL) as $table => $item) {
      $values[$table] = [
        'table_name' => $table,
        'database' => $item['database'],
        'is_log' => $item['is_log'],
        'status' => $item['status'],
        'needs_change' => $item['needs_change'],
        'blocked' => $item['blocked'],
        'errors' => $item['errors'],
        'schema_fingerprint' => $item['schema_fingerprint'],
        'collation_before' => $item['current_collation'],
        'target_collation' => $item['target_collation'],
        'columns' => count($item['columns']),
        'size' => $item['size_formatted'],
        'warnings' => $item['warnings'],
        'statement' => $item['alter'],
      ];
    }
    return civicrm_api3_create_success($values, $params, 'Collationfix', 'convert');
  }

  // Like the UI queue, carry on past a failed table, and convert log tables
  // first; a table whose log table failed in this call is left unchanged.
  // Report failures at the end so CLI callers get a non-zero exit. Every
  // table converts to the target collation read at the start of the call.
  $failed = [];
  foreach ($analyzer->orderForConversion($tables) as $table) {
    $logTable = $analyzer->getLogTableKey($table);
    $values[$table] = CRM_Collationfix_Converter::convertTable($table, $analyzer->getTargetCollation(), NULL,
      in_array($logTable, $tables, TRUE) ? $logTable : NULL);
    if ($values[$table]['status'] === 'error') {
      $failed[] = $table;
    }
  }
  if ($failed) {
    return civicrm_api3_create_error(E::ts('%1 of %2 tables failed: %3', [
      1 => count($failed),
      2 => count($tables),
      3 => implode(', ', $failed),
    ]), ['values' => $values]);
  }
  return civicrm_api3_create_success($values, $params, 'Collationfix', 'convert');
}
