# Collation Fix — utf8mb4 Conversion Toolkit (com.skvare.collationfix)

Audits every `civicrm_*` InnoDB table in the CiviCRM database (and, while
detailed logging is enabled, its log tables) for legacy `utf8` / `utf8mb3`
collations, shows exactly what would change (per column), and converts
selected tables to `utf8mb4` through a queue with progress tracking and a
permanent audit log.

Tables still on 3-byte utf8 cannot store emoji or other 4-byte characters —
inserts fail with `Incorrect string value` errors. CiviCRM core ships a blunt
`System.utf8conversion` API; this extension is the careful, auditable
alternative:

* **Per-column fidelity** — preserves `_bin` collations (hash columns stay
  `utf8mb4_bin`), NULL/NOT NULL, defaults, `auto_increment`, and comments.
  Literal and expression defaults are told apart by how `SHOW CREATE TABLE`
  writes them, and literals and comments are escaped by the database
  connection, so quotes and backslashes survive unchanged.
  Backtick-quoted, ANSI-quoted and unquoted column names in `SHOW CREATE
  TABLE` are supported. If a required definition cannot be read or parsed,
  the table is marked **Blocked** and no ALTER is generated or executed.
* **Generated columns handled correctly** — redefined with their generation
  expression so they convert along with their base columns. (Skipping them
  leaves the table unable to accept 4-byte input.) The expression is read
  from `SHOW CREATE TABLE`, because MySQL 8's `information_schema` returns it
  with its quotes escaped a second time.
* **Dry run by default** — the analysis page never touches the database.
  Review the exact ALTER statement for every table before anything runs.
* **Pre-flight warnings** — large tables (lock duration), and indexes whose
  key would exceed the InnoDB limit once their columns take 4 bytes per
  character (the ALTER would fail). The limit accounts for row format,
  `innodb_large_prefix` and `innodb_page_size`.
  Composite keys include numeric, date, binary, ENUM and SET components;
  unknown or functional key parts produce a manual-verification warning.
* **Row format** — converted InnoDB tables move to `ROW_FORMAT=Dynamic`,
  except `Compressed` tables, which keep their compression.
* **Server context at a glance** — database name, MySQL/MariaDB flavor and
  version, and the server, database and connection variables that matter
  for a safe conversion (`character_set_server`, `collation_server`,
  `character_set_database`, `collation_database`, `character_set_connection`,
  `collation_connection`, `innodb_file_per_table`, `innodb_large_prefix`,
  `innodb_default_row_format`, `innodb_page_size`, `max_allowed_packet`)
  are shown right on the analysis page, each with a help icon explaining
  what it means for the conversion.
* **Detailed-logging tables** — while CiviCRM's detailed logging is enabled,
  the log tables are analyzed and converted too, in the same database or in
  the separate one named by `CIVICRM_LOGGING_DSN`. See
  [Detailed logging](#detailed-logging).
* **Scope report** — the analysis page lists CiviCRM tables this tool
  doesn't convert that still use 3-byte utf8: non-InnoDB `civicrm_*` tables,
  log tables while logging is disabled or on an engine other than InnoDB or
  ARCHIVE, and `log_civicrm_*` tables left in the CiviCRM database when
  logging uses a separate one.
* **Queue-based execution** — one table per task via `CRM_Queue` with a
  progress bar. A single large ALTER can still exceed a web request timeout;
  use the CLI for large tables and inspect any interrupted audit records.
  The target collation shown on the confirmation screen is pinned for the
  whole run, even if the setting changes while it is in progress.
  The confirmation screen saves the reviewed SQL and a schema fingerprint.
  Changes are checked before queue creation and again by each task; changed
  tables require a fresh review. Ordinary AUTO_INCREMENT counter movement
  does not invalidate the review. Existing queued tasks without a saved plan
  are refused and must be reviewed again.
* **Audit log** — every statement, its duration, before/after collation,
  outcome, and the operator are recorded in `civicrm_collationfix_log`.
  A `running` record is written before each ALTER and updated afterwards, so
  an interrupted request still leaves a trace. After each ALTER the table is
  re-analyzed; any column (or table default) still off the target collation,
  or a re-analysis that fails, marks the run as an error.
* **Command-line API** — `Collationfix.convert` converts one or more tables
  from `cv`, `drush` or `wp-cli`, so large tables are never cut off by a web
  request timeout. Same verification and audit log as the UI.
* **System status check** — warns on the CiviCRM status page while any
  table default or column remains on 3-byte utf8, counting log tables while
  detailed logging is enabled.

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
5. Select tables, then either click **Download selected as .sql** to run the
   statements manually, or **Review and convert selected tables**.
6. On the confirmation screen, type the database name to enable execution.
   The conversion runs as a queue with a progress bar and lands on the
   conversion log when done.

## Detailed logging

When detailed logging is enabled (**Administer → System Settings → Misc →
Logging**), triggers copy every change to a CiviCRM table into its log table,
`log_civicrm_*`. A table converted to utf8mb4 then passes 4-byte characters to
its trigger, and a log table still on utf8 rejects them: the whole save fails
with `Incorrect string value` (or, without strict SQL mode, the log stores
`?`). So a table is only fully converted once its log table is too.

* **Only while logging is enabled.** Log tables are listed and converted only
  while the `logging` setting is on. With logging off nothing writes to them;
  any still on utf8 are shown in the scope report instead, because they are
  reused if logging is turned on again.
* **Which tables.** The log tables CiviCRM itself knows about
  (`CRM_Logging_Schema`): `log_civicrm_*` plus those of tables added through
  `hook_civicrm_alterLogTables`. InnoDB and ARCHIVE log tables are converted;
  ARCHIVE tables keep their engine, and get a warning because their ALTER
  rewrites the whole table. To move them to InnoDB first, run
  `cv api System.updatelogtables forceEngineMigration=1`.
* **Separate logging database.** When `CIVICRM_LOGGING_DSN` names another
  database, its log tables are shown and passed as `database.table` (for
  example `civicrm_logs.log_civicrm_contact`) on the analysis page, in the
  downloaded SQL, in the API and in the audit log. Like CiviCRM core, the
  tool reaches that database through the CiviCRM connection, so the CiviCRM
  database user needs `ALTER` on it; without it the ALTER fails, changes
  nothing and is recorded as an error. The confirmation screen then also asks
  for the logging database name.
* **Convert both together.** Every table that needs conversion warns when
  its log table needs it too, and the confirmation screen lists selected
  tables whose log table is not selected. Log tables are always converted
  first, which is safe: a log table on utf8mb4 accepts everything a utf8
  table sends it.
* **Locking.** While a log table's ALTER runs, saves to the table it logs
  wait for it, because the trigger writes to the log table. Log tables are
  often the largest tables in the database; convert them from the command
  line, in a quiet period.

## Command Line

For large tables, convert from the command line instead of the browser. The
`Collationfix.convert` API (APIv3) runs the tables one after another in the
same process, with the same analysis, post-conversion verification and audit
log as the UI queue.

```sh
# Preview the ALTER statements without running them.
cv api Collationfix.convert tables=civicrm_contact,civicrm_activity dry_run=1

# Analyze every table (read-only), e.g. for monitoring.
cv api Collationfix.convert dry_run=1

# Convert. Pass --user so the audit log records who ran it.
cv --user=admin api Collationfix.convert tables=civicrm_contact,civicrm_activity

# Same API from drush (Drupal) or wp-cli (WordPress).
drush civicrm-api Collationfix.convert tables=civicrm_contact
wp civicrm api Collationfix.convert tables=civicrm_contact
```

* `tables` — one or more `civicrm_*` InnoDB tables, or log tables while
  detailed logging is enabled (`database.log_civicrm_*` for a separate
  logging database), as a comma-separated string or an array. Required
  unless `dry_run` is set. Every name is checked before anything runs; an
  unknown name stops the whole call. Log tables are converted first.
* `dry_run` — return each table's generated statement, columns and warnings
  without executing anything. Without `tables`, every table is analyzed.
  Results also include `blocked`, `errors`, and `schema_fingerprint`.
  Blocked tables have an empty statement and remain marked as needing work.
* All tables in one call convert to the target collation read at its start.
* If any table fails, the call returns an error (non-zero exit from `cv`)
  that still includes every table's result. The other tables are still
  converted, as in the UI queue.

**Take a database backup before converting.** Each ALTER rebuilds and locks
its table; large tables can take several minutes and will cause replication
lag on replicated setups.

## Scope / Known Issues

* Operates on the database `CIVICRM_DSN` connects to, and only on tables
  whose names start with `civicrm_`, plus the detailed-logging tables while
  logging is enabled (see [Detailed logging](#detailed-logging)). On a
  shared Drupal/WordPress database the CMS's own tables (`users`, `node`,
  `sessions`, etc.) are not listed or converted. Neither are extension
  tables that use another prefix (e.g. CiviRules' `civirule_*`). Convert
  those separately if needed.
* Only analyzes `civicrm_*` tables using the `InnoDB` engine, and log tables
  using `InnoDB` or `ARCHIVE`; tables on other engines (e.g. `MyISAM`/`Aria`)
  are not converted, even if they are still on a legacy utf8 collation. They
  are listed in the analysis page's scope report.
* Assumes the default SQL mode handling of backslashes. Like CiviCRM core,
  its `LIKE 'civicrm\_%'` table filters find nothing under
  `NO_BACKSLASH_ESCAPES`.
* Converts the utf8 family only (`utf8`, `utf8mb3`, and normalizes other
  `utf8mb4_*` variants to the configured target collation); latin1 and
  other charsets are left untouched.
* Schema conversion only — it does not repair mojibake or double-encoded
  data.
* Schema checks detect changes since review; they do not lock the schema
  between the final check and ALTER. Avoid concurrent schema migrations.
* Index-size checks are preflight warnings, not full DDL validation. Foreign
  key compatibility and unique-value collisions still need separate review.
* `NOT NULL` is not re-emitted on generated columns (MariaDB rejects the
  clause on generated columns); such columns are rare in CiviCRM schemas.

## Regression checks

Run `php tests/regression.php` from the extension directory. These standalone
tests simulate CiviCRM and database responses and never connect to a database.
They cover SQL parsing, blocked tables, schema drift, queue/form review state,
audit ordering, verification failures, API results, composite index sizes,
and detailed-logging tables (logging on/off, separate logging database,
ARCHIVE log tables, conversion order and the scope report).
They complement, rather than replace, conversion tests on disposable MySQL
and MariaDB databases.
