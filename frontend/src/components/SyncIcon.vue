<template>
  <span class="sync-icon-wrap">
    <a href="#" class="link icon-only sync-icon" :class="'st-' + state" role="button" :aria-label="label" @click.prevent="open = true">
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M7 18h10.5a3.5 3.5 0 0 0 .6-6.95A5.5 5.5 0 0 0 7.4 9.6 4.2 4.2 0 0 0 7 18z" />
        <path v-if="state === 'synced'" d="M9.5 13.2l2 2 3.5-3.8" />
        <g v-else-if="state === 'pending' || state === 'syncing'" class="arrows"><path d="M14.5 12.5a2.5 2.5 0 0 0-4.6-1M9.5 14.5a2.5 2.5 0 0 0 4.6 1" /><path d="M10.2 9.8v1.8H8.4M13.8 17.2v-1.8h1.8" /></g>
        <path v-else-if="state === 'error'" d="M12 11.2v2.6M12 15.9v.1" />
        <path v-else d="M4 4l16 16" />
      </svg>
      <span v-if="sync.pending > 0" class="sync-badge" aria-hidden="true">{{ sync.pending > 99 ? '99+' : sync.pending }}</span>
    </a>
    <f7-sheet :opened="open" style="height:auto" swipe-to-close backdrop @sheet:closed="open = false">
      <div class="sheet-modal-swipe-step">
        <f7-block class="sync-sheet">
          <h3>{{ $t('sync.title') }}</h3>
          <p class="sync-status-text" role="status">{{ text }}</p>
          <p class="sync-last">{{ lastText }}</p>
          <f7-button fill :disabled="!canSync" @click="go">{{ $t('sync.now') }}</f7-button>
          <f7-button @click="open = false">{{ $t('common.done') }}</f7-button>
        </f7-block>
      </div>
    </f7-sheet>
  </span>
</template>
<script setup>
import { computed, ref } from 'vue';
import { useSync } from '../stores/sync.js';
import { t } from '../i18n/index.js';
const sync = useSync();
const open = ref(false);
const state = computed(() => sync.display);
const canSync = computed(() => sync.online && sync.display !== 'syncing');
const text = computed(() => {
  const n = sync.pending;
  let s;
  if (state.value === 'offline') s = n ? t('sync.offlinePending', { n }) : t('sync.offline');
  else if (state.value === 'syncing') s = t('sync.syncing');
  else if (state.value === 'pending') s = n === 1 ? t('sync.pendingOneCount') : t('sync.pending', { n });
  else if (state.value === 'error') s = n ? t('sync.errorPending', { n }) : t('sync.error');
  else s = t('sync.ok');
  if (sync.rejected) s += ' · ' + t('sync.rejected', { n: sync.rejected });
  return s;
});
const label = computed(() => t('sync.title') + ': ' + text.value);
const lastText = computed(() => {
  if (!sync.lastSync) return t('sync.never');
  const d = new Date(sync.lastSync);
  return t('sync.last', { when: d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) });
});
const go = () => { sync.syncNow(); };
</script>
