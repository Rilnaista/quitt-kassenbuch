<template>
  <f7-page name="update">
    <f7-navbar :title="$t('upd.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="update" /></f7-nav-right>
    </f7-navbar>

    <f7-list strong-ios inset>
      <f7-list-item :title="$t('upd.current')" :after="session.version || '–'" />
      <f7-list-item v-if="info" :title="$t('upd.latest')" :after="info.latest || '–'" />
    </f7-list>

    <f7-block v-if="!session.canEdit" class="sync-pill off"><f7-icon f7="lock" size="18" /> {{ $t('upd.needPin') }}</f7-block>
    <f7-block v-else-if="!sync.online" class="sync-pill off"><f7-icon f7="wifi_slash" size="18" /> {{ $t('upd.offline') }}</f7-block>

    <f7-block>
      <f7-button tonal :disabled="!usable || busy || upd.checking" @click="check">
        <f7-icon f7="arrow_2_circlepath" size="18" /> {{ upd.checking ? $t('upd.checking') : $t('upd.check') }}
      </f7-button>
    </f7-block>

    <f7-block v-if="message" :class="{ 'text-color-red': isError }" role="status">{{ message }}</f7-block>

    <template v-if="info && info.available">
      <f7-block-title>{{ $t('upd.available', { v: info.latest }) }}</f7-block-title>
      <f7-block-header v-if="info.published_at">{{ $t('upd.published', { d: fmtDate(info.published_at) }) }}</f7-block-header>
      <f7-block strong-ios inset>
        <strong>{{ $t('upd.notes') }}</strong>
        <div class="help-text">{{ info.notes || $t('upd.noNotes') }}</div>
      </f7-block>
      <f7-block v-if="info.docker" strong-ios inset>
        <p><f7-icon f7="shippingbox" size="18" /> <strong>{{ $t('upd.dockerTitle') }}</strong></p>
        <p>{{ $t('upd.dockerText') }}</p>
      </f7-block>
      <f7-block v-else>
        <f7-button fill large :disabled="!usable || busy" @click="confirmApply"><f7-icon f7="arrow_down_circle" size="18" /> {{ $t('upd.apply') }}</f7-button>
      </f7-block>
    </template>
    <div style="height:40px"></div>
  </f7-page>
</template>
<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { f7 } from 'framework7-vue';
import HelpButton from '../components/HelpButton.vue';
import * as api from '../api/index.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import { useUpdate } from '../stores/update.js';
import { setDirty } from '../lib/dirty.js';
import { updateNow } from '../lib/pwa.js';
import { t, errorText, fmtDate } from '../i18n/index.js';

const session = useSession();
const sync = useSync();
const upd = useUpdate();
const busy = ref(false);
const message = ref('');
const isError = ref(false);
const info = computed(() => upd.info);
const usable = computed(() => session.canEdit && sync.online);
onBeforeUnmount(() => setDirty('update-apply', false));

function say(text, err = false) { message.value = text; isError.value = err; }

async function check() {
  say('');
  try {
    const res = await upd.check();
    if (!res.available) say(t('upd.upToDate'));
  } catch (e) { say(errorText(e), true); }
}

function confirmApply() { f7.dialog.confirm(t('upd.confirm'), t('upd.apply'), apply); }

async function apply() {
  busy.value = true;
  say('');
  setDirty('update-apply', true); // waehrenddessen nie automatisch neu laden
  const dlg = f7.dialog.preloader(t('upd.applying'));
  try {
    const res = await api.updateApply();
    dlg.close();
    upd.clearHint();
    say(t('upd.success', { v: res.to }));
    try { session.setInfo({ ...(session.info || {}), version: res.to }); } catch (e) { /* egal */ }
    setDirty('update-apply', false);
    await updateNow();
  } catch (e) {
    dlg.close();
    setDirty('update-apply', false);
    // Verbindungsabbruch mitten im Austausch der Dateien: erst pruefen, ob es doch geklappt hat.
    if (e.code === 'network') {
      const v = await api.health().then((h) => h.version).catch(() => null);
      if (v && v !== session.version) { say(t('upd.success', { v })); upd.clearHint(); await updateNow(); return; }
      say(t('upd.interrupted'), true);
    } else {
      say(e.code === 'update_failed' && e.message ? e.message : errorText(e), true);
    }
  } finally { busy.value = false; }
}
</script>
