<?php

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Streams the generated ALTER statements as a downloadable .sql file.
 */
class CRM_Collationfix_Page_Download extends CRM_Core_Page {

  public function run() {
    $analyzer = new CRM_Collationfix_Analyzer();
    $analysis = $analyzer->analyze();

    $lines = [
      '-- Collation Fix (com.skvare.collationfix)',
      '-- Database: ' . $analyzer->getDatabaseName(),
      '-- Server: ' . $analyzer->getServerVersion(),
      '-- Generated: ' . date('Y-m-d H:i:s'),
      '-- Review before running. Take a backup first.',
      '',
    ];

    foreach ($analysis as $table => $item) {
      if (!$item['needs_change']) {
        continue;
      }
      $lines[] = '-- ===== TABLE ' . $table . ' (' . $item['size_formatted'] . ') =====';
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
