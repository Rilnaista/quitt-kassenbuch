<template>
  <!-- Ein-/Ausgang nie nur ueber Farbe: Pfeil-Icon + Vorzeichen (Design-Brief) -->
  <span class="amt" :class="[isIn ? 'amt-in' : 'amt-out', big && 'amt-lg']">
    <f7-icon :f7="isIn ? 'arrow_down_left' : 'arrow_up_right'" :size="big ? 16 : 14" />
    <span>{{ isIn ? '+' : '−' }}{{ fmtEur(Math.abs(cents || 0)) }}</span>
  </span>
</template>
<script setup>
import { computed } from 'vue';
import { fmtEur } from '../i18n/index.js';
const props = defineProps({ type: String, cents: Number, big: Boolean });
const isIn = computed(() => props.type !== 'out');
</script>
