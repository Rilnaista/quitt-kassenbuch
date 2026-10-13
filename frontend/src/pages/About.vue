<template>
  <f7-page name="about">
    <f7-navbar :title="$t('settings.about')" :back-link="$t('common.back')" />
    <div class="about-logo">
      <Logo />
      <h1>Quitt</h1>
      <p>{{ $t('brand.subtitle') }}</p>
      <p><strong>{{ $t('about.slogan') }}</strong></p>
    </div>
    <f7-list strong-ios inset>
      <f7-list-item :title="$t('settings.version')" :after="session.version || '–'" />
      <f7-list-item :title="$t('about.license')" after="AGPL-3.0" />
    </f7-list>
    <f7-block class="help-text">{{ $t('about.licenseText') }}</f7-block>
    <f7-block-title>{{ $t('about.credits') }}</f7-block-title>
    <f7-block-header>{{ $t('about.creditsText') }}</f7-block-header>
    <f7-list strong-ios inset>
      <f7-list-item v-for="c in credits" :key="c[0]" :title="c[0]" :after="c[1]" />
    </f7-list>
  </f7-page>
</template>
<script setup>
import Logo from '../components/Logo.vue';
import { useSession } from '../stores/session.js';
const session = useSession();
// Laufzeit-Bibliotheken (vollstaendige Liste mit Versionen: CREDITS-frontend.md)
const credits = [['Framework7', 'MIT'], ['Framework7 Vue', 'MIT'], ['Framework7 Icons', 'MIT'], ['Vue', 'MIT'], ['Pinia', 'MIT'], ['idb', 'ISC'], ['Slim', 'MIT'], ['tFPDF', 'LGPL-2.1'], ['JetBrains Mono', 'OFL-1.1']];
</script>
