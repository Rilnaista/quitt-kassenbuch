<template>
  <f7-page name="form" @page:beforeremove="setDirty(dirtyKey, false)">
    <f7-navbar :title="title" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="form" /></f7-nav-right>
    </f7-navbar>
    <f7-list form strong-ios inset class="form-list" @submit.prevent="save">
      <f7-list-input type="date" :label="$t('form.date')" :value="f.date" :error-message="err.date" :error-message-force="!!err.date" @input="f.date = $event.target.value; err.date = ''" />
      <f7-list-input type="text" inputmode="decimal" :label="$t('form.amount') + ' (€)'" placeholder="0,00" :value="f.amount" :error-message="err.amount_cents" :error-message-force="!!err.amount_cents" @input="f.amount = $event.target.value; err.amount_cents = ''" />
      <template v-if="kind === 'out'">
        <Typeahead v-model="f.reason" :options="data.reasonOptions" :label="$t('form.reason')" :placeholder="$t('form.reasonPh')" :error-message="err.reason" @update:model-value="err.reason = ''" />
        <Typeahead v-model="f.category" :options="data.categoryOptions" :label="$t('form.category')" :placeholder="$t('form.categoryPh')" :error-message="err.category" @update:model-value="err.category = ''" />
        <f7-list-input type="text" :label="$t('form.item')" :placeholder="$t('form.itemPh')" :value="f.item" :error-message="err.item" :error-message-force="!!err.item" @input="f.item = $event.target.value; err.item = ''" />
      </template>
      <Typeahead v-else v-model="f.purpose" :options="data.purposeOptions" :label="$t('form.purpose')" :placeholder="$t('form.purposePh')" :error-message="err.purpose" @update:model-value="err.purpose = ''" />
      <f7-list-input type="text" :label="$t('form.note')" :placeholder="$t('form.notePh')" maxlength="100" :value="f.note" :error-message="err.note" :error-message-force="!!err.note" :info="f.note.length >= 80 ? $t('form.noteCount', { n: f.note.length }) : ''" @input="f.note = $event.target.value; err.note = ''" />
    </f7-list>

    <template v-if="kind === 'out'">
      <f7-block-title>{{ $t('form.receiptOpt') }}</f7-block-title>
      <f7-block>
        <img v-if="newReceiptUrl" class="receipt-thumb" :src="newReceiptUrl" alt="" />
        <ReceiptImage v-else-if="receiptId" :id="receiptId" cls="receipt-thumb" />
        <p v-if="receiptBusy" class="sub-small"><f7-preloader size="16" /> {{ $t('form.receiptShrinking') }}</p>
        <div class="grid grid-cols-2 grid-gap" style="margin-top:8px">
          <label class="button button-outline"><f7-icon f7="camera" size="16" /> {{ $t('form.camera') }}<input type="file" accept="image/*" capture="environment" hidden @change="onFile" /></label>
          <label class="button button-outline"><f7-icon f7="photo" size="16" /> {{ $t('form.file') }}<input type="file" accept="image/jpeg,image/png,image/webp,image/*" hidden @change="onFile" /></label>
        </div>
        <f7-button v-if="receiptId || newReceipt" small color="red" style="margin-top:8px" @click="removeReceipt">{{ $t('form.receiptRemove') }}</f7-button>
      </f7-block>
    </template>

    <f7-block>
      <f7-button large fill :disabled="!session.canEdit || receiptBusy || busy" @click="save">{{ $t('common.save') }}</f7-button>
    </f7-block>
  </f7-page>
</template>
<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { f7 } from 'framework7-vue';
import Typeahead from '../components/Typeahead.vue';
import HelpButton from '../components/HelpButton.vue';
import ReceiptImage from '../components/ReceiptImage.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import { validateEntry } from '../db/repo.js';
import { parseCents, fmtCentsPlain } from '../lib/money.js';
import { shrinkImage } from '../lib/image.js';
import { setDirty } from '../lib/dirty.js';
import { t, today } from '../i18n/index.js';

const props = defineProps({ f7router: Object, f7route: Object, kind: String });
const data = useData();
const session = useSession();
const existing = props.f7route.params.id ? data.byId(props.f7route.params.id) : null;
const kind = existing ? existing.type : props.kind || 'out';
const dirtyKey = 'booking-form-' + (existing ? existing.id : kind);

// "Weitere Position aus diesem Beleg": ?receipt=<id>&from=<Buchungs-id> (Datum/Grund/Kategorie vorbelegt)
const q = props.f7route.query || {};
const seed = !existing && q.receipt ? data.byId(q.from) : null;
const initial = {
  date: existing ? existing.date : seed ? seed.date : today(),
  amount: existing ? fmtCentsPlain(existing.amount_cents) : '',
  reason: existing?.reason || seed?.reason || '', category: existing?.category || seed?.category || '',
  item: existing?.item || '', purpose: existing?.purpose || '', note: existing?.note || '',
};
const f = reactive({ ...initial });
const err = reactive({});
const busy = ref(false);
const receiptId = ref(existing ? existing.receipt_id : !existing && q.receipt ? q.receipt : null);
const initialReceipt = receiptId.value;
const newReceipt = ref(null); // { blob, mime } – erst beim Speichern als Beleg vorgemerkt
const newReceiptUrl = ref('');
const receiptBusy = ref(false);
const title = computed(() => (existing ? t('form.edit') : kind === 'out' ? t('form.newExpense') : t('form.newIncome')));

watch([f, receiptId, newReceipt], () => {
  const changed = Object.keys(initial).some((k) => f[k] !== initial[k]) || !!newReceipt.value || receiptId.value !== (existing ? existing.receipt_id : initialReceipt);
  setDirty(dirtyKey, changed);
}, { deep: true });
onBeforeUnmount(() => { setDirty(dirtyKey, false); if (newReceiptUrl.value) URL.revokeObjectURL(newReceiptUrl.value); });

async function onFile(ev) {
  const file = ev.target.files && ev.target.files[0];
  ev.target.value = '';
  if (!file) return;
  receiptBusy.value = true;
  try {
    const r = await shrinkImage(file);
    if (newReceiptUrl.value) URL.revokeObjectURL(newReceiptUrl.value);
    newReceipt.value = { blob: r.blob, mime: r.mime };
    newReceiptUrl.value = URL.createObjectURL(r.blob);
  } catch (e) {
    f7.dialog.alert(t('form.receiptError'));
  } finally {
    receiptBusy.value = false;
  }
}
function removeReceipt() {
  if (newReceiptUrl.value) URL.revokeObjectURL(newReceiptUrl.value);
  newReceipt.value = null; newReceiptUrl.value = ''; receiptId.value = null;
}

async function save() {
  if (!session.canEdit || busy.value) return;
  const entry = {
    ...(existing ? { id: existing.id } : {}),
    type: kind,
    date: f.date,
    amount_cents: parseCents(f.amount) ?? 0,
    purpose: f.purpose, reason: f.reason, category: f.category, item: f.item, note: f.note,
    receipt_id: receiptId.value,
  };
  const v = validateEntry(entry);
  Object.keys(err).forEach((k) => delete err[k]);
  if (Object.keys(v).length) { Object.entries(v).forEach(([k, key]) => { err[k] = t(key); }); return; }
  busy.value = true;
  try {
    if (newReceipt.value) entry.receipt_id = await data.addReceipt(newReceipt.value.blob, newReceipt.value.mime);
    await data.save(entry);
    setDirty(dirtyKey, false);
    props.f7router.back();
    f7.toast.create({ text: t('form.saved'), closeTimeout: 2000, position: 'center' }).open();
  } finally {
    busy.value = false;
  }
}
</script>
