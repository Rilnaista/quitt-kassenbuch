<template>
  <f7-page name="flow" @page:beforeremove="leave">
    <f7-navbar :title="$t('flow.title')" :back-link="$t('common.back')">
      <f7-nav-right><HelpButton topic="receipt" /></f7-nav-right>
    </f7-navbar>

    <!-- 1 Aufnahme -->
    <template v-if="stage === 'pick'">
      <f7-block class="text-align-center">
        <f7-icon f7="camera" size="56" color="gray" />
        <p>{{ $t('flow.pickText') }}</p>
      </f7-block>
      <f7-block v-if="!session.canEdit" class="sync-pill off"><f7-icon f7="lock" /> {{ $t('flow.needUnlock') }}</f7-block>
      <f7-block>
        <label class="button button-large button-fill" :class="{ disabled: !session.canEdit }"><f7-icon f7="camera" size="18" /> {{ $t('flow.camera') }}<input type="file" accept="image/*" capture="environment" hidden :disabled="!session.canEdit" @change="onFile" /></label>
        <label class="button button-large button-outline" style="margin-top:12px" :class="{ disabled: !session.canEdit }"><f7-icon f7="photo" size="18" /> {{ $t('flow.file') }}<input type="file" accept="image/jpeg,image/png,image/webp,image/*" hidden :disabled="!session.canEdit" @change="onFile" /></label>
      </f7-block>
      <f7-block v-if="!sync.online && settings.recognition_mode === 'ai'" class="sync-pill off"><f7-icon f7="wifi_slash" /> {{ $t('flow.offlineNote') }}</f7-block>
    </template>

    <!-- 2 Erkennung -->
    <div v-else-if="stage === 'working'" class="rec-progress text-align-center">
      <img v-if="imageUrl" class="receipt-thumb" :src="imageUrl" alt="" />
      <p>{{ status }}</p>
      <f7-progressbar v-if="progress != null" :progress="progress" />
      <f7-progressbar v-else infinite />
    </div>

    <!-- vorgemerkt -->
    <f7-block v-else-if="stage === 'queued'" class="text-align-center">
      <f7-icon f7="arrow_2_circlepath" size="56" color="orange" />
      <h3>{{ $t('flow.queuedTitle') }}</h3>
      <p>{{ $t('flow.queuedText') }}</p>
      <f7-button fill large @click="f7router.back()">{{ $t('common.done') }}</f7-button>
    </f7-block>

    <!-- 3 Pruefen -->
    <template v-else>
      <f7-block v-if="imageUrl" class="text-align-center"><img class="receipt-thumb" :src="imageUrl" alt="" /></f7-block>
      <f7-block v-if="notice" class="sync-pill off" role="alert"><f7-icon f7="info_circle" /> {{ notice }}</f7-block>
      <f7-list strong-ios inset>
        <f7-list-input type="date" :label="$t('flow.date')" :value="date" @input="date = $event.target.value" />
      </f7-list>
      <f7-block class="sub-small">{{ $t('flow.prefillHint') }}</f7-block>
      <f7-block v-if="total != null" class="sub-small">{{ $t('flow.total', { sum: fmtEur(total) }) }}</f7-block>

      <f7-card v-for="(c, i) in cards" :key="c.key" class="pos-card" :class="{ skipped: c.skipped }">
        <f7-card-header>
          <span>{{ $t('flow.position', { n: i + 1 }) }}</span>
          <f7-button small :tonal="!c.skipped" :fill="c.skipped" @click="c.skipped = !c.skipped">{{ c.skipped ? $t('flow.unskip') : $t('flow.skip') }}</f7-button>
        </f7-card-header>
        <f7-card-content :padding="false">
          <f7-block v-if="c.skipped" class="sub-small">{{ $t('flow.skipped') }}</f7-block>
          <template v-else>
            <f7-list form>
              <f7-list-input type="text" :label="$t('form.item')" :placeholder="$t('form.itemPh')" :value="c.item" :error-message="errs[c.key]?.item" :error-message-force="!!errs[c.key]?.item" @input="c.item = $event.target.value" />
              <f7-list-input type="text" inputmode="decimal" :label="$t('form.amount') + ' (€)'" :value="amountText(c)" :error-message="errs[c.key]?.amount_cents" :error-message-force="!!errs[c.key]?.amount_cents" @input="c.override = $event.target.value" />
              <template v-if="c.gross >= 0">
                <Typeahead :model-value="c.reason" :options="data.reasonOptions" :label="$t('form.reason')" :placeholder="$t('form.reasonPh')" :error-message="errs[c.key]?.reason" @update:model-value="cards = setField(cards, i, 'reason', $event)" />
                <Typeahead :model-value="c.category" :options="data.categoryOptions" :label="$t('form.category')" :placeholder="$t('form.categoryPh')" :error-message="errs[c.key]?.category" @update:model-value="cards = setField(cards, i, 'category', $event)" />
              </template>
            </f7-list>
            <f7-block v-for="(d, j) in c.discounts" :key="j" class="discount-line">
              <span><f7-icon f7="tag" size="16" /> {{ $t('flow.discount', { item: d.item || $t('flow.discountDefault'), amount: fmtEur(d.cents) }) }}</span>
              <f7-button small tonal @click="cards = unfoldDiscount(cards, i, j)">{{ $t('flow.split') }}</f7-button>
            </f7-block>
            <f7-block v-if="c.gross < 0" class="discount-line">
              <span class="sub-small">{{ $t('flow.negativeNote') }}</span>
              <f7-button v-if="i > 0" small tonal @click="cards = foldCard(cards, i)">{{ $t('flow.fold') }}</f7-button>
            </f7-block>
            <f7-block v-else-if="!isBookable(c)" class="sub-small">{{ $t('flow.notBookable') }}</f7-block>
          </template>
        </f7-card-content>
      </f7-card>

      <f7-block>
        <f7-button large outline @click="cards = addCard(cards)"><f7-icon f7="plus" size="16" /> {{ $t('flow.add') }}</f7-button>
      </f7-block>
      <f7-block>
        <f7-button large fill :disabled="!session.canEdit || !bookable || busy" @click="bookAll">{{ $t('flow.bookAll', { n: bookable }) }}</f7-button>
      </f7-block>
      <div style="height:40px"></div>
    </template>
  </f7-page>
</template>
<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { f7 } from 'framework7-vue';
import Typeahead from '../components/Typeahead.vue';
import HelpButton from '../components/HelpButton.vue';
import { useData } from '../stores/data.js';
import { useSession } from '../stores/session.js';
import { useSettings } from '../stores/settings.js';
import { useSync } from '../stores/sync.js';
import { useReceiptQueue } from '../stores/receipts.js';
import * as api from '../api/index.js';
import * as db from '../db/index.js';
import * as repo from '../db/repo.js';
import { shrinkImage, withTimeout, STEP_TIMEOUT_MS } from '../lib/image.js';
import { recognizeText, warmOcrCache } from '../lib/ocr.js';
import { parseReceiptText } from '../lib/receiptParse.js';
import { buildCards, newCard, amountText, isBookable, setField, addCard, unfoldDiscount, foldCard, toEntries } from '../lib/receiptCards.js';
import { setDirty } from '../lib/dirty.js';
import { t, today, fmtEur, errorText } from '../i18n/index.js';

const props = defineProps({ f7router: Object, f7route: Object });
const data = useData();
const session = useSession();
const settings = useSettings();
const sync = useSync();
const queue = useReceiptQueue();

const DIRTY = 'receipt-flow';
const stage = ref('pick'); // pick | working | queued | review
const status = ref('');
const progress = ref(null);
const imageUrl = ref('');
const date = ref(today());
const cards = ref([]);
const total = ref(null);
const notice = ref('');
const busy = ref(false);
const errs = reactive({});
let blob = null; // Belegbild (verkleinert)
let rid = null; // Beleg-ID, sobald lokal gespeichert

const bookable = computed(() => cards.value.filter(isBookable).length);
watch([stage, cards], () => setDirty(DIRTY, stage.value === 'review' && cards.value.length > 0), { deep: true });
function leave() { setDirty(DIRTY, false); }
onBeforeUnmount(() => { leave(); if (imageUrl.value) URL.revokeObjectURL(imageUrl.value); });

function setImage(b) {
  if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
  blob = b;
  imageUrl.value = URL.createObjectURL(b);
}

function showReview(positions, d, tot, msg = '') {
  date.value = d || today();
  total.value = tot ?? null;
  cards.value = positions && positions.length ? buildCards(positions) : [];
  if (!cards.value.length) {
    cards.value = [newCard()];
    if (!msg) msg = t('flow.nothingFound');
  }
  notice.value = msg;
  stage.value = 'review';
}
const manual = (msg = '') => showReview([], null, null, msg);

/** Fehler des Beleg-Flows (Schritt, Dateityp, Groesse, Bildmasse, userAgent) an /logs/client; nie selbst werfen. */
function reportFlow(step, e, file, extra = {}) {
  try {
    const info = (e && e.info) || {};
    const ctx = { step: (e && e.step) || step, type: file && file.type, size: file && file.size, width: info.width, height: info.height, userAgent: navigator.userAgent, ...extra };
    console.error('receipt-flow', ctx, e);
    api.logClient('error', `Beleg-Flow: ${(e && e.message) || e}`, ctx).catch(() => {});
  } catch (x) { /* still */ }
}

async function onFile(ev) {
  const file = ev.target.files && ev.target.files[0];
  ev.target.value = '';
  if (!file) return;
  stage.value = 'working'; status.value = t('flow.shrinking'); progress.value = null;
  try {
    const r = await shrinkImage(file);
    if (r.fallback) reportFlow(r.step || 'shrink', { message: r.reason, info: r.info, step: r.step }, file, { fallback: 'original' });
    setImage(r.blob);
  } catch (e) {
    reportFlow('shrink', e, file);
    stage.value = 'pick';
    f7.dialog.alert(e && e.info && e.info.timeout ? t('flow.imageTimeout') : t('flow.imageError'));
    return;
  }
  try {
    await start();
  } catch (e) {
    reportFlow('start', e, file);
    manual(t('flow.saveError')); // Status nie haengen lassen: von Hand weiter
  }
}

/** Je nach Erkennungsart weiter (Einstellung recognition_mode). */
async function start() {
  const mode = settings.recognition_mode;
  if (mode === 'ai') {
    if (!rid) {
      rid = await withTimeout(data.addReceipt(blob, blob.type || 'image/jpeg'), STEP_TIMEOUT_MS, 'save'); // lokal + Upload ueber die Outbox
      await withTimeout(queue.add(rid), STEP_TIMEOUT_MS, 'queue');
    }
    if (!sync.online) { stage.value = 'queued'; return; }
    return runAi();
  }
  if (mode === 'local') return runLocal();
  return manual();
}

async function runAi() {
  stage.value = 'working'; status.value = t('flow.uploading'); progress.value = null;
  try {
    await sync.syncNow();
    if (await db.get('uploads', rid)) { manual(t('flow.uploadError')); return; }
    status.value = t('flow.recognizing');
    const r = await api.recognizeReceipt(rid);
    showReview(r.positions || [], r.date, r.total_cents);
  } catch (e) {
    if (e.code === 'network') { stage.value = 'queued'; return; }
    manual(t('flow.aiError', { msg: errorText(e) }));
  }
}

async function runLocal() {
  stage.value = 'working'; status.value = t('flow.loadingEngine'); progress.value = 0;
  try {
    const text = await recognizeText(blob, (p) => { status.value = t('flow.recognizing'); progress.value = Math.round(p * 100); });
    const r = parseReceiptText(text);
    showReview(r.positions, r.date, r.total_cents);
  } catch (e) {
    manual(t('flow.ocrError'));
  }
}

async function bookAll() {
  if (busy.value || !session.canEdit) return;
  const entries = toEntries(cards.value, { date: date.value, receiptId: null });
  Object.keys(errs).forEach((k) => delete errs[k]);
  if (!entries.length) { f7.dialog.alert(t('flow.nothingToBook')); return; }
  // Pflichtfelder je Karte pruefen (spiegelt die Server-Validierung)
  let bad = false;
  cards.value.filter(isBookable).forEach((c) => {
    const [e] = toEntries([c], { date: date.value, receiptId: null });
    const v = repo.validateEntry(e);
    if (Object.keys(v).length) { bad = true; errs[c.key] = Object.fromEntries(Object.entries(v).map(([k, key]) => [k, t(key)])); }
  });
  if (bad) { f7.toast.create({ text: t('flow.fixErrors'), closeTimeout: 2500, position: 'center' }).open(); return; }
  busy.value = true;
  try {
    if (!rid && blob) rid = await withTimeout(data.addReceipt(blob, blob.type || 'image/jpeg'), STEP_TIMEOUT_MS, 'save');
    for (const e of entries) await repo.saveEntry({ ...e, receipt_id: rid });
    if (rid) await queue.remove(rid);
    await data.changed();
    setDirty(DIRTY, false);
    props.f7router.back();
    f7.toast.create({ text: entries.length === 1 ? t('flow.bookedOne') : t('flow.booked', { n: entries.length }), closeTimeout: 2500, position: 'center' }).open();
  } catch (e) {
    reportFlow('book', e, blob);
    f7.dialog.alert(t('flow.saveError'));
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  await queue.load();
  if (settings.recognition_mode === 'local' && sync.online) warmOcrCache();
  const pid = props.f7route.query && props.f7route.query.pending;
  if (!pid) return;
  // Vorgemerkten Beleg erkennen
  try {
    const b = await data.receiptBlob(pid);
    rid = pid;
    setImage(b);
  } catch (e) {
    f7.dialog.alert(t('flow.imageError'));
    return;
  }
  if (settings.recognition_mode === 'ai') {
    if (!sync.online) { stage.value = 'queued'; return; }
    await runAi();
  } else if (settings.recognition_mode === 'local') await runLocal();
  else manual();
});
</script>
