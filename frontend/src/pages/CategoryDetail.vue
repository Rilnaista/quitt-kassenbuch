<template>
  <f7-page name="category">
    <f7-navbar :title="name" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="categories" /></f7-nav-right>
    </f7-navbar>
    <div class="hero">
      <div class="hero-label">{{ $t('categories.sum') }}</div>
      <div class="hero-saldo">{{ fmtEur(sum) }}</div>
    </div>
    <f7-block-title>{{ $t('categories.expenses') }}</f7-block-title>
    <f7-list v-if="rows.length" media-list inset>
      <BookingRow v-for="e in rows" :key="e.id" :e="e" :show-category="false" @open="open" />
    </f7-list>
    <f7-block v-else class="text-align-center">{{ $t('ledger.empty') }}</f7-block>
    <div style="height:90px"></div>
  </f7-page>
</template>
<script setup>
import { computed } from 'vue';
import BookingRow from '../components/BookingRow.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { fmtEur } from '../i18n/index.js';
const props = defineProps({ f7router: Object, f7route: Object });
const data = useData();
const name = computed(() => decodeURIComponent(props.f7route.params.name));
const rows = computed(() => data.ledger.filter((e) => e.type === 'out' && e.category === name.value).reverse());
const sum = computed(() => rows.value.reduce((s, e) => s + e.amount_cents, 0));
const open = (e) => props.f7router.navigate(`/buchung/${e.id}/`);
</script>
