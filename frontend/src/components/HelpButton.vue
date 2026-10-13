<template>
  <f7-link class="icon-only" icon-f7="question_circle" :aria-label="$t('help.title')" @click="ui.openHelp(topic)" />
</template>
<script setup>
import { useUi } from '../stores/ui.js';
defineProps({ topic: { type: String, default: 'home' } });
const ui = useUi();
</script>
