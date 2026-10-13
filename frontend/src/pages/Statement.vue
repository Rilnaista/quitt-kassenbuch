<template>
  <f7-page name="statement">
    <f7-navbar :title="$t('stmt.title')">
      <f7-nav-left v-if="session.readonly"><LockButton /></f7-nav-left>
      <f7-nav-right><HelpButton topic="statement" /></f7-nav-right>
    </f7-navbar>

    <f7-block-title>{{ $t('stmt.period') }}</f7-block-title>
    <f7-block>
      <f7-segmented strong>
        <f7-button v-for="p in PRESETS" :key="p" :active="preset === p" @click="setPreset(p)">{{ $t('stmt.preset.' + p) }}</f7-button>
      </f7-segmented>
    </f7-block>
    <f7-list form strong-ios inset>
      <f7-list-input type="date" :label="$t('stmt.from')" :value="from" :disabled="preset !== 'custom'" @input="from = $event.target.value" />
      <f7-list-input type="date" :label="$t('stmt.to')" :value="to" :disabled="preset !== 'custom'" @input="to = $event.target.value" />
    </f7-list>

    <f7-list v-if="ok" strong-ios inset>
      <f7-list-item :title="$t('stmt.opening')" :after="fmtEur(sum.opening)" />
      <f7-list-item :title="$t('stmt.closing')" :after="fmtEur(sum.closing)" :footer="sum.count === 1 ? $t('stmt.summaryOne') : $t('stmt.summary', { n: sum.count })" />
    </f7-list>
    <f7-block v-else class="text-color-red">{{ $t('stmt.invalid') }}</f7-block>

    <f7-block v-if="!sync.online" class="sync-pill off"><f7-icon f7="wifi_slash" size="18" /> {{ $t('stmt.offline') }}</f7-block>
    <f7-block v-else-if="!session.canEdit">
      <p>{{ $t('stmt.needPin') }}</p>
      <f7-button v-if="session.readonly" tonal @click="session.openLock()">{{ $t('stmt.unlock') }}</f7-button>
    </f7-block>
    <f7-block>
      <f7-button fill large :disabled="!ok || !canCreate || busy" @click="create('pdf')">
        <f7-icon f7="doc_text" size="18" /> {{ busy && kindBusy === 'pdf' ? $t('stmt.creating') : $t('stmt.create') }}
      </f7-button>
      <f7-button outline large style="margin-top:12px" :disabled="!ok || !canCreate || busy" @click="create('xlsx')">
        <f7-icon f7="table" size="18" /> {{ busy && kindBusy === 'xlsx' ? $t('stmt.excelCreating') : $t('stmt.excel') }}
      </f7-button>
      <p class="hint-small">{{ $t('stmt.openHint') }}</p>
    </f7-block>
    <div style="height:40px"></div>
  </f7-page>
</template>
<script setup>
import { computed, ref } from 'vue';
import { f7 } from 'framework7-vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import * as api from '../api/index.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import { useData } from '../stores/data.js';
import { PRESETS, presetRange, validRange, summarize } from '../lib/statement.js';
import { openUrl, preopenWindow, shareFile } from '../lib/download.js';
import { t, errorText, fmtEur } from '../i18n/index.js';

const session = useSession();
const sync = useSync();
const data = useData();
const busy = ref(false);
const kindBusy = ref('pdf');
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const preset = ref('all');
const earliest = () => (data.ledger.length ? data.ledger[0].date : null);
const init = presetRange('all', new Date(), earliest());
const from = ref(init.from);
const to = ref(init.to);

function setPreset(p) {
  preset.value = p;
  if (p !== 'custom') ({ from: from.value, to: to.value } = presetRange(p, new Date(), earliest()));
}
const ok = computed(() => validRange(from.value, to.value));
const sum = computed(() => summarize(data.ledger, from.value, to.value));
const canCreate = computed(() => sync.online && session.canEdit);

async function create(kind = 'pdf') {
  const xlsx = kind === 'xlsx';
  kindBusy.value = kind;
  const win = preopenWindow(); // iOS: Fenster im Klick oeffnen, sonst blockt der Browser
  busy.value = true;
  try {
    // Teilen-Blatt mit Datei, wenn moeglich (Handy); sonst Einmal-Ticket oeffnen.
    if (!win && navigator.canShare && navigator.share) {
      const blob = xlsx ? await api.exportXlsx(from.value, to.value) : await api.statementPdf(from.value, to.value);
      const name = t(xlsx ? 'stmt.excelFile' : 'stmt.file', { from: from.value, to: to.value });
      if (await shareFile(blob, name, xlsx ? XLSX : 'application/pdf')) return;
    }
    const url = xlsx ? await api.exportUrl(from.value, to.value) : await api.statementUrl(from.value, to.value);
    openUrl(url, { win, download: xlsx, newTab: !xlsx });
  } catch (e) {
    if (win) win.close();
    f7.dialog.alert(errorText(e), t('stmt.title'));
  } finally { busy.value = false; }
}
</script>
