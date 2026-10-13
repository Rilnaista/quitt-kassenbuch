<template>
  <svg viewBox="0 0 64 64" role="img" aria-label="Quitt">
    <rect width="64" height="64" rx="14" :fill="bg" />
    <g fill="none" :stroke="fg" stroke-width="7" stroke-linecap="round" stroke-linejoin="round">
      <path d="M38.5 48.1A20 20 0 1 1 47.3 20" />
      <path d="M23 32l11 11 22-29" />
    </g>
  </svg>
</template>
<script setup>
defineProps({ bg: { type: String, default: 'var(--f7-theme-color)' }, fg: { type: String, default: '#fff' } });
</script>
