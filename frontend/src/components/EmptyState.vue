<template>
  <div class="empty-card" role="status">
    <Logo v-if="logo" class="empty-logo" />
    <f7-icon v-else-if="icon" :f7="icon" size="48" class="empty-icon" />
    <p class="empty-text">{{ text }}</p>
    <div v-if="$slots.default" class="empty-actions"><slot /></div>
  </div>
</template>
<script setup>
import Logo from './Logo.vue';
defineProps({ text: String, icon: String, logo: Boolean });
</script>
