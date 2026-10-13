<template>
  <f7-page name="home">
    <f7-navbar :title="settings.group_name">
      <f7-nav-left v-if="session.readonly"><LockButton /></f7-nav-left>
      <f7-nav-right><SyncIcon /><HelpButton topic="home" /></f7-nav-right>
    </f7-navbar>

    <div class="hero">
      <div class="hero-label">{{ $t('home.saldo') }}</div>
      <div class="hero-saldo">{{ fmtEur(data.balance) }}</div>
      <div class="hero-row">
        <span><f7-icon f7="arrow_down_left" size="16" /> +{{ fmtEur(data.totals.in) }}</span>
        <span><f7-icon f7="arrow_up_right" size="16" /> −{{ fmtEur(data.totals.out) }}</span>
      </div>
    </div>

    <f7-card v-if="ui.showHttpsHint" class="https-hint">
      <f7-card-content>
        <p><f7-icon f7="info_circle" size="18" /> {{ $t('home.httpsHint') }}</p>
        <f7-button small tonal @click="ui.dismissHttpsHint()">{{ $t('home.httpsOk') }}</f7-button>
      </f7-card-content>
    </f7-card>

    <template v-if="openReceipts.length">
      <f7-block-title>{{ $t('pending.title') }}</f7-block-title>
      <f7-list media-list inset>
        <f7-list-item v-for="r in openReceipts" :key="r.id" :title="$t('detail.receipt') + ' ' + r.date" :footer="$t('pending.footer')">
          <template #media><f7-icon f7="doc_text_viewfinder" /></template>
          <template #after>
            <f7-button small tonal :disabled="!session.canEdit" @click="openReceipt(r.id)">{{ $t('pending.recognize') }}</f7-button>
            <f7-button small color="red" :disabled="!session.canEdit" @click="discardReceipt(r.id)">{{ $t('flow.discard') }}</f7-button>
          </template>
        </f7-list-item>
      </f7-list>
    </template>

    <template v-if="data.categories.length">
      <f7-block-title>{{ $t('home.byCategory') }}</f7-block-title>
      <f7-list media-list inset>
        <f7-list-item v-for="c in data.categories.slice(0, 6)" :key="c.name" link="#" :title="c.name" :footer="countText(c.count)" @click="openCategory(c.name)">
          <template #after><Amount type="out" :cents="c.sum_cents" /></template>
          <template #text><div class="pbar"><i :style="{ width: (c.sum_cents / maxSum) * 100 + '%' }"></i></div></template>
        </f7-list-item>
        <f7-list-item v-if="data.categories.length > 6" link="#" :title="$t('home.allCategories')" @click="goTab('categories')" />
      </f7-list>
    </template>

    <f7-block-title>{{ $t('home.latest') }}</f7-block-title>
    <f7-list v-if="latest.length" media-list inset>
      <BookingRow v-for="e in latest" :key="e.id" :e="e" @open="openBooking" />
      <f7-list-item link="#" :title="$t('home.all')" @click="goTab('ledger')" />
    </f7-list>
    <f7-block v-else class="text-align-center empty-state">
      <p>{{ data.ready ? $t('home.empty') : '' }}</p>
      <f7-button v-if="session.canEdit && data.ready" tonal @click="ui.addOpen = true">{{ $t('home.firstBooking') }}</f7-button>
    </f7-block>
    <div style="height:90px"></div>
  </f7-page>
</template>
<script setup>
import { computed, onMounted } from 'vue';
import { f7 } from 'framework7-vue';
import Amount from '../components/Amount.vue';
import BookingRow from '../components/BookingRow.vue';
import SyncIcon from '../components/SyncIcon.vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import { useSettings } from '../stores/settings.js';
import { useUi } from '../stores/ui.js';
import { useReceiptQueue } from '../stores/receipts.js';
import { fmtDate, fmtEur, t } from '../i18n/index.js';

const props = defineProps({ f7router: Object });
const data = useData();
const session = useSession();
const settings = useSettings();
const ui = useUi();
const queue = useReceiptQueue();
onMounted(() => queue.load());
// Belege, die noch auf Erkennung/Buchung warten (vorgemerkt, z. B. KI-Erkennung offline)
const openReceipts = computed(() => queue.ids
  .filter((id) => !data.entriesOfReceipt(id).length)
  .map((id) => ({ id, date: fmtDate((data.receiptById(id) || {}).created_at || '') })));
const openReceipt = (id) => props.f7router.navigate('/beleg/?pending=' + id);
const discardReceipt = (id) => f7.dialog.confirm(t('flow.confirmDiscard'), t('flow.discard'), () => queue.remove(id));
const maxSum = computed(() => Math.max(1, ...data.categories.map((c) => c.sum_cents)));
const latest = computed(() => data.ledger.slice(-5).reverse());
const countText = (n) => (n === 1 ? t('home.countOne') : t('home.count', { n }));
const openBooking = (e) => props.f7router.navigate(`/buchung/${e.id}/`);
const openCategory = (n) => props.f7router.navigate(`/kategorie/${encodeURIComponent(n)}/`);
const goTab = (id) => f7.tab.show('#view-' + id);
</script>
