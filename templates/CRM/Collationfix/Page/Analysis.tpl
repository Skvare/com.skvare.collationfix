{* Collation Fix - analysis page *}
<div class="crm-block crm-content-block collationfix-analysis">

  <div class="messages status no-popup">
    <p>
      {ts}Database{/ts}: <strong>{$databaseName|escape}</strong> &nbsp;|&nbsp;
      {ts}Server{/ts}: <strong>{$serverVersion|escape}</strong>{if $isMariaDb} ({ts}MariaDB{/ts}){else} ({ts}MySQL{/ts}){/if} &nbsp;|&nbsp;
      {ts}Target collation{/ts}: <strong>{$targetCollation|escape}</strong> (<a href="{$settingsUrl}">{ts}change{/ts}</a>)
    </p>
    {if $serverDetails}
      <p class="description">
        {foreach from=$serverDetails key=name item=value name=sd}
          <code>{$name|escape}</code>: {$value|escape}{if !$smarty.foreach.sd.last} &nbsp;|&nbsp; {/if}
        {/foreach}
      </p>
    {/if}
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

  <div style="margin-bottom:0.5em;">
    <label>
      <input type="checkbox" id="collationfix-filter-ok" checked/>
      {ts}Hide tables that are already utf8mb4{/ts}
    </label>
  </div>

  <form method="post" action="{$convertUrl}" id="collationfix-form">
    <input type="hidden" name="qfKey" value="{$convertQfKey}"/>
    <table class="display crm-sortable" id="collationfix-table">
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
              {if $item.needs_change}
                <input type="checkbox" name="tables[]" value="{$table|escape}" class="collationfix-check" checked/>
              {/if}
            </td>
            <td><code>{$table|escape}</code></td>
            <td>{$item.current_collation|escape}</td>
            <td>{if $item.needs_change}{$item.target_collation|escape}{else}-{/if}</td>
            <td>{$item.columns|@count}</td>
            <td>{$item.size_formatted|escape}</td>
            <td>
              {if !$item.needs_change}
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
      <a class="button" href="{$downloadUrl}"><span><i class="crm-i fa-download" aria-hidden="true"></i> {ts}Download .sql{/ts}</span></a>
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

    $('#collationfix-form').on('submit', function() {
      if (!$('.collationfix-check:checked').length) {
        CRM.alert(ts('Select at least one table to convert.'), '', 'error');
        return false;
      }
    });
  });
</script>
{/literal}
