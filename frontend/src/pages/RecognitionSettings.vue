<template>
  <f7-page name="recog">
    <f7-navbar :title="$t('recog.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="settings" /></f7-nav-right>
    </f7-navbar>
    <f7-block v-if="hint" class="sync-pill off"><f7-icon f7="lock" /> {{ hint }}</f7-block>

    <f7-list strong-ios inset>
      <f7-list-item v-for="m in MODES" :key="m" radio radio-icon="start" name="recog-mode" :value="m" :checked="settings.recognition_mode === m"
        :title="$t('recog.mode.' + m)" :footer="$t('recog.modeHint.' + m)" :disabled="!canChange" @change="setMode(m)" />
    </f7-list>

    <template v-if="settings.recognition_mode === 'ai'">
      <f7-block-title>{{ $t('recog.provider') }}</f7-block-title>
      <f7-list strong-ios inset form @submit.prevent>
        <f7-list-input type="select" :label="$t('recog.provider')" :value="settings.ai_provider || ''" :disabled="!canChange" @change="setProvider($event.target.value)">
          <option v-if="!settings.ai_provider" value="" disabled>–</option>
          <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
        </f7-list-input>
        <template v-if="current">
          <f7-list-input v-if="current.needs_key" type="password" :label="$t('recog.key')" :placeholder="settings.ai_key_set ? '••••••••' : $t('recog.keyPh')" autocomplete="off"
            :value="key" :info="settings.ai_key_set ? $t('recog.keySet') : $t('recog.keyNotSet')" :disabled="!canChange"
            @input="key = $event.target.value; setDirty('recog-key', !!key)" @change="saveKey" />
          <f7-list-input type="text" :label="$t('recog.model')" :value="model" :placeholder="current.default_model" :disabled="!canChange"
            @input="model = $event.target.value" @change="saveModel" />
          <f7-list-input v-if="current.needs_base_url" type="url" :label="$t('recog.server')" :placeholder="$t('recog.serverPh')" :value="baseUrl" :disabled="!canChange"
            @input="baseUrl = $event.target.value" @change="saveBaseUrl" />
        </template>
      </f7-list>
      <f7-block v-if="current">
        <f7-button fill large :disabled="!canChange" @click="test">{{ $t('recog.test') }}</f7-button>
        <f7-button v-if="current.needs_key && settings.ai_key_set" color="red" style="margin-top:8px" :disabled="!canChange" @click="deleteKey">{{ $t('recog.keyDelete') }}</f7-button>
      </f7-block>
    </template>
    <div style="height:40px"></div>
  </f7-page>
</template>
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { f7 } from 'framework7-vue';
import HelpButton from '../components/HelpButton.vue';
import { useSettings } from '../stores/settings.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import * as api from '../api/index.js';
import { setDirty } from '../lib/dirty.js';
import { t, errorText } from '../i18n/index.js';

const MODES = ['off', 'local', 'ai'];
const settings = useSettings();
const session = useSession();
const sync = useSync();
const providers = ref([]);
const key = ref('');
const model = ref('');
const baseUrl = ref('');

// Nur mit PIN und Verbindung zum Server aenderbar
const canChange = computed(() => session.canEdit && sync.online);
const hint = computed(() => (!session.canEdit ? t('recog.needUnlock') : !sync.online ? t('recog.needOnline') : ''));
const current = computed(() => providers.value.find((p) => p.id === settings.ai_provider) || null);

function syncFields() {
  model.value = settings.ai_model || (current.value ? current.value.default_model : '') || '';
  baseUrl.value = settings.ai_base_url || '';
}
watch([() => settings.ai_provider, () => settings.ai_model, () => settings.ai_base_url, providers], syncFields);

const toast = (text) => f7.toast.create({ text, closeTimeout: 2000, position: 'center' }).open();
async function save(patch) {
  try { await settings.save(patch); toast(t('recog.saved')); return true; } catch (e) { f7.dialog.alert(errorText(e)); syncFields(); return false; }
}
async function setMode(m) {
  if (m === settings.recognition_mode) return;
  const patch = { recognition_mode: m };
  // Beim ersten Wechsel auf KI gleich einen Anbieter vorbelegen
  if (m === 'ai' && !settings.ai_provider && providers.value.length) patch.ai_provider = providers.value[0].id;
  await save(patch);
}
async function setProvider(id) {
  if (!id || id === settings.ai_provider) return;
  key.value = ''; setDirty('recog-key', false);
  await save({ ai_provider: id, ai_model: null, ai_base_url: null });
}
async function saveKey() {
  const v = key.value.trim();
  if (!v) { setDirty('recog-key', false); return; }
  if (await save({ ai_key: v })) { key.value = ''; setDirty('recog-key', false); }
}
async function saveModel() {
  const v = model.value.trim();
  const next = !v || (current.value && v === current.value.default_model) ? null : v;
  if (next === (settings.ai_model || null)) return;
  await save({ ai_model: next });
}
async function saveBaseUrl() {
  const v = baseUrl.value.trim() || null;
  if (v === (settings.ai_base_url || null)) return;
  await save({ ai_base_url: v });
}
function deleteKey() {
  f7.dialog.confirm(t('recog.confirmKeyDelete'), t('recog.keyDelete'), async () => {
    if (await save({ ai_key: '' })) toast(t('recog.keyDeleted'));
  });
}
async function test() {
  if (!current.value) return;
  const payload = { ai_provider: current.value.id };
  const m = model.value.trim();
  if (m) payload.ai_model = m;
  if (current.value.needs_base_url && baseUrl.value.trim()) payload.ai_base_url = baseUrl.value.trim();
  if (key.value.trim()) payload.ai_key = key.value.trim(); // ungespeicherte Eingabe; sonst gilt der gespeicherte Schluessel
  f7.dialog.preloader(t('recog.testing'));
  try {
    const r = await api.testRecognition(payload);
    f7.dialog.close();
    f7.dialog.alert(r.message || (r.ok ? 'OK' : ''), r.ok ? t('recog.title') : t('recog.test'));
  } catch (e) {
    f7.dialog.close();
    f7.dialog.alert(errorText(e));
  }
}

onMounted(async () => {
  settings.load();
  try { providers.value = await api.aiProviders(); } catch (e) { /* offline: Liste fehlt, Modus bleibt aenderbar */ }
  syncFields();
});
onBeforeUnmount(() => setDirty('recog-key', false));
</script>
