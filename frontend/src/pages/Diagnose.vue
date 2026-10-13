<template>
  <f7-page name="diagnose" @page:afterin="load">
    <f7-navbar :title="$t('diag.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="diagnose" /></f7-nav-right>
    </f7-navbar>

    <f7-block v-if="!session.canEdit" class="sync-pill off"><f7-icon f7="lock" size="18" /> {{ $t('diag.needPin') }}</f7-block>
    <f7-block v-else-if="!sync.online" class="sync-pill off"><f7-icon f7="wifi_slash" size="18" /> {{ $t('diag.offline') }}</f7-block>

    <f7-block>
      <f7-segmented strong>
        <f7-button v-for="l in LEVELS" :key="l" :active="level === l" @click="setLevel(l)">{{ $t('diag.level.' + l) }}</f7-button>
      </f7-segmented>
    </f7-block>

    <f7-block v-if="error" class="text-color-red">{{ error }}</f7-block>
    <f7-block v-else-if="loaded && !rows.length" class="text-align-center">{{ $t('diag.empty') }}</f7-block>
    <f7-list v-else-if="rows.length" media-list strong-ios inset class="log-list">
      <f7-list-item v-for="(r, i) in rows" :key="i" :title="r.message" :subtitle="fmtTs(r.ts) + ' · ' + r.channel" :class="'log-' + r.level">
        <template #media><f7-icon :f7="ICON[r.level] || 'info_circle'" size="22" /></template>
        <template v-if="hasContext(r)" #footer>
          <details class="log-ctx"><summary>{{ $t('diag.context') }}</summary><pre>{{ ctxText(r) }}</pre></details>
        </template>
      </f7-list-item>
    </f7-list>
    <f7-block v-if="usable"><f7-button small tonal @click="load"><f7-icon f7="arrow_2_circlepath" size="16" /> {{ $t('diag.reload') }}</f7-button></f7-block>

    <f7-block-title>{{ $t('diag.packageTitle') }}</f7-block-title>
    <f7-block strong-ios inset>
      <p>{{ $t('diag.packageText') }}</p>
      <f7-button fill :disabled="!usable || busy" @click="download"><f7-icon f7="arrow_down_doc" size="18" /> {{ $t('diag.package') }}</f7-button>
    </f7-block>

    <f7-block-title>{{ $t('diag.pageTitle') }}</f7-block-title>
    <f7-block strong-ios inset>
      <p>{{ $t('diag.pageText') }}</p>
      <p><a :href="diagUrl" target="_blank" rel="noopener" class="external">{{ $t('diag.pageLink') }}</a></p>
    </f7-block>
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
import { openUrl } from '../lib/download.js';
import { sortNewest } from '../lib/logs.js';
import { t, errorText } from '../i18n/index.js';

const LEVELS = ['error', 'warning', 'info'];
const ICON = { error: 'exclamationmark_octagon', warning: 'exclamationmark_triangle', info: 'info_circle' };
const session = useSession();
const sync = useSync();
const level = ref('warning');
const rows = ref([]);
const loaded = ref(false);
const error = ref('');
const busy = ref(false);
const usable = computed(() => session.canEdit && sync.online);
const diagUrl = new URL('diagnose.html', api.appBase()).href;

const fmtTs = (ts) => {
  const d = new Date(ts);
  return isNaN(d) ? String(ts) : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
};
const hasContext = (r) => r.context && (typeof r.context !== 'object' || Object.keys(r.context).length);
const ctxText = (r) => (typeof r.context === 'string' ? r.context : JSON.stringify(r.context, null, 2));

async function load() {
  if (!usable.value) return;
  try { rows.value = sortNewest(await api.logs({ level: level.value, limit: 200 })); error.value = ''; } catch (e) { error.value = errorText(e) || t('diag.loadError'); }
  loaded.value = true;
}
function setLevel(l) { level.value = l; load(); }
async function download() {
  busy.value = true;
  try { openUrl(await api.diagnosticsUrl()); } catch (e) { f7.dialog.alert(errorText(e), t('diag.title')); } finally { busy.value = false; }
}
</script>
