<template>
  <f7-page name="detail">
    <f7-navbar :title="$t('detail.title')" :back-link="$t('common.back')">
      <f7-nav-right><LockButton /><HelpButton topic="detail" /></f7-nav-right>
    </f7-navbar>
    <template v-if="e">
      <div class="block text-align-center" style="margin-top:20px">
        <Amount :type="e.type" :cents="e.amount_cents" big style="font-size:32px" />
        <div class="sub-small" style="margin-top:4px">{{ e.type === 'out' ? $t('detail.out') : $t('detail.in') }}</div>
      </div>
      <f7-block v-if="data.rejected[e.id]" class="sync-pill err" role="alert"><f7-icon f7="exclamationmark_triangle" size="18" /> {{ $t('detail.rejected') }}: {{ data.rejected[e.id] }}</f7-block>
      <f7-list strong-ios inset>
        <f7-list-item :header="$t('detail.nr')" :title="e.number != null ? String(e.number) : $t('detail.noNumber')" />
        <f7-list-item :header="$t('form.date')" :title="fmtDate(e.date)" />
        <template v-if="e.type === 'out'">
          <f7-list-item :header="$t('form.reason')" :title="e.reason || '–'" />
          <f7-list-item :header="$t('form.category')" :title="e.category || '–'" />
          <f7-list-item :header="$t('form.item')" :title="e.item || '–'" />
        </template>
        <f7-list-item v-else :header="$t('form.purpose')" :title="e.purpose || '–'" />
      </f7-list>

      <template v-if="e.receipt_id">
        <f7-block-title>{{ $t('detail.receipt') }}</f7-block-title>
        <f7-block class="text-align-center">
          <a href="#" :aria-label="$t('detail.zoomHint')" @click.prevent="zoom = true"><ReceiptImage :id="e.receipt_id" cls="receipt-thumb" :alt="$t('detail.receipt')" /></a>
          <div class="sub-small">{{ $t('detail.zoomHint') }}</div>
        </f7-block>
        <f7-block>
          <f7-button outline :disabled="!session.canEdit" @click="addToReceipt"><f7-icon f7="plus" size="16" /> {{ $t('flow.morePosition') }}</f7-button>
        </f7-block>
        <template v-if="siblings.length">
          <f7-block-title>{{ $t('detail.siblings') }}</f7-block-title>
          <f7-list media-list inset>
            <BookingRow v-for="s in siblings" :key="s.id" :e="s" @open="openSibling" />
          </f7-list>
        </template>
      </template>

      <f7-block class="grid grid-cols-2 grid-gap">
        <f7-button outline :disabled="!session.canEdit" @click="f7router.navigate('/bearbeiten/' + e.id + '/')"><f7-icon f7="pencil" size="16" /> {{ $t('common.edit') }}</f7-button>
        <f7-button outline color="red" :disabled="!session.canEdit" @click="remove"><f7-icon f7="trash" size="16" /> {{ $t('common.delete') }}</f7-button>
      </f7-block>
      <f7-block v-if="session.readonly" class="sub-small text-align-center">{{ $t('lock.readonlyHint') }}</f7-block>
    </template>
    <f7-block v-else-if="data.ready">{{ $t('detail.gone') }}</f7-block>

    <f7-popup :opened="zoom" @popup:closed="zoom = false; zoomed = false">
      <f7-page>
        <f7-navbar :title="$t('detail.receipt')"><f7-nav-right><f7-link popup-close>{{ $t('common.done') }}</f7-link></f7-nav-right></f7-navbar>
        <div v-if="e && e.receipt_id && zoom" class="zoom-wrap"><ReceiptImage :id="e.receipt_id" :cls="zoomed ? 'zoomed' : ''" @click="zoomed = !zoomed" /></div>
      </f7-page>
    </f7-popup>
  </f7-page>
</template>
<script setup>
import { computed, ref } from 'vue';
import { f7 } from 'framework7-vue';
import Amount from '../components/Amount.vue';
import BookingRow from '../components/BookingRow.vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import ReceiptImage from '../components/ReceiptImage.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import { fmtDate, t } from '../i18n/index.js';

const props = defineProps({ f7router: Object, f7route: Object });
const data = useData();
const session = useSession();
const zoom = ref(false); const zoomed = ref(false);
const e = computed(() => { const x = data.byId(props.f7route.params.id); return x && !x.deleted_at ? x : null; });
const siblings = computed(() => (e.value && e.value.receipt_id ? data.entriesOfReceipt(e.value.receipt_id).filter((x) => x.id !== e.value.id) : []));
const isMaster = computed(() => props.f7route.path.startsWith('/buchungen/'));
const openSibling = (s) => props.f7router.navigate((isMaster.value ? '/buchungen/' : '/buchung/') + s.id + '/');
function addToReceipt() {
  props.f7router.navigate(`/neu/ausgabe/?receipt=${e.value.receipt_id}&from=${e.value.id}`);
}
function remove() {
  const id = e.value.id;
  f7.dialog.confirm(t('detail.confirmDelete'), t('common.delete'), async () => {
    await data.trash(id);
    // Master-Detail (Desktop) bleibt stehen; auf dem Handy liegt das Detail als eigene Seite oben -> zurueck.
    if (!isMaster.value || window.innerWidth < 1024) props.f7router.back();
    f7.toast.create({ text: t('detail.trashed'), closeButton: true, closeButtonText: t('common.undo'), closeTimeout: 4000, on: { closeButtonClick: () => data.restore(id) } }).open();
  });
}
</script>
