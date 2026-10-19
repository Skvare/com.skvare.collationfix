<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Streams the generated ALTER statements as a downloadable .sql file.
 */
class CRM_Collationfix_Page_Download extends CRM_Core_Page {

  public function run() {
    $analyzer = new CRM_Collationfix_Analyzer();
    // The analysis page posts the selected tables; without them, every table
    // that needs a change is exported.
    $tables = NULL;
    if (!empty($_POST['tables']) && is_array($_POST['tables'])) {
      $tables = array_map('strval', $_POST['tables']);
    }
    $analysis = $analyzer->analyze($tables);
    // Same order as the conversion queue: log tables first.
    $analysis = array_replace(array_flip($analyzer->orderForConversion(array_keys($analysis))), $analysis);

    $lines = [
      '-- Collation Fix (com.skvare.collationfix)',
      '-- Database: ' . $analyzer->getDatabaseName(),
      '-- Logging database: ' . ($analyzer->isLoggingEnabled()
        ? $analyzer->getLoggingDatabaseName()
        : 'none (detailed logging is disabled; log tables are not included)'),
      '-- Server: ' . $analyzer->getServerVersion(),
      '-- Target collation: ' . $analyzer->getTargetCollation(),
      '-- Tables: ' . ($tables === NULL ? 'all that need a change' : 'selected on the analysis page'),
      '-- Generated: ' . date('Y-m-d H:i:s'),
      '-- Every statement names its database, so it alters only these tables',
      '-- whichever database the client has selected.',
      '-- Review before running. Take a backup first.',
      '',
    ];

    foreach ($analysis as $table => $item) {
      if (!$item['needs_change']) {
        continue;
      }
      $lines[] = '-- ===== TABLE ' . $table . ' (' . $item['size_formatted'] . ') =====';
      if ($item['blocked']) {
        foreach ($item['errors'] as $error) {
          $lines[] = '-- BLOCKED: ' . str_replace(["\r", "\n"], ' ', $error);
        }
        $lines[] = '';
        continue;
      }
      foreach ($item['warnings'] as $warning) {
        $lines[] = '-- WARNING: ' . str_replace("\n", ' ', $warning);
      }
      $lines[] = $item['alter'];
      $lines[] = '';
    }

    $content = implode("\n", $lines) . "\n";
    $fileName = $analyzer->getDatabaseName() . '_conversion.sql';

    CRM_Utils_System::download($fileName, 'application/sql', $content);
  }

}
