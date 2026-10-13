<template>
  <f7-page name="help">
    <f7-navbar :title="$t('nav.help')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="helpPage" /></f7-nav-right>
    </f7-navbar>
    <f7-block>{{ $t('help.intro') }}</f7-block>
    <f7-list accordion-list strong-ios inset>
      <f7-list-item v-for="k in HELP_TOPICS" :key="k" accordion-item :title="$t('help.topics.' + k + '.q')">
        <f7-accordion-content><f7-block class="help-text">{{ $t('help.topics.' + k + '.a') }}</f7-block></f7-accordion-content>
      </f7-list-item>
    </f7-list>
    <div style="height:40px"></div>
  </f7-page>
</template>
<script setup>
import HelpButton from '../components/HelpButton.vue';
import { HELP_TOPICS } from '../lib/help.js';
</script>
