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
    'description' => E::ts('One or more civicrm_* InnoDB tables, as an array or a comma-separated string.'),
    'api.required' => 1,
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
  $tables = $params['tables'];
  if (!is_array($tables)) {
    $tables = explode(',', (string) $tables);
  }
  $tables = array_values(array_unique(array_filter(array_map('trim', $tables), 'strlen')));
  if (!$tables) {
    throw new CRM_Core_Exception(E::ts('No tables given.'));
  }

  // Check every name before converting anything, so a typo cannot leave the
  // run half-done.
  $analyzer = new CRM_Collationfix_Analyzer();
  $unknown = array_diff($tables, array_keys($analyzer->getTables()));
  if ($unknown) {
    throw new CRM_Core_Exception(E::ts('Not a civicrm_* InnoDB table: %1', [1 => implode(', ', $unknown)]));
  }

  $values = [];
  if (!empty($params['dry_run'])) {
    foreach ($analyzer->analyze($tables) as $table => $item) {
      $values[$table] = [
        'table_name' => $table,
        'needs_change' => $item['needs_change'],
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

  // Like the UI queue, carry on past a failed table; report failures at the
  // end so CLI callers get a non-zero exit.
  $failed = [];
  foreach ($tables as $table) {
    $values[$table] = CRM_Collationfix_Converter::convertTable($table);
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
