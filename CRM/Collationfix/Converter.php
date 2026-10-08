<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Executes conversions via CRM_Queue (one table per task) and writes the
 * audit log to civicrm_collationfix_log.
 */
class CRM_Collationfix_Converter {

  const QUEUE_NAME_PREFIX = 'com.skvare.collationfix';

  /**
   * Build a queue with one conversion task per table.
   *
   * @param array $tables
   *   Table names to convert.
   *
   * @return CRM_Queue_Queue
   */
  public static function buildQueue(array $tables) {
    // Unique per run so two conversions started around the same time (two
    // admins, two tabs, a double-submit) can never reset/clobber each
    // other's queue - a fixed queue name would let the second call wipe out
    // the first run's still-pending items.
    $queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => self::QUEUE_NAME_PREFIX . '_' . uniqid(),
      'reset' => TRUE,
    ]);
    foreach ($tables as $table) {
      $queue->createItem(new CRM_Queue_Task(
        ['CRM_Collationfix_Converter', 'convertTableTask'],
        [$table],
        E::ts('Converting %1', [1 => $table])
      ));
    }
    return $queue;
  }

  /**
   * Queue task callback: convert a single table.
   *
   * The ALTER is regenerated at run time so it always reflects the current
   * schema, even if the analysis page was loaded a while ago.
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param string $table
   *
   * @return bool
   */
  public static function convertTableTask(CRM_Queue_TaskContext $ctx, $table) {
    self::convertTable($table);
    // Always continue the queue; failures are recorded in the log.
    return TRUE;
  }

  /**
   * Convert one table, timing the ALTER and recording the outcome.
   *
   * @param string $table
   *
   * @return array
   *   The log record that was written.
   */
  public static function convertTable($table) {
    $analyzer = new CRM_Collationfix_Analyzer();
    $log = [
      'table_name' => $table,
      'statement' => '',
      'collation_before' => '',
      'collation_after' => '',
      'duration_ms' => 0,
      'status' => 'skipped',
      'error_message' => '',
    ];

    try {
      $item = $analyzer->analyzeTable($table);
      $log['collation_before'] = $item['current_collation'];

      if (!$item['needs_change']) {
        $log['status'] = 'skipped';
        $log['error_message'] = E::ts('No change required.');
      }
      else {
        $log['statement'] = $item['alter'];
        $start = microtime(TRUE);
        CRM_Core_DAO::executeQuery($item['alter'], [], TRUE, NULL, FALSE, FALSE);
        $log['duration_ms'] = (int) round((microtime(TRUE) - $start) * 1000);
        $log['status'] = 'success';
      }
    }
    catch (Exception $e) {
      $log['status'] = 'error';
      $log['error_message'] = $e->getMessage();
    }

    // Verify the post-conversion state by re-analyzing the table, so column
    // collations are checked as well as the table default. A successful ALTER
    // that still leaves columns (or the default) off target is an error.
    try {
      $after = $analyzer->analyzeTable($table);
      $log['collation_after'] = $after['current_collation'];
      $remaining = [];
      foreach ($after['columns'] as $col) {
        $remaining[] = "{$col['field']} ({$col['current_collation']})";
      }
      foreach ($after['skipped_columns'] as $col) {
        $remaining[] = "{$col['field']} ({$col['reason']})";
      }
      if ($log['status'] === 'success' && ($remaining || $after['needs_change'])) {
        $log['status'] = 'error';
        $log['error_message'] = E::ts('The ALTER ran, but verification found the table is not fully converted. Table collation: %1. Unconverted columns: %2', [
          1 => $after['current_collation'],
          2 => $remaining ? implode(', ', $remaining) : E::ts('none'),
        ]);
      }
    }
    catch (Exception $e) {
      // Non-fatal.
    }

    self::writeLog($log);
    return $log;
  }

  /**
   * Insert a record into civicrm_collationfix_log.
   *
   * @param array $log
   */
  public static function writeLog(array $log) {
    $contactId = CRM_Core_Session::getLoggedInContactID();
    $params = [
      1 => [$log['table_name'], 'String'],
      2 => [(string) $log['statement'], 'String'],
      3 => [(string) $log['collation_before'], 'String'],
      4 => [(string) $log['collation_after'], 'String'],
      5 => [(int) $log['duration_ms'], 'Integer'],
      6 => [$log['status'], 'String'],
      7 => [(string) $log['error_message'], 'String'],
    ];
    // created_id is a nullable FK; CRM_Utils_Type won't bind NULL through an
    // 'Integer' placeholder, so fall back to a literal NULL when there is no
    // logged-in contact (e.g. run from cv/cron) rather than storing a bogus 0.
    $createdId = $contactId ? '%8' : 'NULL';
    if ($contactId) {
      $params[8] = [(int) $contactId, 'Integer'];
    }
    CRM_Core_DAO::executeQuery("
      INSERT INTO civicrm_collationfix_log
        (table_name, statement, collation_before, collation_after, duration_ms, status, error_message, created_id, created_date)
      VALUES (%1, %2, %3, %4, %5, %6, %7, {$createdId}, NOW())
    ", $params);
  }

}
