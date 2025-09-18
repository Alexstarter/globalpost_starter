{if isset($globalpost_options) && $globalpost_options}
<div class="globalpost-carrier-options" data-globalpost-type="{$globalpost_type|escape:'html':'UTF-8'}">
  <p class="globalpost-title">{$globalpost_title|escape:'html':'UTF-8'}</p>
  <ul class="globalpost-options-list">
    {foreach from=$globalpost_options item=option name=options}
      <li class="globalpost-option-item">
        <label class="globalpost-option-label">
          <input type="radio"
                 name="globalpost_option[{$globalpost_type|escape:'html':'UTF-8'}]"
                 value="{$option.key|escape:'html':'UTF-8'}"
                 {if $globalpost_selected_key == $option.key || (!$globalpost_selected_key && $smarty.foreach.options.first)}checked="checked"{/if}>
          <span>{$option.label|escape:'html':'UTF-8'}</span>
        </label>
        {if $option.estimate}
          <div class="globalpost-option-estimate">{$option.estimate|escape:'html':'UTF-8'}</div>
        {/if}
      </li>
    {/foreach}
  </ul>
</div>
{/if}
