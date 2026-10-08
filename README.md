# Collation Fix — utf8mb4 Conversion Toolkit (com.skvare.collationfix)

Audits every `civicrm_*` InnoDB table in the CiviCRM database for legacy
`utf8` / `utf8mb3` collations, shows exactly what would change (per column), and
converts selected tables to `utf8mb4` through a queue with progress tracking
and a permanent audit log.

Tables still on 3-byte utf8 cannot store emoji or other 4-byte characters —
inserts fail with `Incorrect string value` errors. CiviCRM core ships a blunt
`System.utf8conversion` API; this extension is the careful, auditable
alternative:

* **Per-column fidelity** — preserves `_bin` collations (hash columns stay
  `utf8mb4_bin`), NULL/NOT NULL, defaults (including `CURRENT_TIMESTAMP`,
  which is never quoted), `auto_increment`, and comments.
* **Generated columns handled correctly** — redefined with their
  `GENERATION_EXPRESSION` so they convert along with their base columns.
  (Skipping them leaves the table unable to accept 4-byte input.)
* **Dry run by default** — the analysis page never touches the database.
  Review the exact ALTER statement for every table before anything runs.
* **Pre-flight warnings** — large tables (lock duration), indexed
  `varchar(191+)` columns (index prefix limits).
* **Server context at a glance** — database name, MySQL/MariaDB flavor and
  version, and the server variables that matter for a safe conversion
  (`character_set_server`, `collation_server`, `innodb_file_per_table`,
  `innodb_large_prefix`, `innodb_default_row_format`, `max_allowed_packet`)
  are shown right on the analysis page.
* **Queue-based execution** — one table per task via `CRM_Queue` with a
  progress bar; a timeout on one giant table cannot leave the run half-done.
* **Audit log** — every statement, its duration, before/after collation,
  outcome, and the operator are recorded in `civicrm_collationfix_log`.
  After each ALTER the table is re-analyzed, and any column (or table
  default) still off the target collation marks the run as an error.
* **Command-line API** — `Collationfix.convert` converts one or more tables
  from `cv`, `drush` or `wp-cli`, so large tables are never cut off by a web
  request timeout. Same verification and audit log as the UI.
* **System status check** — warns on the CiviCRM status page while any
  tables remain on 3-byte utf8.

Works with MySQL 5.7 / 8.x (`utf8_*` and `utf8mb3_*` collation names) and
MariaDB.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting Started

1. Install and enable the extension.
2. Go to **Administer → System Settings → Collation Fix (utf8mb4)** or visit
   `civicrm/admin/collationfix`.
3. Optionally pick the **target collation** (default `utf8mb4_unicode_ci`)
   at **Administer → System Settings → Collation Fix Settings**
   (`civicrm/admin/setting/collationfix`) or via the *change* link on the
   analysis page. The list shows the non-binary
   `utf8mb4` collations your server supports; `_bin` columns always become
   `utf8mb4_bin`.
4. Review the analysis: expand any table to see the per-column diff and the
   exact ALTER statement. Warnings are shown for large tables and risky
   indexes.
5. Either download the statements as a `.sql` file to run manually, or select
   tables and click **Review and convert selected tables**.
6. On the confirmation screen, type the database name to enable execution.
   The conversion runs as a queue with a progress bar and lands on the
   conversion log when done.

## Command Line

For large tables, convert from the command line instead of the browser. The
`Collationfix.convert` API (APIv3) runs the tables one after another in the
same process, with the same analysis, post-conversion verification and audit
log as the UI queue.

```sh
# Preview the ALTER statements without running them.
cv api Collationfix.convert tables=civicrm_contact,civicrm_activity dry_run=1

# Convert. Pass --user so the audit log records who ran it.
cv --user=admin api Collationfix.convert tables=civicrm_contact,civicrm_activity

# Same API from drush (Drupal) or wp-cli (WordPress).
drush civicrm-api Collationfix.convert tables=civicrm_contact
wp civicrm api Collationfix.convert tables=civicrm_contact
```

* `tables` (required) — one or more `civicrm_*` InnoDB tables, as a
  comma-separated string or an array. Every name is checked before anything
  runs; an unknown name stops the whole call.
* `dry_run` — return each table's generated statement, columns and warnings
  without executing anything.
* If any table fails, the call returns an error (non-zero exit from `cv`)
  that still includes every table's result. The other tables are still
  converted, as in the UI queue.

**Take a database backup before converting.** Each ALTER rebuilds and locks
its table; large tables can take several minutes and will cause replication
lag on replicated setups.

## Scope / Known Issues

* Operates on the database `CIVICRM_DSN` connects to, and only on tables
  whose names start with `civicrm_`. On a shared Drupal/WordPress database
  the CMS's own tables (`users`, `node`, `sessions`, etc.) are not listed or
  converted. Neither are CiviCRM detailed-logging tables (`log_civicrm_*`)
  or extension tables that use another prefix (e.g. CiviRules'
  `civirule_*`). Convert those separately if needed.
* Only analyzes tables using the `InnoDB` engine; tables on other engines
  (e.g. `MyISAM`/`Aria`) are not listed here and are not converted, even if
  they are still on a legacy utf8 collation.
* Converts the utf8 family only (`utf8`, `utf8mb3`, and normalizes other
  `utf8mb4_*` variants to the configured target collation); latin1 and
  other charsets are left untouched.
* Schema conversion only — it does not repair mojibake or double-encoded
  data.
* `NOT NULL` is not re-emitted on generated columns (MariaDB rejects the
  clause on generated columns); such columns are rare in CiviCRM schemas.
