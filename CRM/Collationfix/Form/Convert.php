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

  public function preProcess() {
    parent::preProcess();
    // Tables arrive via POST from the analysis page on first load; keep them
    // in the controller's qfKey-scoped state so simultaneous browser tabs
    // cannot replace each other's pending selections.
    if (!empty($_POST['tables']) && is_array($_POST['tables']) && empty($_POST['_qf_Convert_submit'])) {
      $tables = array_map('strval', $_POST['tables']);
      $this->set('tables', $tables);
    }
    $this->_tables = $this->get('tables') ?: [];

    // Only allow tables that actually exist, as a safety measure against
    // tampered input.
    if (!empty($this->_tables)) {
      $analyzer = new CRM_Collationfix_Analyzer();
      $this->_tables = array_values(array_intersect($this->_tables, array_keys($analyzer->getTables())));
    }

    if (empty($this->_tables)) {
      CRM_Core_Session::setStatus(E::ts('No tables selected. Select tables from the analysis page first.'), E::ts('Collation Fix'), 'alert');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/collationfix', 'reset=1'));
    }
  }

  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Confirm Conversion'));

    $analyzer = new CRM_Collationfix_Analyzer();
    $analysis = $analyzer->analyze($this->_tables);

    $totalBytes = 0;
    foreach ($analysis as $item) {
      $totalBytes += $item['size_bytes'];
    }

    $this->assign('tables', $analysis);
    $this->assign('tableCount', count($this->_tables));
    $this->assign('totalSize', CRM_Collationfix_Analyzer::formatBytes($totalBytes));
    $this->assign('databaseName', $analyzer->getDatabaseName());

    $this->add('text', 'confirm_database', E::ts('Type the database name to confirm'), ['class' => 'huge'], TRUE);

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
    $this->addFormRule(['CRM_Collationfix_Form_Convert', 'validateConfirmation']);
  }

  /**
   * @param array $values
   * @return array|bool
   */
  public static function validateConfirmation($values) {
    $errors = [];
    $analyzer = new CRM_Collationfix_Analyzer();
    if (trim($values['confirm_database'] ?? '') !== $analyzer->getDatabaseName()) {
      $errors['confirm_database'] = E::ts('The database name does not match. Conversion not started.');
    }
    return empty($errors) ? TRUE : $errors;
  }

  public function postProcess() {
    $tables = $this->_tables;
    $this->set('tables', NULL);

    $queue = CRM_Collationfix_Converter::buildQueue($tables);

    $runner = new CRM_Queue_Runner([
      'title' => E::ts('Converting %1 tables to utf8mb4', [1 => count($tables)]),
      'queue' => $queue,
      'errorMode' => CRM_Queue_Runner::ERROR_CONTINUE,
      'onEndUrl' => CRM_Utils_System::url('civicrm/admin/collationfix/log', 'reset=1'),
    ]);
    $runner->runAllViaWeb();
  }

}
