<template>
  <f7-page name="backup" @page:afterin="loadList">
    <f7-navbar :title="$t('backup.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="backup" /></f7-nav-right>
    </f7-navbar>

    <f7-block v-if="!session.canEdit" class="sync-pill off"><f7-icon f7="lock" size="18" /> {{ $t('backup.needPin') }}</f7-block>
    <f7-block v-else-if="!sync.online" class="sync-pill off"><f7-icon f7="wifi_slash" size="18" /> {{ $t('backup.offline') }}</f7-block>

    <f7-block-title>{{ $t('backup.downloadTitle') }}</f7-block-title>
    <f7-block strong-ios inset>
      <p>{{ $t('backup.downloadText') }}</p>
      <f7-button fill :disabled="!usable || busy" @click="download"><f7-icon f7="arrow_down_doc" size="18" /> {{ $t('backup.download') }}</f7-button>
    </f7-block>

    <f7-block-title>{{ $t('backup.restoreTitle') }}</f7-block-title>
    <f7-block strong-ios inset>
      <p>{{ $t('backup.restoreText') }}</p>
      <f7-button tonal color="red" :disabled="!usable || busy" @click="pick"><f7-icon f7="arrow_up_doc" size="18" /> {{ $t('backup.restore') }}</f7-button>
      <input ref="fileInput" type="file" accept=".zip,application/zip" hidden @change="picked" />
    </f7-block>

    <f7-block-title>{{ $t('backup.resyncTitle') }}</f7-block-title>
    <f7-block strong-ios inset>
      <p>{{ $t('backup.resyncText') }}</p>
      <f7-button tonal :disabled="busy" @click="resync"><f7-icon f7="arrow_2_circlepath" size="18" /> {{ $t('backup.resync') }}</f7-button>
    </f7-block>

    <f7-block-title>{{ $t('backup.autoTitle') }}</f7-block-title>
    <f7-block-header>{{ $t('backup.autoText') }}</f7-block-header>
    <f7-block v-if="listError" class="text-color-red">{{ listError }}</f7-block>
    <f7-block v-else-if="loaded && !list.length" class="text-align-center">{{ $t('backup.autoEmpty') }}</f7-block>
    <f7-list v-else-if="list.length" media-list strong-ios inset>
      <f7-list-item v-for="b in list" :key="b.name" :title="fmtDateTime(b.created_at)" :subtitle="$t('backup.reason.' + b.reason)" :text="fmtBytes(b.size)">
        <template #footer>
          <f7-button small tonal :disabled="!usable || busy" style="margin-top:6px" @click="back(b)"><f7-icon f7="arrow_uturn_left_circle" size="16" /> {{ $t('backup.back') }}</f7-button>
        </template>
      </f7-list-item>
    </f7-list>
    <div style="height:40px"></div>
  </f7-page>
</template>
<script setup>
import { computed, ref } from 'vue';
import { f7 } from 'framework7-vue';
import HelpButton from '../components/HelpButton.vue';
import * as api from '../api/index.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import { useData } from '../stores/data.js';
import { openUrl, fmtBytes } from '../lib/download.js';
import { fullResync } from '../lib/resync.js';
import { t, errorText, fmtDate } from '../i18n/index.js';

const session = useSession();
const sync = useSync();
const data = useData();
const busy = ref(false);
const list = ref([]);
const loaded = ref(false);
const listError = ref('');
const fileInput = ref(null);
const usable = computed(() => session.canEdit && sync.online);

const fmtDateTime = (iso) => {
  const d = new Date(iso);
  return isNaN(d) ? fmtDate(iso) : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

async function loadList() {
  if (!usable.value) return;
  try { list.value = await api.listBackups(); listError.value = ''; } catch (e) { listError.value = errorText(e); }
  loaded.value = true;
}

async function download() {
  busy.value = true;
  try { openUrl(await api.backupUrl()); } catch (e) { f7.dialog.alert(errorText(e), t('backup.title')); } finally { busy.value = false; }
}

function pick() { fileInput.value.value = ''; fileInput.value.click(); }
function picked(ev) {
  const file = ev.target.files && ev.target.files[0];
  if (!file) return;
  f7.dialog.confirm(t('backup.confirmFile', { name: file.name }), t('backup.confirmTitle'), () => run(() => api.restore(file)));
}
function back(b) {
  f7.dialog.confirm(t('backup.confirmBack', { d: fmtDateTime(b.created_at) }), t('backup.confirmTitle'), () => run(() => api.restoreBackup(b.name)));
}

// Neu synchronisieren: erst normal abgleichen; bleiben Aenderungen uebrig -> Rueckfrage; dann lokal verwerfen + Vollabgleich.
// Aendert keine Serverdaten, daher auch ohne PIN erlaubt.
async function resync() {
  busy.value = true;
  try {
    await sync.syncNow();
    if (!sync.online || sync.state === 'offline' || sync.state === 'error') { f7.dialog.alert(t('backup.resyncOffline'), t('backup.resyncTitle')); busy.value = false; return; }
    if (sync.pending > 0) {
      const text = sync.pending === 1 ? t('backup.resyncConfirmOne') : t('backup.resyncConfirm', { n: sync.pending });
      f7.dialog.confirm(text, t('backup.resyncTitle'), () => doResync(), () => { busy.value = false; });
    } else await doResync();
  } catch (e) { busy.value = false; f7.dialog.alert(errorText(e), t('backup.resyncTitle')); }
}
async function doResync() {
  busy.value = true;
  try {
    await sync.resetLocal();
    await data.load();
    if (sync.state === 'offline' || sync.state === 'error') f7.dialog.alert(t('backup.resyncOffline'), t('backup.resyncTitle'));
    else f7.toast.create({ text: t('backup.resyncDone'), closeTimeout: 2000, position: 'center' }).open();
  } catch (e) { f7.dialog.alert(errorText(e), t('backup.resyncTitle')); } finally { busy.value = false; }
}

// Nach dem Einspielen ersetzt der Server-Stand alles: lokalen Store leeren, Voll-Sync ab 0.
async function run(fn) {
  busy.value = true;
  const dlg = f7.dialog.preloader(t('backup.restoring'));
  try {
    const res = await fn();
    await fullResync({ data, sync });
    dlg.close();
    f7.dialog.alert(t('backup.done', { entries: res.entries ?? 0, receipts: res.receipts ?? 0 }), t('backup.title'));
    loaded.value = false;
    await loadList();
  } catch (e) {
    dlg.close();
    f7.dialog.alert(errorText(e), t('backup.title'));
  } finally { busy.value = false; }
}
</script>
