<template>
  <f7-page name="ledger" @page:init="onInit">
    <f7-navbar :title="$t('ledger.title')">
      <f7-nav-left v-if="session.readonly"><LockButton /></f7-nav-left>
      <f7-nav-right>
        <f7-link class="icon-only" icon-f7="line_horizontal_3_decrease" :badge="activeFilters || undefined" badge-color="red" :aria-label="$t('ledger.filter')" @click="sheet = true" />
        <HelpButton topic="ledger" />
      </f7-nav-right>
      <f7-subnavbar :inner="false">
        <f7-searchbar :placeholder="$t('ledger.search')" :disable-button-text="$t('common.cancel')" :clear-button="true" @input="q = $event.target.value" @searchbar:clear="q = ''" @searchbar:disable="q = ''" />
      </f7-subnavbar>
    </f7-navbar>

    <template v-if="rows.length">
      <section v-for="g in groups" :key="g.key" class="month-group">
        <div class="month-head">
          <h2>{{ g.label }}</h2>
          <div class="month-sums">
            <span>{{ $t('ledger.monthIn') }} +{{ fmtEur(g.in) }}</span>
            <span>{{ $t('ledger.monthOut') }} −{{ fmtEur(g.out) }}</span>
          </div>
        </div>
        <f7-list media-list class="bk-list" strong-ios outline-ios>
          <BookingRow v-for="e in g.rows" :key="e.id" :e="e" swipe show-balance @open="open" @edit="edit" @remove="remove" />
        </f7-list>
      </section>
    </template>
    <EmptyState v-else-if="activeFilters || q.trim()" icon="search" :text="$t('empty.noMatch')">
      <f7-button tonal @click="resetAll">{{ $t('empty.resetFilter') }}</f7-button>
    </EmptyState>
    <EmptyState v-else :text="$t('empty.ledger')">
      <f7-button v-if="session.canEdit" tonal @click="ui.addOpen = true">{{ $t('home.firstBooking') }}</f7-button>
    </EmptyState>
    <div style="height:90px"></div>

    <f7-sheet :opened="sheet" style="height:auto" swipe-to-close backdrop @sheet:closed="sheet = false">
      <f7-page-content>
        <f7-block-title>{{ $t('ledger.filter') }}</f7-block-title>
        <f7-list strong-ios>
          <f7-list-input type="select" :label="$t('ledger.fType')" :value="fType" @change="fType = $event.target.value">
            <option value="">{{ $t('ledger.all') }}</option>
            <option value="in">{{ $t('ledger.fIn') }}</option>
            <option value="out">{{ $t('ledger.fOut') }}</option>
          </f7-list-input>
          <f7-list-input type="select" :label="$t('form.category')" :value="fCategory" @change="fCategory = $event.target.value">
            <option value="">{{ $t('ledger.all') }}</option>
            <option v-for="c in categoryNames" :key="c" :value="c">{{ c }}</option>
          </f7-list-input>
          <f7-list-input type="select" :label="$t('form.reason')" :value="fReason" @change="fReason = $event.target.value">
            <option value="">{{ $t('ledger.all') }}</option>
            <option v-for="r in reasonNames" :key="r" :value="r">{{ r }}</option>
          </f7-list-input>
        </f7-list>
        <f7-block class="grid grid-cols-2 grid-gap">
          <f7-button outline @click="reset">{{ $t('ledger.reset') }}</f7-button>
          <f7-button fill @click="sheet = false">{{ $t('common.done') }}</f7-button>
        </f7-block>
      </f7-page-content>
    </f7-sheet>
  </f7-page>
</template>
<script setup>
import { computed, ref } from 'vue';
import { f7 } from 'framework7-vue';
import BookingRow from '../components/BookingRow.vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import EmptyState from '../components/EmptyState.vue';
import { useUi } from '../stores/ui.js';
import { filterLedger, groupByMonth } from '../lib/ledger.js';
import { t, fmtEur } from '../i18n/index.js';

const props = defineProps({ f7router: Object });
const data = useData();
const session = useSession();
const ui = useUi();
const q = ref(''); const sheet = ref(false);
const fType = ref(''); const fCategory = ref(''); const fReason = ref('');
const activeFilters = computed(() => [fType, fCategory, fReason].filter((f) => f.value).length);
const byName = (a, b) => a.localeCompare(b, 'de');
const categoryNames = computed(() => [...data.categoryOptions].sort(byName));
const reasonNames = computed(() => [...data.reasonOptions].sort(byName));
// neueste oben; laufender Saldo bleibt der chronologische (auch bei aktivem Filter)
const rows = computed(() => filterLedger(data.ledger, { q: q.value, type: fType.value, category: fCategory.value, reason: fReason.value }).reverse());
const groups = computed(() => groupByMonth(rows.value));
const resetAll = () => { reset(); q.value = ''; const sb = document.querySelector('.page-current .searchbar input'); if (sb) sb.value = ''; };
const reset = () => { fType.value = ''; fCategory.value = ''; fReason.value = ''; };
const open = (e) => props.f7router.navigate(`/buchungen/${e.id}/`);
const edit = (e) => props.f7router.navigate(`/bearbeiten/${e.id}/`);
async function remove(e) {
  await data.trash(e.id);
  f7.toast.create({ text: t('detail.trashed'), closeButton: true, closeButtonText: t('common.undo'), closeTimeout: 4000, on: { closeButtonClick: () => data.restore(e.id) } }).open();
}
function onInit() {
  // Desktop (Master-Detail): neueste Buchung gleich im Detail zeigen
  setTimeout(() => {
    props.f7router.view.checkMasterDetailBreakpoint();
    if (window.innerWidth >= 1024 && data.ledger.length) props.f7router.navigate(`/buchungen/${data.ledger[data.ledger.length - 1].id}/`);
  }, 60);
}
</script>
