<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Conversion audit log viewer.
 */
class CRM_Collationfix_Page_Log extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('Collation Fix - Conversion Log'));

    $rows = [];
    $dao = CRM_Core_DAO::executeQuery("
      SELECT l.*, c.display_name
      FROM civicrm_collationfix_log l
      LEFT JOIN civicrm_contact c ON c.id = l.created_id
      ORDER BY l.id DESC
      LIMIT 500
    ");
    while ($dao->fetch()) {
      $rows[] = [
        'id' => $dao->id,
        'table_name' => $dao->table_name,
        'statement' => $dao->statement,
        'collation_before' => $dao->collation_before,
        'collation_after' => $dao->collation_after,
        'duration_ms' => $dao->duration_ms,
        'status' => $dao->status,
        'error_message' => $dao->error_message,
        'display_name' => $dao->display_name,
        'created_date' => $dao->created_date,
      ];
    }

    $this->assign('rows', $rows);
    $this->assign('analysisUrl', CRM_Utils_System::url('civicrm/admin/collationfix', 'reset=1'));

    parent::run();
  }

}
