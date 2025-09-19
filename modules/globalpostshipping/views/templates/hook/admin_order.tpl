{if isset($globalpost_admin) && $globalpost_admin}
<div class="card mt-3" id="globalpost-admin-block">
  <div class="card-header">
    <h3 class="card-title mb-0">
      {$globalpost_admin.title|escape:'html':'UTF-8'}
      {if !empty($globalpost_admin.type_label)}
        <span class="badge badge-info ml-2">{$globalpost_admin.type_label|escape:'html':'UTF-8'}</span>
      {/if}
    </h3>
  </div>
  <div class="card-body">
    {if isset($globalpost_admin.flash)}
      <div class="alert {if $globalpost_admin.flash.type == 'error'}alert-danger{else}alert-success{/if}" role="alert">
        {$globalpost_admin.flash.message|escape:'html':'UTF-8'}
      </div>
    {/if}
    <div class="row">
      <div class="col-md-6">
        <dl class="row mb-0">
          <dt class="col-sm-5">{$globalpost_admin.labels.status|escape:'html':'UTF-8'}</dt>
          <dd class="col-sm-7">
            <span class="badge badge-{$globalpost_admin.status.badge|escape:'html':'UTF-8'}">
              {$globalpost_admin.status.label|escape:'html':'UTF-8'}
            </span>
            {if $globalpost_admin.status.message}
              <div class="text-muted small mt-2">{$globalpost_admin.status.message|escape:'html':'UTF-8'}</div>
            {/if}
          </dd>
          <dt class="col-sm-5 mt-3">{$globalpost_admin.labels.tariff|escape:'html':'UTF-8'}</dt>
          <dd class="col-sm-7 mt-3">
            {if $globalpost_admin.tariff.key}
              <div>{$globalpost_admin.labels.tariff_key|escape:'html':'UTF-8'}: {$globalpost_admin.tariff.key|escape:'html':'UTF-8'}</div>
            {/if}
            {if $globalpost_admin.tariff.id}
              <div>{$globalpost_admin.labels.tariff_id|escape:'html':'UTF-8'}: {$globalpost_admin.tariff.id|escape:'html':'UTF-8'}</div>
            {/if}
            {if $globalpost_admin.tariff.price_text}
              <div>{$globalpost_admin.labels.price|escape:'html':'UTF-8'}: {$globalpost_admin.tariff.price_text|escape:'html':'UTF-8'}</div>
            {/if}
            {if $globalpost_admin.tariff.estimate_text}
              <div>{$globalpost_admin.labels.estimate|escape:'html':'UTF-8'}: {$globalpost_admin.tariff.estimate_text|escape:'html':'UTF-8'}</div>
            {/if}
          </dd>
        </dl>
      </div>
      <div class="col-md-6">
        <dl class="row mb-0">
          <dt class="col-sm-5">{$globalpost_admin.labels.shipment_id|escape:'html':'UTF-8'}</dt>
          <dd class="col-sm-7">{if $globalpost_admin.shipment_id}{$globalpost_admin.shipment_id|escape:'html':'UTF-8'}{else}&mdash;{/if}</dd>
          <dt class="col-sm-5">{$globalpost_admin.labels.ttn|escape:'html':'UTF-8'}</dt>
          <dd class="col-sm-7">{if $globalpost_admin.ttn}{$globalpost_admin.ttn|escape:'html':'UTF-8'}{else}&mdash;{/if}</dd>
          <dt class="col-sm-5">{$globalpost_admin.labels.tracking|escape:'html':'UTF-8'}</dt>
          <dd class="col-sm-7">
            {if $globalpost_admin.tracking_url}
              <a href="{$globalpost_admin.tracking_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
                {$globalpost_admin.tracking_url|escape:'html':'UTF-8'}
              </a>
            {else}
              &mdash;
            {/if}
          </dd>
          {if $globalpost_admin.status.message}
            <dt class="col-sm-5">{$globalpost_admin.labels.last_message|escape:'html':'UTF-8'}</dt>
            <dd class="col-sm-7">{$globalpost_admin.status.message|escape:'html':'UTF-8'}</dd>
          {/if}
        </dl>
      </div>
    </div>
    <div class="d-flex flex-wrap mt-3">
      {if isset($globalpost_admin.actions.create)}
        <form method="post" action="{$globalpost_admin.actions.create.url|escape:'html':'UTF-8'}" class="mr-2 mb-2">
          <button type="submit" class="btn btn-primary">{$globalpost_admin.actions.create.label|escape:'html':'UTF-8'}</button>
        </form>
      {/if}
      {if isset($globalpost_admin.actions.label)}
        <a class="btn btn-outline-secondary mr-2 mb-2" href="{$globalpost_admin.actions.label.url|escape:'html':'UTF-8'}">
          {$globalpost_admin.actions.label.label|escape:'html':'UTF-8'}
        </a>
      {/if}
      {if isset($globalpost_admin.actions.invoice)}
        <a class="btn btn-outline-secondary mb-2" href="{$globalpost_admin.actions.invoice.url|escape:'html':'UTF-8'}">
          {$globalpost_admin.actions.invoice.label|escape:'html':'UTF-8'}
        </a>
      {/if}
    </div>
  </div>
</div>
{/if}
