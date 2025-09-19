{if isset($globalpost_tracking) && $globalpost_tracking.number}
<div class="card mt-3" id="globalpost-tracking-block">
  <div class="card-header">
    <h3 class="card-title mb-0">{$globalpost_tracking.title|escape:'html':'UTF-8'}</h3>
  </div>
  <div class="card-body">
    <p class="mb-2">
      <strong>{$globalpost_tracking.label_number|escape:'html':'UTF-8'}:</strong>
      <span class="ml-1">{$globalpost_tracking.number|escape:'html':'UTF-8'}</span>
    </p>
    {if $globalpost_tracking.url}
      <a class="btn btn-outline-primary" href="{$globalpost_tracking.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
        {$globalpost_tracking.label_link|escape:'html':'UTF-8'}
      </a>
    {/if}
  </div>
</div>
{/if}
