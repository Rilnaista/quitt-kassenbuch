<template>
  <f7-popup :opened="opened" @popup:closed="close" @popup:open="reset">
    <f7-page>
      <f7-navbar :title="$t('pin.changeTitle')">
        <f7-nav-right><f7-link popup-close>{{ $t('common.cancel') }}</f7-link></f7-nav-right>
      </f7-navbar>
      <f7-list form strong-ios inset @submit.prevent="save">
        <f7-list-input type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="current-password" maxlength="8" :label="$t('pin.old')" :value="oldPin" @input="oldPin = $event.target.value" />
        <f7-list-input type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="new-password" maxlength="8" :label="$t('pin.new')" :info="$t('pin.rule')" :value="newPin" @input="newPin = $event.target.value" />
        <f7-list-input type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="new-password" maxlength="8" :label="$t('pin.repeat')" :value="newPin2" @input="newPin2 = $event.target.value" />
      </f7-list>
      <f7-block v-if="error" class="text-color-red" role="alert">{{ error }}</f7-block>
      <f7-block><f7-button large fill :disabled="busy" @click="save">{{ $t('common.save') }}</f7-button></f7-block>
    </f7-page>
  </f7-popup>
</template>
<script setup>
import { ref, watch } from 'vue';
import { f7 } from 'framework7-vue';
import * as api from '../api/index.js';
import { setDirty } from '../lib/dirty.js';
import { t, errorText } from '../i18n/index.js';

const props = defineProps({ opened: Boolean });
const emit = defineEmits(['update:opened']);
const oldPin = ref(''); const newPin = ref(''); const newPin2 = ref('');
const error = ref(''); const busy = ref(false);
watch([oldPin, newPin, newPin2], () => setDirty('pin-change', !!(oldPin.value || newPin.value || newPin2.value)));
function reset() { oldPin.value = newPin.value = newPin2.value = ''; error.value = ''; }
function close() { setDirty('pin-change', false); emit('update:opened', false); }
async function save() {
  error.value = '';
  if (!/^\d{4,8}$/.test(newPin.value)) { error.value = t('pin.invalid'); return; }
  if (newPin.value !== newPin2.value) { error.value = t('pin.mismatch'); return; }
  busy.value = true;
  try {
    await api.changePin(oldPin.value, newPin.value);
    localStorage.setItem('quitt.pinLength', String(newPin.value.length));
    reset();
    close();
    f7.toast.create({ text: t('pin.changed'), closeTimeout: 2000, position: 'center' }).open();
  } catch (e) {
    error.value = e.code === 'unauthorized' || e.code === 'validation_failed' ? (e.message || t('pin.oldWrong')) : errorText(e);
  } finally { busy.value = false; }
}
</script>
