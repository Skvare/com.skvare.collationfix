{* Collation Fix - conversion confirmation *}
<div class="crm-block crm-form-block collationfix-convert">

  <div class="messages warning no-popup">
    <p><strong>{ts}You are about to run ALTER TABLE statements against the live database.{/ts}</strong></p>
    <ul>
      <li>{ts 1=$tableCount 2=$totalSize}%1 tables selected, approximately %2 of data and indexes will be rebuilt.{/ts}</li>
      <li>{ts}Each table is locked while its ALTER runs. Large tables can take several minutes.{/ts}</li>
      <li>{ts}Take a database backup before continuing. On replicated setups, expect replication lag.{/ts}</li>
      <li>{ts}Each statement, its duration, and its outcome will be recorded in the conversion log.{/ts}</li>
    </ul>
  </div>

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
          <td><code>{$table|escape}</code></td>
          <td>{$item.current_collation|escape}</td>
          <td>{$item.target_collation|escape}</td>
          <td>{$item.columns|@count}</td>
          <td>{$item.size_formatted|escape}</td>
        </tr>
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

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>

</div>
