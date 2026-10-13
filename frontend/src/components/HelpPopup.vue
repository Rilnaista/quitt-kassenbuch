<template>
  <f7-popup :opened="ui.help.open" @popup:closed="ui.help.open = false">
    <f7-page>
      <f7-navbar :title="$t('help.title')">
        <f7-nav-right><f7-link popup-close>{{ $t('common.done') }}</f7-link></f7-nav-right>
      </f7-navbar>
      <f7-block-title>{{ $t('help.ctx.' + ctx + '.title') }}</f7-block-title>
      <f7-block strong-ios inset class="help-text">{{ $t('help.ctx.' + ctx + '.text') }}</f7-block>
      <f7-block v-if="ctx !== 'helpPage'">
        <f7-button fill @click="more"><f7-icon f7="question_circle" size="18" /> {{ $t('help.ctxMore') }}</f7-button>
      </f7-block>
    </f7-page>
  </f7-popup>
</template>
<script setup>
import { computed } from 'vue';
import { f7 } from 'framework7-vue';
import { useUi } from '../stores/ui.js';
import { helpContext } from '../lib/help.js';
const ui = useUi();
const ctx = computed(() => helpContext(ui.help.key));
function more() {
  ui.help.open = false;
  const v = f7.views.current || f7.views.get('#view-home');
  setTimeout(() => v.router.navigate('/hilfe/'), 350);
}
</script>
