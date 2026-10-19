<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Confirmation screen. Receives the selected tables from the analysis page,
 * requires the operator to type the database name, then runs the conversion
 * queue via the web runner (one table per task, with progress bar).
 */
class CRM_Collationfix_Form_Convert extends CRM_Core_Form {

  /**
   * @var array
   */
  protected $_tables = [];

  /**
   * @var string
   */
  protected $_targetCollation;

  public function preProcess() {
    parent::preProcess();
    // Tables arrive via POST from the analysis page on first load; keep them
    // in the controller's qfKey-scoped state so simultaneous browser tabs
    // cannot replace each other's pending selections.
    if (!empty($_POST['tables']) && is_array($_POST['tables']) && empty($_POST['_qf_Convert_submit'])) {
      $tables = array_values(array_unique(array_map('strval', $_POST['tables'])));
      $this->set('tables', $tables);
      // A new selection is reviewed against the current setting.
      $this->set('targetCollation', NULL);
      $this->set('reviewedAnalysis', NULL);
    }
    $this->_tables = $this->get('tables') ?: [];

    // Pin the target collation shown on this screen, so the queued run uses
    // it even if the setting changes before the form is submitted.
    $analyzer = new CRM_Collationfix_Analyzer($this->get('targetCollation'));
    $this->_targetCollation = $analyzer->getTargetCollation();
    $this->set('targetCollation', $this->_targetCollation);

    // Only allow tables that actually exist, as a safety measure against
    // tampered input.
    if (!empty($this->_tables)) {
      if (array_diff($this->_tables, array_keys($analyzer->getTables()))) {
        throw new CRM_Core_Exception(E::ts('A selected table is missing or unsupported. Return to the analysis page and review the selection again.'));
      }
      // List the tables in the order the queue converts them.
      $this->_tables = $analyzer->orderForConversion($this->_tables);
    }

    if (empty($this->_tables)) {
      CRM_Core_Session::setStatus(E::ts('No tables selected. Select tables from the analysis page first.'), E::ts('Collation Fix'), 'alert');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/collationfix', 'reset=1'));
    }
  }

  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Confirm Conversion'));

    $analyzer = new CRM_Collationfix_Analyzer($this->_targetCollation);
    $analysis = $this->get('reviewedAnalysis');
    if ($analysis === NULL) {
      if (!empty($_POST['_qf_Convert_submit'])) {
        throw new CRM_Core_Exception(E::ts('The saved review is missing. Return to the analysis page and review the selection again.'));
      }
      $analysis = $analyzer->analyze($this->_tables);
      $this->set('reviewedAnalysis', $analysis);
    }

    $totalBytes = 0;
    $hasLogTables = FALSE;
    $warningCount = 0;
    $indexLimitTables = [];
    foreach ($analysis as $table => $item) {
      if ($item['blocked']) {
        throw new CRM_Core_Exception(E::ts('Conversion of %1 is blocked: %2', [1 => $item['table'], 2 => implode(' ', $item['errors'])]));
      }
      $totalBytes += $item['size_bytes'];
      $hasLogTables = $hasLogTables || $item['is_log'];
      $warningCount += (int) !empty($item['warnings']);
      if ($item['exceeds_index_limit']) {
        $indexLimitTables[] = $table;
      }
    }
    $this->assign('warningCount', $warningCount);
    $this->assign('indexLimitTables', $indexLimitTables);

    $this->assign('tables', $analysis);
    $this->assign('tableCount', count($this->_tables));
    $this->assign('totalSize', CRM_Collationfix_Analyzer::formatBytes($totalBytes));
    $this->assign('databaseName', $analyzer->getDatabaseName());
    $this->assign('loggingDatabaseName', $analyzer->getLoggingDatabaseName());
    $this->assign('targetCollation', $this->_targetCollation);
    $this->assign('hasLogTables', $hasLogTables);
    $this->assign('unselectedLogTables', $analyzer->findUnselectedLogTables($analysis));
    $confirmLogging = $this->requiresLoggingConfirmation();
    $this->assign('confirmLoggingDatabase', $confirmLogging);

    $this->add('text', 'confirm_database', E::ts('Type the database name to confirm'), ['class' => 'huge'], TRUE);
    if ($confirmLogging) {
      $this->add('text', 'confirm_logging_database', E::ts('Type the logging database name to confirm'), ['class' => 'huge'], TRUE);
    }

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Convert %1 tables now', [1 => count($this->_tables)]),
        'isDefault' => FALSE,
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ],
    ]);
  }

  public function addRules() {
    $this->addFormRule(['CRM_Collationfix_Form_Convert', 'validateConfirmation'], $this);
  }

  /**
   * Whether the selection alters tables in a separate logging database,
   * whose name must then be confirmed too.
   *
   * @return bool
   */
  public function requiresLoggingConfirmation() {
    $analyzer = new CRM_Collationfix_Analyzer($this->_targetCollation);
    if (!$analyzer->hasSeparateLoggingDatabase()) {
      return FALSE;
    }
    foreach ($this->_tables as $table) {
      if ($analyzer->isLogTable($table)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array $values
   * @param array $files
   * @param CRM_Collationfix_Form_Convert|null $form
   * @return array|bool
   */
  public static function validateConfirmation($values, $files = [], $form = NULL) {
    $errors = [];
    $analyzer = new CRM_Collationfix_Analyzer();
    if (trim($values['confirm_database'] ?? '') !== $analyzer->getDatabaseName()) {
      $errors['confirm_database'] = E::ts('The database name does not match. Conversion not started.');
    }
    if ($form && $form->requiresLoggingConfirmation()
      && trim($values['confirm_logging_database'] ?? '') !== $analyzer->getLoggingDatabaseName()) {
      $errors['confirm_logging_database'] = E::ts('The logging database name does not match. Conversion not started.');
    }
    return empty($errors) ? TRUE : $errors;
  }

  public function postProcess() {
    $tables = $this->_tables;
    $reviewed = $this->get('reviewedAnalysis');
    if (!is_array($reviewed)) {
      throw new CRM_Core_Exception(E::ts('The saved review is missing. Review the selection again.'));
    }
    $queue = CRM_Collationfix_Converter::buildQueue($tables, $this->_targetCollation, $reviewed);
    $this->set('tables', NULL);
    $this->set('targetCollation', NULL);
    $this->set('reviewedAnalysis', NULL);

    $runner = new CRM_Queue_Runner([
      'title' => E::ts('Converting %1 tables to utf8mb4', [1 => count($tables)]),
      'queue' => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_CONTINUE,
      'onEndUrl' => CRM_Utils_System::url('civicrm/admin/collationfix/log', 'reset=1'),
    ]);
    $runner->runAllViaWeb();
  }

}
