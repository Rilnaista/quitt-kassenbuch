<template>
  <f7-page name="trash">
    <f7-navbar :title="$t('trash.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="trash" /></f7-nav-right>
    </f7-navbar>
    <EmptyState v-if="!data.trashed.length" icon="trash" :text="$t('empty.trash')" />
    <template v-else>
      <f7-block v-if="!sync.online" class="sync-pill off"><f7-icon f7="wifi_slash" size="18" /> {{ $t('trash.onlineOnly') }}</f7-block>
      <f7-list media-list strong-ios inset>
        <f7-list-item v-for="e in data.trashed" :key="e.id" :title="e.type === 'out' ? e.item : e.purpose" :subtitle="[e.reason, e.category].filter(Boolean).join(' · ')" :text="$t('trash.deletedOn', { d: fmtDate(e.deleted_at) })">
          <template #after><Amount :type="e.type" :cents="e.amount_cents" /></template>
          <template #footer>
            <div class="grid grid-cols-2 grid-gap" style="margin-top:6px">
              <f7-button small tonal :disabled="!session.canEdit" @click="data.restore(e.id)"><f7-icon f7="arrow_uturn_left_circle" size="16" /> {{ $t('trash.restore') }}</f7-button>
              <f7-button small tonal color="red" :disabled="!session.canEdit || (!sync.online && e.version != null)" @click="purge(e)"><f7-icon f7="xmark" size="16" /> {{ $t('trash.purge') }}</f7-button>
            </div>
          </template>
        </f7-list-item>
      </f7-list>
      <f7-block>
        <f7-button fill color="red" :disabled="!session.canEdit || !sync.online" @click="clear">{{ $t('trash.emptyAll') }}</f7-button>
      </f7-block>
    </template>
  </f7-page>
</template>
<script setup>
import { f7 } from 'framework7-vue';
import EmptyState from '../components/EmptyState.vue';
import Amount from '../components/Amount.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import { fmtDate, t, errorText } from '../i18n/index.js';

const data = useData();
const session = useSession();
const sync = useSync();
const fail = (e) => f7.dialog.alert(errorText(e), t('trash.title'));
const purge = (e) => f7.dialog.confirm(t('trash.confirmPurge'), t('trash.purge'), () => data.purge(e.id).catch(fail));
const clear = () => f7.dialog.confirm(t('trash.confirmEmpty'), t('trash.emptyAll'), () => data.emptyTrash().catch(fail));
</script>
