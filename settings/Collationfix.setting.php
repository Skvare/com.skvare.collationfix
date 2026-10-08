<?php

use CRM_Collationfix_ExtensionUtil as E;

return [
  'collationfix_target_collation' => [
    'name' => 'collationfix_target_collation',
    'type' => 'String',
    'html_type' => 'select',
    'html_attributes' => ['class' => 'crm-select2'],
    'default' => 'utf8mb4_unicode_ci',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Target collation'),
    'description' => E::ts('utf8mb4 collation that legacy utf8/utf8mb3 tables and columns are converted to. Columns on a binary (_bin) collation always become utf8mb4_bin. Tables already on a different utf8mb4 collation are also flagged for conversion to this one.'),
    'pseudoconstant' => ['callback' => 'CRM_Collationfix_Analyzer::getTargetCollationOptions'],
    'validate_callback' => 'CRM_Collationfix_Analyzer::validateTargetCollation',
    'settings_pages' => ['collationfix' => ['weight' => 10]],
  ],
];
