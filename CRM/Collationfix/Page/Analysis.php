<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Analysis page: lists all tables with their collation status, per-column
 * diffs, generated ALTER statements, and warnings.
 */
class CRM_Collationfix_Page_Analysis extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('Collation Fix - utf8mb4 Conversion'));

    $analyzer = new CRM_Collationfix_Analyzer();
    $analysis = $analyzer->analyze();

    $needsChange = array_filter($analysis, function($item) {
      return $item['needs_change'];
    });
    $totalColumns = 0;
    $totalBytes = 0;
    foreach ($needsChange as $item) {
      $totalColumns += count($item['columns']);
      $totalBytes += $item['size_bytes'];
    }

    $this->assign('analysis', $analysis);
    $this->assign('databaseName', $analyzer->getDatabaseName());
    $this->assign('serverVersion', $analyzer->getServerVersion());
    $this->assign('isMariaDb', $analyzer->isMariaDb());
    $this->assign('serverDetails', $analyzer->getServerDetails());
    $this->assign('targetCollation', $analyzer->getTargetCollation());
    $this->assign('settingsUrl', CRM_Utils_System::url('civicrm/admin/setting/collationfix', 'reset=1'));
    $this->assign('totalTables', count($analysis));
    $this->assign('totalNeedsChange', count($needsChange));
    $this->assign('totalColumns', $totalColumns);
    $this->assign('totalSize', CRM_Collationfix_Analyzer::formatBytes($totalBytes));
    $this->assign('convertUrl', CRM_Utils_System::url('civicrm/admin/collationfix/convert', 'reset=1'));
    // The analysis table posts directly to the Convert QuickForm page. A POST
    // request is always required to carry a valid qfKey (see
    // CRM_Core_Controller::key()), so generate one here for the form's
    // name/session rather than relying on an initial GET to mint it.
    $this->assign('convertQfKey', CRM_Core_Key::get('CRM_Collationfix_Form_Convert', TRUE));
    $this->assign('downloadUrl', CRM_Utils_System::url('civicrm/admin/collationfix/download', 'reset=1'));
    $this->assign('logUrl', CRM_Utils_System::url('civicrm/admin/collationfix/log', 'reset=1'));

    parent::run();
  }

}
