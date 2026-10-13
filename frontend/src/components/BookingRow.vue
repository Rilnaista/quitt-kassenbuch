<template>
  <f7-list-item class="bk" :class="e.type === 'out' ? 'bk-out' : 'bk-in'" link="#" :swipeout="swipeable" :title="title" :subtitle="sub" @click="$emit('open', e)">
    <template #after><Amount :type="e.type" :cents="e.amount_cents" /></template>
    <template #text>
      <span class="sub-small">
        {{ fmtDate(e.date) }}<template v-if="showBalance && e.balance_cents != null"> · {{ $t('ledger.balance') }} {{ fmtEur(e.balance_cents) }}</template>
        <f7-icon v-if="data.rejected[e.id]" f7="exclamationmark_triangle" size="13" color="red" :aria-label="$t('sync.rejectedOne')" />
        <f7-icon v-else-if="isPending" f7="arrow_2_circlepath" size="13" :aria-label="$t('sync.pendingOne')" />
      </span>
      <span v-if="e.note" class="bk-note">{{ e.note }}</span>
    </template>
    <f7-swipeout-actions v-if="swipeable" right>
      <f7-swipeout-button close color="blue" @click="$emit('edit', e)">{{ $t('common.edit') }}</f7-swipeout-button>
      <f7-swipeout-button close color="red" @click="$emit('remove', e)">{{ $t('common.delete') }}</f7-swipeout-button>
    </f7-swipeout-actions>
  </f7-list-item>
</template>
<script setup>
import { computed } from 'vue';
import Amount from './Amount.vue';
import { useSession } from '../stores/session.js';
import { useData } from '../stores/data.js';
import { fmtDate, fmtEur, t } from '../i18n/index.js';
defineOptions({ name: 'f7-list-item-booking' });
const props = defineProps({ e: Object, swipe: Boolean, showBalance: Boolean, showCategory: { type: Boolean, default: true } });
defineEmits(['open', 'edit', 'remove']);
const session = useSession();
const data = useData();
const swipeable = computed(() => props.swipe && session.canEdit);
const isPending = computed(() => data.pendingIds.includes(props.e.id));
const title = computed(() => (props.e.type === 'out' ? props.e.item || t('common.noItem') : props.e.purpose || t('ledger.income')));
const sub = computed(() => (props.e.type === 'out' ? [props.e.reason, props.showCategory ? props.e.category : ''].filter(Boolean).join(' · ') : t('ledger.income')));
</script>
