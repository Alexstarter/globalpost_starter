{if isset($globalpost_carriers) && $globalpost_carriers|@count > 0}
<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title mb-0">{$globalpost_carriers_labels.title|escape:'html':'UTF-8'}</h3>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>{$globalpost_carriers_labels.type|escape:'html':'UTF-8'}</th>
            <th>{$globalpost_carriers_labels.id|escape:'html':'UTF-8'}</th>
            <th>{$globalpost_carriers_labels.name|escape:'html':'UTF-8'}</th>
            <th>{$globalpost_carriers_labels.delay|escape:'html':'UTF-8'}</th>
            <th>{$globalpost_carriers_labels.default|escape:'html':'UTF-8'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$globalpost_carriers item=carrier}
            <tr{if !empty($carrier.missing)} class="table-warning"{/if}>
              <td>{$carrier.type_label|escape:'html':'UTF-8'}</td>
              <td>{if !empty($carrier.id_carrier)}{$carrier.id_carrier|escape:'html':'UTF-8'}{else}&mdash;{/if}</td>
              <td>
                {if !empty($carrier.missing)}
                  <span class="badge badge-warning">{$globalpost_carriers_labels.missing|escape:'html':'UTF-8'}</span>
                {elseif !empty($carrier.name)}
                  {$carrier.name|escape:'html':'UTF-8'}
                {else}
                  &mdash;
                {/if}
              </td>
              <td>{if !empty($carrier.delay)}{$carrier.delay|escape:'html':'UTF-8'}{else}&mdash;{/if}</td>
              <td>
                {assign var=isDefault value=$carrier.is_default|default:0}
                {if $isDefault}
                  <span class="badge badge-success">{$globalpost_carriers_labels.default_yes|escape:'html':'UTF-8'}</span>
                {else}
                  <span class="badge badge-secondary">{$globalpost_carriers_labels.default_no|escape:'html':'UTF-8'}</span>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  {if $globalpost_carriers_missing}
    <div class="card-footer">
      <div class="alert alert-warning mb-0">{$globalpost_carriers_labels.missing|escape:'html':'UTF-8'}</div>
    </div>
  {/if}
</div>
{elseif isset($globalpost_carriers_labels)}
  <div class="alert alert-info mt-3">
    {$globalpost_carriers_labels.empty|escape:'html':'UTF-8'}
  </div>
{/if}
