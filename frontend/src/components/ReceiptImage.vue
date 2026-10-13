<template>
  <img v-if="url" :src="url" :class="cls" :alt="alt" @click="$emit('click')" />
  <div v-else-if="failed" class="receipt-missing sub-small"><f7-icon f7="photo" size="28" /><div>{{ $t('detail.receiptUnavailable') }}</div></div>
  <f7-preloader v-else />
</template>
<script setup>
// Laedt eine Belegdatei aus IndexedDB bzw. per API (mit Token) und zeigt sie als Object-URL.
import { onBeforeUnmount, ref, watch } from 'vue';
import { useData } from '../stores/data.js';
const props = defineProps({ id: String, cls: String, alt: { type: String, default: '' } });
defineEmits(['click']);
const data = useData();
const url = ref('');
const failed = ref(false);
async function load() {
  if (url.value) URL.revokeObjectURL(url.value);
  url.value = ''; failed.value = false;
  if (!props.id) return;
  try { url.value = URL.createObjectURL(await data.receiptBlob(props.id)); } catch (e) { failed.value = true; }
}
watch(() => props.id, load, { immediate: true });
onBeforeUnmount(() => { if (url.value) URL.revokeObjectURL(url.value); });
</script>
