<template>
  <f7-list-input class="ta-root" type="text" :label="label" :value="modelValue" :placeholder="placeholder" clear-button autocomplete="off"
    :error-message="errorMessage" :error-message-force="!!errorMessage" :disabled="disabled"
    @input="onInput" @focus="open = true" @blur="onBlur" @keydown="onKey" @input:clear="emit('update:modelValue', '')">
    <template #root-end>
      <ul v-if="open && matches.length" class="ta-list" role="listbox">
        <li v-for="(m, i) in matches" :key="m" :class="{ hi: i === hi }" role="option" :aria-selected="i === hi" @mousedown.prevent="pick(m)" @touchstart.prevent="pick(m)">{{ m }}</li>
      </ul>
    </template>
  </f7-list-input>
</template>
<script setup>
// Vorschlaege kommen ausschliesslich aus lokalen Daten (offline-faehig).
import { computed, ref } from 'vue';
defineOptions({ name: 'f7-list-input-typeahead' });
const props = defineProps({ modelValue: String, options: { type: Array, default: () => [] }, label: String, placeholder: String, errorMessage: String, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const hi = ref(-1);
const matches = computed(() => {
  const q = (props.modelValue || '').toLowerCase().trim();
  return props.options.filter((o) => o.toLowerCase().includes(q) && o.toLowerCase() !== q).slice(0, 6);
});
function onInput(e) { emit('update:modelValue', e.target.value); open.value = true; hi.value = -1; }
function pick(m) { emit('update:modelValue', m); open.value = false; }
function onBlur() { setTimeout(() => { open.value = false; }, 120); }
function onKey(e) {
  if (e.key === 'ArrowDown') { hi.value = Math.min(hi.value + 1, matches.value.length - 1); e.preventDefault(); }
  else if (e.key === 'ArrowUp') { hi.value = Math.max(hi.value - 1, 0); e.preventDefault(); }
  else if (e.key === 'Enter' && hi.value >= 0 && open.value) { pick(matches.value[hi.value]); e.preventDefault(); }
  else if (e.key === 'Escape') open.value = false;
}
</script>
