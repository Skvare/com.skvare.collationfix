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
   *   Table keys to convert (see CRM_Collationfix_Analyzer::getTableKey()).
   *   Log tables are queued before the other tables.
   * @param string|null $targetCollation
   *   The confirmed target collation. Every task converts to it, even if the
   *   setting changes while the queue runs.
   * @param array|null $reviewed
   *   Saved analysis from the confirmation screen, keyed by table. When
   *   omitted by a programmatic caller, capture a plan at queue creation.
   *
   * @return CRM_Queue_Queue
   */
  public static function buildQueue(array $tables, $targetCollation = NULL, ?array $reviewed = NULL) {
    $analyzer = new CRM_Collationfix_Analyzer($targetCollation);
    $targetCollation = $analyzer->getTargetCollation();
    $tables = $analyzer->orderForConversion($tables);
    $plans = [];
    // Validate all tables before creating a queue. Never replace the plan
    // saved by the confirmation form with a freshly generated approval.
    foreach ($tables as $table) {
      $item = $analyzer->analyzeTable($table);
      self::assertConvertible($item);
      if ($reviewed !== NULL) {
        self::assertPlanMatches($item, $reviewed[$table] ?? []);
      }
      $plans[$table] = ['schema_fingerprint' => $item['schema_fingerprint'], 'alter' => $item['alter']];
      // A table whose log table is in this run waits for it, see
      // convertTable().
      $logTable = $analyzer->getLogTableKey($table);
      if ($logTable !== NULL && in_array($logTable, $tables, TRUE)) {
        $plans[$table]['log_table'] = $logTable;
      }
    }
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
        [$table, $targetCollation, $plans[$table]],
        E::ts('Converting %1', [1 => $table])
      ));
    }
    return $queue;
  }

  /**
   * Queue task callback: convert a single table.
   *
   * Execute only while the schema and ALTER still match the reviewed plan.
   * Old tasks without a saved plan fail safely and require a fresh review.
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param string $table
   * @param string|null $targetCollation
   * @param array|null $plan
   *   Reviewed schema fingerprint and ALTER statement, and the log table
   *   converted earlier in the same run, if any.
   *
   * @return bool
   */
  public static function convertTableTask(CRM_Queue_TaskContext $ctx, $table, $targetCollation = NULL, ?array $plan = NULL) {
    self::convertTable($table, $targetCollation, $plan ?? [], $plan['log_table'] ?? NULL);
    // Always continue the queue; failures are recorded in the log. A table
    // whose log table failed is left unchanged by convertTable().
    return TRUE;
  }

  /**
   * Convert one table, timing the ALTER and recording the outcome.
   *
   * @param string $table
   *   Table key; "database.table" for a table in a separate logging database.
   * @param string|null $targetCollation
   *   Defaults to the target collation setting.
   * @param array|null $plan
   *   Optional reviewed plan. Direct CLI conversion analyzes immediately;
   *   queued conversion must provide its saved plan.
   * @param string|null $logTable
   *   This table's log table, when it was converted earlier in the same run.
   *   If it still needs conversion (its ALTER failed or was blocked), this
   *   table is left unchanged and the run records an error for it, so the
   *   pair stays consistent until the log table is fixed.
   *
   * @return array
   *   The log record that was written.
   */
  public static function convertTable($table, $targetCollation = NULL, ?array $plan = NULL, $logTable = NULL) {
    $analyzer = NULL;
    $log = [
      'table_name' => $table,
      'statement' => '',
      'collation_before' => '',
      'collation_after' => '',
      'duration_ms' => 0,
      'status' => 'skipped',
      'error_message' => '',
    ];
    $logId = NULL;

    try {
      $analyzer = new CRM_Collationfix_Analyzer($targetCollation);
      $item = $analyzer->analyzeTable($table);
      $log['collation_before'] = $item['current_collation'];
      self::assertConvertible($item);
      if ($plan !== NULL) {
        self::assertPlanMatches($item, $plan);
      }

      if (!$item['needs_change']) {
        $log['status'] = 'skipped';
        $log['error_message'] = E::ts('No change required.');
      }
      else {
        if ($logTable !== NULL) {
          self::assertLogTableConverted($analyzer, $logTable);
        }
        $log['statement'] = $item['alter'];
        // Record the attempt first, so a request killed mid-ALTER leaves a
        // 'running' row rather than no trace at all.
        $log['status'] = 'running';
        $logId = self::writeLog($log);
        if ($analyzer->getSchemaFingerprint($table) !== $item['schema_fingerprint']) {
          throw new CRM_Core_Exception(E::ts('The schema of %1 changed before execution. Review it again before converting.', [1 => $table]));
        }
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
      if ($analyzer === NULL) {
        throw new CRM_Core_Exception('Analysis was not initialized.');
      }
      $after = $analyzer->analyzeTable($table);
      $log['collation_after'] = $after['current_collation'];
      $remaining = [];
      foreach ($after['columns'] as $col) {
        $remaining[] = "{$col['field']} ({$col['current_collation']})";
      }
      foreach ($after['skipped_columns'] as $col) {
        $remaining[] = "{$col['field']} ({$col['reason']})";
      }
      if (in_array($log['status'], ['success', 'skipped'], TRUE) && ($remaining || $after['needs_change'] || $after['blocked'])) {
        $log['status'] = 'error';
        $log['error_message'] = E::ts('Verification found the table is not fully converted. Table collation: %1. Unconverted columns: %2', [
          1 => $after['current_collation'],
          2 => $remaining ? implode(', ', $remaining) : E::ts('none'),
        ]);
      }
    }
    catch (Exception $e) {
      // An unverified ALTER must not be reported as a success.
      if (in_array($log['status'], ['success', 'skipped'], TRUE)) {
        $log['status'] = 'error';
        $log['error_message'] = E::ts('The conversion result could not be verified: %1', [1 => $e->getMessage()]);
      }
    }

    self::writeLog($log, $logId);
    return $log;
  }

  protected static function assertConvertible(array $item) {
    if (!empty($item['blocked']) || !empty($item['skipped_columns'])) {
      throw new CRM_Core_Exception(E::ts('Conversion of %1 is blocked: %2', [
        1 => $item['table'],
        2 => implode(' ', $item['errors']),
      ]));
    }
  }

  /**
   * Refuse to convert a table while its log table, converted earlier in the
   * same run, still needs conversion.
   *
   * @param CRM_Collationfix_Analyzer $analyzer
   * @param string $logTable
   */
  protected static function assertLogTableConverted(CRM_Collationfix_Analyzer $analyzer, $logTable) {
    $tables = $analyzer->getTables();
    // A log table that is gone (or logging was disabled) has nothing to wait for.
    if (isset($tables[$logTable]) && $analyzer->analyzeTable($logTable, $tables[$logTable])['needs_change']) {
      throw new CRM_Core_Exception(E::ts('Not converted, because its log table %1 did not convert in this run (see its log entry). The logging trigger would still reject 4-byte characters. Fix the log table, then convert this table again.', [1 => $logTable]));
    }
  }

  protected static function assertPlanMatches(array $item, array $plan) {
    if (empty($plan['schema_fingerprint'])
      || $item['schema_fingerprint'] !== $plan['schema_fingerprint']
      || !isset($plan['alter']) || $item['alter'] !== $plan['alter']) {
      throw new CRM_Core_Exception(E::ts('The schema or conversion plan for %1 changed. Return to the analysis page and review it again.', [1 => $item['table']]));
    }
  }

  /**
   * Insert a record into civicrm_collationfix_log, or update it when $id is
   * given.
   *
   * @param array $log
   * @param int|null $id
   *
   * @return int
   *   The record ID.
   */
  public static function writeLog(array $log, $id = NULL) {
    $params = [
      1 => [$log['table_name'], 'String'],
      2 => [(string) $log['statement'], 'String'],
      3 => [(string) $log['collation_before'], 'String'],
      4 => [(string) $log['collation_after'], 'String'],
      5 => [(int) $log['duration_ms'], 'Integer'],
      6 => [$log['status'], 'String'],
      7 => [(string) $log['error_message'], 'String'],
    ];
    if ($id) {
      $params[8] = [(int) $id, 'Integer'];
      CRM_Core_DAO::executeQuery("
        UPDATE civicrm_collationfix_log
        SET table_name = %1, statement = %2, collation_before = %3, collation_after = %4,
          duration_ms = %5, status = %6, error_message = %7
        WHERE id = %8
      ", $params);
      return (int) $id;
    }
    $contactId = CRM_Core_Session::getLoggedInContactID();
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
    return (int) CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
  }

}
