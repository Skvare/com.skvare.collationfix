<?php

require_once 'collationfix.civix.php';

use CRM_Collationfix_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function collationfix_civicrm_config(&$config): void {
  _collationfix_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function collationfix_civicrm_install(): void {
  _collationfix_civix_civicrm_install();
  $sqlFile = E::path('sql/install.sql');
  if (file_exists($sqlFile)) {
    CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $sqlFile);
  }
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function collationfix_civicrm_uninstall(): void {
  $sqlFile = E::path('sql/uninstall.sql');
  if (file_exists($sqlFile)) {
    CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $sqlFile);
  }
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function collationfix_civicrm_enable(): void {
  _collationfix_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds "Collation Fix" and its settings page under Administer > System Settings.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function collationfix_civicrm_navigationMenu(&$menu): void {
  _collationfix_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('Collation Fix (utf8mb4)'),
    'name' => 'collationfix',
    'url' => 'civicrm/admin/collationfix?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _collationfix_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('Collation Fix Settings'),
    'name' => 'collationfix_settings',
    'url' => 'civicrm/admin/setting/collationfix?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _collationfix_civix_navigationMenu($menu);
}

/**
 * Implements hook_civicrm_check().
 *
 * Adds a system status warning when tables are not yet on utf8mb4.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_check
 */
function collationfix_civicrm_check(&$messages, $statusNames, $includeDisabled): void {
  if ($statusNames && !in_array('collationfixNonUtf8mb4', $statusNames)) {
    return;
  }
  try {
    $count = CRM_Collationfix_Analyzer::countNonUtf8mb4Tables();
  }
  catch (Exception $e) {
    return;
  }
  if ($count > 0) {
    $url = CRM_Utils_System::url('civicrm/admin/collationfix', 'reset=1');
    $messages[] = new CRM_Utils_Check_Message(
      'collationfixNonUtf8mb4',
      E::ts('%1 database tables are not using the utf8mb4 character set. Emoji and other 4-byte characters cannot be stored in these tables. <a href="%2">Review and convert them with Collation Fix</a>.', [
        1 => $count,
        2 => $url,
      ]),
      E::ts('Tables not on utf8mb4'),
      \Psr\Log\LogLevel::WARNING,
      'fa-database'
    );
  }
}
