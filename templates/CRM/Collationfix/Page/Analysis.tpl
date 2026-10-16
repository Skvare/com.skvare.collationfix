{* Collation Fix - analysis page *}
<div class="crm-block crm-content-block collationfix-analysis">

  <div class="messages status no-popup">
    <p>
      {ts}Database{/ts}: <strong>{$databaseName|escape}</strong> &nbsp;|&nbsp;
      {ts}Server{/ts}: <strong>{$serverVersion|escape}</strong>{if $isMariaDb} ({ts}MariaDB{/ts}){else} ({ts}MySQL{/ts}){/if} &nbsp;|&nbsp;
      {ts}Target collation{/ts}: <strong>{$targetCollation|escape}</strong> (<a href="{$settingsUrl}">{ts}change{/ts}</a>)
    </p>
    <p>
      {ts}Detailed logging{/ts}:
      {if $loggingEnabled}
        <strong>{ts}enabled{/ts}</strong> &nbsp;|&nbsp;
        {ts}Logging database{/ts}: <strong>{$loggingDatabaseName|escape}</strong>{if $separateLoggingDatabase} ({ts}separate database, from CIVICRM_LOGGING_DSN{/ts}){/if}
      {else}
        <strong>{ts}disabled{/ts}</strong> &nbsp;|&nbsp; {ts}Log tables are not listed or converted while logging is disabled.{/ts}
      {/if}
    </p>
    <div class="collationfix-overview" style="display:flex; flex-wrap:wrap; align-items:flex-start; gap:0 1.5em;">
      {if $serverDetails}
        <table class="report-layout collationfix-server-details" style="width:auto; margin:0.5em 0;">
          <thead>
            <tr>
              <th>{ts}Server variable{/ts}</th><th>{ts}Value{/ts}</th>
              <th>{ts}Server variable{/ts}</th><th>{ts}Value{/ts}</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$serverDetails key=name item=value name=sd}
              {if $smarty.foreach.sd.iteration % 2 == 1}<tr>{/if}
                <td><code>{$name|escape}</code> {help id=$name file="CRM/Collationfix/Page/Analysis" title=$name database=$databaseName collation=$targetCollation}</td>
                <td>{$value|escape}</td>
              {if $smarty.foreach.sd.iteration % 2 == 0}</tr>{elseif $smarty.foreach.sd.last}<td></td><td></td></tr>{/if}
            {/foreach}
          </tbody>
        </table>
      {/if}

      {* Legend for the Status column. Keep the badges identical to the ones in the table below. *}
      <table class="report-layout collationfix-legend" style="width:auto; max-width:32em; margin:0.5em 0;">
        <thead>
          <tr><th>{ts}Status{/ts}</th><th>{ts}What it means{/ts}</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="crm-tag-item" style="background:#fdd;">{ts}Blocked{/ts}</span></td>
            <td>{ts}Cannot be converted safely, so no SQL is generated and it cannot be selected. Open Details for the reason.{/ts}</td>
          </tr>
          <tr>
            <td><span class="crm-tag-item" style="background:#fdd;">{ts}Warning{/ts}</span></td>
            <td>{ts}Will be converted when selected, but read the warnings under Details first, such as an index that becomes too long, a large table, or a log table to convert with it.{/ts}</td>
          </tr>
          <tr>
            <td><span class="crm-tag-item" style="background:#ffd;">{ts}Needs change{/ts}</span></td>
            <td>{ts}Will be converted when selected. Nothing to flag.{/ts}</td>
          </tr>
          <tr>
            <td><span class="crm-tag-item" style="background:#dfe;">{ts}OK{/ts}</span></td>
            <td>{ts}Already on the target collation; nothing to do. Hidden unless you untick "Hide tables that are already utf8mb4".{/ts}</td>
          </tr>
          {if $loggingEnabled}
            <tr>
              <td><span class="crm-tag-item">{ts}Log{/ts}</span></td>
              <td>{ts}A detailed-logging table. It is converted before the table it logs.{/ts}</td>
            </tr>
          {/if}
        </tbody>
      </table>
    </div>
    <p>{ts}Dry run by default: nothing on this page modifies the database. Statements are only executed from the conversion screen after explicit confirmation.{/ts}</p>
  </div>

  <table class="report-layout collationfix-summary" style="width:auto; margin-bottom:1em;">
    <tr>
      <td><strong>{ts}Tables scanned{/ts}</strong><br/><span style="font-size:1.6em;">{$totalTables}</span></td>
      <td><strong>{ts}Need conversion{/ts}</strong><br/><span style="font-size:1.6em; color:#a00;">{$totalNeedsChange}</span></td>
      <td><strong>{ts}Columns affected{/ts}</strong><br/><span style="font-size:1.6em;">{$totalColumns}</span></td>
      <td><strong>{ts}Data size affected{/ts}</strong><br/><span style="font-size:1.6em;">{$totalSize}</span></td>
    </tr>
  </table>

  {if $loggingErrors}
    <div class="messages error no-popup">
      {foreach from=$loggingErrors item=error}
        <p>{$error|escape}</p>
      {/foreach}
    </div>
  {/if}

  {if $logTablesNeedingChange}
    <div class="messages warning no-popup">
      <p>{ts 1=$logTablesNeedingChange}%1 detailed-logging tables need conversion. Convert each one together with the table it logs: logging triggers copy every saved value into the log table, so 4-byte characters are rejected there while it is still on utf8. Log tables are converted first. While a log table's ALTER runs, saves to the table it logs wait for it to finish; log tables are often the largest tables, so consider converting them from the command line.{/ts}</p>
    </div>
  {/if}

  {if $uncoveredTables}
    <div class="messages warning no-popup">
      <p>{ts 1=$uncoveredTables|@count}%1 CiviCRM tables outside this tool's scope still use 3-byte utf8. They are not listed below and are not converted; convert them separately.{/ts}</p>
      <details>
        <summary>{ts}Show tables{/ts}</summary>
        <ul>
          {foreach from=$uncoveredTables key=table item=info}
            <li><code>{$table|escape}</code> ({$info.engine|escape}, {$info.collation|escape}): {$info.reason|escape}</li>
          {/foreach}
        </ul>
      </details>
    </div>
  {/if}

  <div style="margin-bottom:0.5em;">
    <label>
      <input type="checkbox" id="collationfix-filter-ok" checked/>
      {ts}Hide tables that are already utf8mb4{/ts}
    </label>
  </div>

  <form method="post" action="{$convertUrl}" id="collationfix-form">
    <input type="hidden" name="qfKey" value="{$convertQfKey}"/>
    <table class="display" id="collationfix-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="collationfix-select-all" title="{ts}Select all{/ts}"/></th>
          <th>{ts}Table{/ts}</th>
          <th>{ts}Current collation{/ts}</th>
          <th>{ts}Target collation{/ts}</th>
          <th>{ts}Columns{/ts}</th>
          <th>{ts}Size{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$analysis key=table item=item}
          <tr class="collationfix-row {if !$item.needs_change}collationfix-ok{/if} {cycle values='odd-row,even-row'}">
            <td>
              {if $item.needs_change and !$item.blocked}
                <input type="checkbox" name="tables[]" value="{$table|escape}" class="collationfix-check" checked/>
              {/if}
            </td>
            <td><code>{$table|escape}</code>{if $item.is_log} <span class="crm-tag-item">{ts}Log{/ts}</span>{/if}</td>
            <td>{$item.current_collation|escape}</td>
            <td>{if $item.needs_change}{$item.target_collation|escape}{else}-{/if}</td>
            <td>{$item.columns|@count}</td>
            <td>{$item.size_formatted|escape}</td>
            <td>
              {if $item.blocked}
                <span class="crm-tag-item" style="background:#fdd;">{ts}Blocked{/ts}</span>
              {elseif !$item.needs_change}
                <span class="crm-tag-item" style="background:#dfe;">{ts}OK{/ts}</span>
              {elseif $item.warnings}
                <span class="crm-tag-item" style="background:#fdd;">{ts}Warning{/ts}</span>
              {else}
                <span class="crm-tag-item" style="background:#ffd;">{ts}Needs change{/ts}</span>
              {/if}
            </td>
            <td>
              {if $item.needs_change}
                <a href="#" class="collationfix-toggle" data-target="collationfix-detail-{$table|escape}">{ts}Details{/ts}</a>
              {/if}
            </td>
          </tr>
          {if $item.needs_change}
            <tr id="collationfix-detail-{$table|escape}" class="collationfix-detail" style="display:none;">
              <td></td>
              <td colspan="7">
                {if $item.errors}
                  <div class="messages error no-popup">
                    {foreach from=$item.errors item=error}
                      <p>{$error|escape}</p>
                    {/foreach}
                  </div>
                {/if}
                {if $item.warnings}
                  <div class="messages warning no-popup">
                    {foreach from=$item.warnings item=warning}
                      <p>{$warning|escape}</p>
                    {/foreach}
                  </div>
                {/if}
                {if $item.skipped_columns}
                  <div class="messages status no-popup">
                    <p>{ts}Skipped columns{/ts}:
                    {foreach from=$item.skipped_columns item=skip name=skiploop}
                      <code>{$skip.field|escape}</code> ({$skip.reason|escape}){if !$smarty.foreach.skiploop.last}, {/if}
                    {/foreach}
                    </p>
                  </div>
                {/if}
                <table class="form-layout-compressed">
                  <thead>
                    <tr><th>{ts}Column{/ts}</th><th>{ts}Type{/ts}</th><th>{ts}Current{/ts}</th><th>{ts}Target{/ts}</th></tr>
                  </thead>
                  <tbody>
                    {foreach from=$item.columns item=col}
                      <tr>
                        <td><code>{$col.field|escape}</code></td>
                        <td>{$col.type|escape}</td>
                        <td>{$col.current_collation|escape}</td>
                        <td>{$col.target_collation|escape}</td>
                      </tr>
                    {/foreach}
                  </tbody>
                </table>
                <strong>{ts}Generated statement{/ts}:</strong>
                <pre class="collationfix-sql" style="white-space:pre-wrap; background:#f6f6f6; padding:8px; border:1px solid #ddd;">{$item.alter|escape}</pre>
              </td>
            </tr>
          {/if}
        {/foreach}
      </tbody>
    </table>

    <div class="crm-submit-buttons">
      <button type="submit" class="crm-button" {if !$totalNeedsChange}disabled{/if}>
        <i class="crm-i fa-play" aria-hidden="true"></i> {ts}Review and convert selected tables{/ts}
      </button>
      <button type="submit" class="crm-button" id="collationfix-download" formaction="{$downloadUrl}" {if !$totalNeedsChange}disabled{/if}>
        <i class="crm-i fa-download" aria-hidden="true"></i> {ts}Download selected as .sql{/ts}
      </button>
      <a class="button" href="{$logUrl}"><span><i class="crm-i fa-list" aria-hidden="true"></i> {ts}View conversion log{/ts}</span></a>
    </div>
  </form>

</div>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    function applyFilter() {
      var hide = $('#collationfix-filter-ok').is(':checked');
      $('.collationfix-row.collationfix-ok').toggle(!hide);
    }
    $('#collationfix-filter-ok').on('change', applyFilter);
    applyFilter();

    $('#collationfix-select-all').on('change', function() {
      $('.collationfix-check').prop('checked', $(this).is(':checked'));
    }).prop('checked', true);

    $('.collationfix-toggle').on('click', function(e) {
      e.preventDefault();
      $('#' + $(this).data('target').replace(/([^A-Za-z0-9_-])/g, '\\$1')).toggle();
    });

    $('#collationfix-download').on('click', function() {
      if (!$('.collationfix-check:checked').length) {
        CRM.alert(ts('Select at least one table to download.'), '', 'error');
        return false;
      }
    });

    $('#collationfix-form').on('submit', function() {
      if (!$('.collationfix-check:checked').length) {
        CRM.alert(ts('Select at least one table to convert.'), '', 'error');
        return false;
      }
    });
  });
</script>
{/literal}
