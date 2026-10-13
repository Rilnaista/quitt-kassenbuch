<template>
  <div class="lock-screen" role="alert">
    <Logo class="lock-logo" bg="rgba(255,255,255,0.22)" />
    <div class="lock-form">
      <h2>{{ $t('notInstalled.title') }}</h2>
      <p class="lock-text">{{ $t('notInstalled.text') }}</p>
      <p class="lock-text"><a class="lock-link" :href="setupUrl">{{ $t('notInstalled.setup') }}</a></p>
      <div class="lock-actions"><button class="lock-btn" @click="session.retryInstalled()">{{ $t('notInstalled.retry') }}</button></div>
    </div>
  </div>
</template>
<script setup>
import Logo from './Logo.vue';
import { useSession } from '../stores/session.js';
import { appBase } from '../api/index.js';
const session = useSession();
const setupUrl = new URL('setup.php', appBase()).href; // Installationspfad nie fest verdrahtet
</script>
