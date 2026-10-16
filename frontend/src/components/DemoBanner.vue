<template>
  <div v-if="active" ref="el" class="demo-banner" role="status">
    <f7-icon f7="exclamationmark_triangle" size="16" />
    <span>{{ $t('demo.banner') }}</span>
  </div>
</template>
<script setup>
// REQ-080: dauerhafter, nicht wegklickbarer Demo-Hinweis oben. Schiebt ueber die
// CSS-Variable --f7-safe-area-top (Framework7-Muster) Navbars und Inhalt nach unten.
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { demoActive } from '../lib/demo.js';

const active = demoActive();
const el = ref(null);
let ro = null;

function measure() {
  if (el.value) document.documentElement.style.setProperty('--demo-banner-h', el.value.offsetHeight + 'px');
}

onMounted(() => {
  if (!active) return;
  document.documentElement.classList.add('demo-on');
  measure();
  if (typeof ResizeObserver !== 'undefined' && el.value) {
    ro = new ResizeObserver(measure);
    ro.observe(el.value);
  }
});

onBeforeUnmount(() => {
  if (ro) ro.disconnect();
  document.documentElement.classList.remove('demo-on');
  document.documentElement.style.removeProperty('--demo-banner-h');
});
</script>
