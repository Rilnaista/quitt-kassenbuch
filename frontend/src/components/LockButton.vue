<template>
  <f7-link v-if="session.readonly" class="icon-only" icon-f7="lock" :aria-label="$t('lock.unlockToEdit')" @click="session.openLock()" />
</template>
<script setup>
import { useSession } from '../stores/session.js';
const session = useSession();
</script>
