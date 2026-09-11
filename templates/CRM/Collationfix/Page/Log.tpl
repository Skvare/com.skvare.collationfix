{* Collation Fix - conversion log *}
<div class="crm-block crm-content-block collationfix-log">

  <div class="crm-submit-buttons">
    <a class="button" href="{$analysisUrl}"><span><i class="crm-i fa-chevron-left" aria-hidden="true"></i> {ts}Back to analysis{/ts}</span></a>
  </div>

  {if $rows}
    <table class="display">
      <thead>
        <tr>
          <th>{ts}Date{/ts}</th>
          <th>{ts}Table{/ts}</th>
          <th>{ts}Before{/ts}</th>
          <th>{ts}After{/ts}</th>
          <th>{ts}Duration{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Run by{/ts}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$rows item=row}
          <tr class="{cycle values='odd-row,even-row'}">
            <td>{$row.created_date|crmDate}</td>
            <td><code>{$row.table_name|escape}</code></td>
            <td>{$row.collation_before|escape}</td>
            <td>{$row.collation_after|escape}</td>
            <td>{$row.duration_ms|escape} ms</td>
            <td>
              {if $row.status eq 'success'}
                <span class="crm-tag-item" style="background:#dfe;">{ts}Success{/ts}</span>
              {elseif $row.status eq 'error'}
                <span class="crm-tag-item" style="background:#fdd;" title="{$row.error_message|escape}">{ts}Error{/ts}</span>
              {else}
                <span class="crm-tag-item" style="background:#eee;">{ts}Skipped{/ts}</span>
              {/if}
            </td>
            <td>{$row.display_name|escape}</td>
            <td>
              {if $row.statement}
                <a href="#" class="collationfix-log-toggle" data-target="collationfix-log-{$row.id|escape}">{ts}SQL{/ts}</a>
              {/if}
            </td>
          </tr>
          {if $row.statement or $row.error_message}
            <tr id="collationfix-log-{$row.id|escape}" style="display:none;">
              <td colspan="8">
                {if $row.error_message}
                  <div class="messages warning no-popup"><p>{$row.error_message|escape}</p></div>
                {/if}
                {if $row.statement}
                  <pre style="white-space:pre-wrap; background:#f6f6f6; padding:8px; border:1px solid #ddd;">{$row.statement|escape}</pre>
                {/if}
              </td>
            </tr>
          {/if}
        {/foreach}
      </tbody>
    </table>
  {else}
    <div class="messages status no-popup">
      <p>{ts}No conversions have been run yet.{/ts}</p>
    </div>
  {/if}

</div>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    $('.collationfix-log-toggle').on('click', function(e) {
      e.preventDefault();
      $('#' + $(this).data('target')).toggle();
    });
  });
</script>
{/literal}
