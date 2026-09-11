# Collation Fix — utf8mb4 Conversion Toolkit (com.skvare.collationfix)

Audits every InnoDB table in the CiviCRM database for legacy `utf8` /
`utf8mb3` collations, shows exactly what would change (per column), and
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
* **Queue-based execution** — one table per task via `CRM_Queue` with a
  progress bar; a timeout on one giant table cannot leave the run half-done.
* **Audit log** — every statement, its duration, before/after collation,
  outcome, and the operator are recorded in `civicrm_collationfix_log`.
* **System status check** — warns on the CiviCRM status page while any
  tables remain on 3-byte utf8.

Works with MySQL 5.7 / 8.x (`utf8_*` and `utf8mb3_*` collation names) and
MariaDB.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting Started

1. Install and enable the extension.
2. Go to **Administer → System Settings → Collation Fix (utf8mb4)** or visit
   `civicrm/admin/collationfix`.
3. Review the analysis: expand any table to see the per-column diff and the
   exact ALTER statement. Warnings are shown for large tables and risky
   indexes.
4. Either download the statements as a `.sql` file to run manually, or select
   tables and click **Review and convert selected tables**.
5. On the confirmation screen, type the database name to enable execution.
   The conversion runs as a queue with a progress bar and lands on the
   conversion log when done.

**Take a database backup before converting.** Each ALTER rebuilds and locks
its table; large tables can take several minutes and will cause replication
lag on replicated setups.

## Scope / Known Issues

* Converts the CiviCRM database only (not the Drupal/WordPress database).
* Converts the utf8 family only (`utf8`, `utf8mb3`, and normalizes other
  `utf8mb4_*` variants to `utf8mb4_unicode_ci`); latin1 and other charsets
  are left untouched.
* Schema conversion only — it does not repair mojibake or double-encoded
  data.
* `NOT NULL` is not re-emitted on generated columns (MariaDB rejects the
  clause on generated columns); such columns are rare in CiviCRM schemas.
