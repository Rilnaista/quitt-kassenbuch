<template>
  <f7-page name="categories" @page:init="onInit">
    <f7-navbar :title="$t('categories.title')">
      <f7-nav-left v-if="session.readonly"><LockButton /></f7-nav-left>
      <f7-nav-right><HelpButton topic="categories" /></f7-nav-right>
    </f7-navbar>
    <f7-list v-if="data.categories.length" media-list strong-ios outline-ios>
      <f7-list-item v-for="c in data.categories" :key="c.name" link="#" :title="c.name" :footer="c.count === 1 ? $t('home.countOne') : $t('home.count', { n: c.count })" @click="open(c.name)">
        <template #media><f7-icon f7="tag" size="22" /></template>
        <template #after><Amount type="out" :cents="c.sum_cents" /></template>
        <template #text><div class="pbar"><i :style="{ width: (c.sum_cents / maxSum) * 100 + '%' }"></i></div></template>
      </f7-list-item>
    </f7-list>
    <EmptyState v-else icon="tag" :text="$t('empty.categories')" />
    <div style="height:90px"></div>
  </f7-page>
</template>
<script setup>
import { computed } from 'vue';
import EmptyState from '../components/EmptyState.vue';
import Amount from '../components/Amount.vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
const props = defineProps({ f7router: Object });
const data = useData();
const session = useSession();
const maxSum = computed(() => Math.max(1, ...data.categories.map((c) => c.sum_cents)));
const open = (n) => props.f7router.navigate(`/kategorien/${encodeURIComponent(n)}/`);
function onInit() {
  setTimeout(() => {
    props.f7router.view.checkMasterDetailBreakpoint();
    if (window.innerWidth >= 1024 && data.categories.length) open(data.categories[0].name);
  }, 60);
}
</script>
