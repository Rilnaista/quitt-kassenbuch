<template>
  <!-- PLATZHALTER: Funktion folgt in einem spaeteren Schritt (bewusst nicht Teil des Fundaments). -->
  <f7-page name="placeholder">
    <f7-navbar :title="$t(titleKey)" :back-link="f7router && f7router.history.length > 1 ? $t('common.back') : undefined" />
    <f7-block class="text-align-center placeholder-note">
      <f7-icon :f7="icon" size="56" color="gray" />
      <p><strong>{{ $t(titleKey) }}</strong></p>
      <p>{{ $t('placeholder.text') }}</p>
    </f7-block>
  </f7-page>
</template>
<script setup>
defineProps({ titleKey: String, icon: { type: String, default: 'hammer' }, f7router: Object });
</script>
