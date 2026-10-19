{* Collation Fix - conversion confirmation *}
<div class="crm-block crm-form-block collationfix-convert">

  <div class="messages warning no-popup">
    <p><strong>{ts}You are about to run ALTER TABLE statements against the live database.{/ts}</strong></p>
    <ul>
      <li>{ts 1=$tableCount 2=$totalSize}%1 tables selected, approximately %2 of data and indexes will be rebuilt.{/ts}</li>
      <li>{ts 1=$targetCollation|escape}Tables convert to <code>%1</code> (binary columns to <code>utf8mb4_bin</code>), even if the target collation setting changes while the run is in progress.{/ts}</li>
      <li>{ts}Each table is locked while its ALTER runs. Large tables can take several minutes.{/ts}</li>
      <li>{ts}Take a database backup before continuing. On replicated setups, expect replication lag.{/ts}</li>
      <li>{ts}Each statement, its duration, and its outcome will be recorded in the conversion log.{/ts}</li>
      <li>{ts}If a selected table's schema changes after this review, its conversion is stopped and a new review is required.{/ts}</li>
      {if $hasLogTables}
        <li>{ts}Log tables are converted first. While a log table's ALTER runs, saves to the table it logs wait for it to finish, because the logging trigger writes to the log table.{/ts}</li>
        <li>{ts}If a log table fails to convert, the table it logs is left unchanged in this run, and recorded as an error.{/ts}</li>
      {/if}
      {if $warningCount}
        <li><strong>{ts 1=$warningCount}%1 selected tables have warnings, shown under each table below. Read them before converting.{/ts}</strong></li>
      {/if}
    </ul>
  </div>

  {if $indexLimitTables}
    <div class="messages error no-popup">
      <p>{ts}The ALTER for these tables will fail: an index will be over the InnoDB index key limit once its columns take 4 bytes per character. Shorten the index (for example with a prefix length) first, or leave these tables out.{/ts}</p>
      <ul>
        {foreach from=$indexLimitTables item=table}
          <li><code>{$table|escape}</code></li>
        {/foreach}
      </ul>
    </div>
  {/if}

  {if $unselectedLogTables}
    <div class="messages warning no-popup">
      <p>{ts}These selected tables have a log table that still needs conversion but is not selected. Once a table is on utf8mb4, 4-byte characters saved to it are rejected by its log table (or stored as "?" without strict SQL mode) until the log table is converted too.{/ts}</p>
      <ul>
        {foreach from=$unselectedLogTables key=table item=logTable}
          <li><code>{$table|escape}</code> &rarr; <code>{$logTable|escape}</code></li>
        {/foreach}
      </ul>
    </div>
  {/if}

  <table class="display">
    <thead>
      <tr>
        <th>{ts}Table{/ts}</th>
        <th>{ts}Current collation{/ts}</th>
        <th>{ts}Target collation{/ts}</th>
        <th>{ts}Columns{/ts}</th>
        <th>{ts}Size{/ts}</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$tables key=table item=item}
        <tr class="{cycle values='odd-row,even-row'}">
          <td><code>{$table|escape}</code>{if $item.is_log} <span class="crm-tag-item">{ts}Log{/ts}</span>{/if}</td>
          <td>{$item.current_collation|escape}</td>
          <td>{$item.target_collation|escape}</td>
          <td>{$item.columns|@count}</td>
          <td>{$item.size_formatted|escape}</td>
        </tr>
        {if $item.warnings}
          <tr>
            <td colspan="5">
              <div class="messages warning no-popup" style="margin:0;">
                <ul>
                  {foreach from=$item.warnings item=warning}
                    <li>{$warning|escape}</li>
                  {/foreach}
                </ul>
              </div>
            </td>
          </tr>
        {/if}
        {if $item.alter}
          <tr>
            <td colspan="5"><details><summary>{ts}Review SQL{/ts}</summary><pre style="white-space:pre-wrap;">{$item.alter|escape}</pre></details></td>
          </tr>
        {/if}
      {/foreach}
    </tbody>
  </table>

  <div class="crm-section">
    <div class="label">{$form.confirm_database.label}</div>
    <div class="content">
      {$form.confirm_database.html}
      <div class="description">{ts 1=$databaseName|escape}Type <code>%1</code> to enable execution.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  {if $confirmLoggingDatabase}
    <div class="crm-section">
      <div class="label">{$form.confirm_logging_database.label}</div>
      <div class="content">
        {$form.confirm_logging_database.html}
        <div class="description">{ts 1=$loggingDatabaseName|escape}The selection includes log tables in the separate logging database. Type <code>%1</code> to enable execution.{/ts}</div>
      </div>
      <div class="clear"></div>
    </div>
  {/if}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>
