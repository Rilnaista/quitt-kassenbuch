<template>
  <div class="lock-screen" role="dialog" aria-modal="true" :aria-label="$t('lock.title')">
    <Logo class="lock-logo" bg="rgba(255,255,255,0.22)" />

    <!-- PIN eingeben -->
    <template v-if="stage === 'pin'">
      <h2>{{ $t('lock.title') }}</h2>
      <div class="lock-msg" aria-live="polite">{{ msg }}</div>
      <div class="lock-dots" :class="{ shake }">
        <i v-for="n in dotCount" :key="n" :class="{ on: pin.length >= n }"></i>
      </div>
      <div class="lock-pad">
        <button v-for="k in keys" :key="k.d" class="lock-key" :aria-label="k.d" :disabled="blocked || busy" @click="press(k.d)">
          {{ k.d }}<small>{{ k.l }}</small>
        </button>
        <span v-if="session.readonly"><button class="lock-key plain" @click="session.cancelLock()">{{ $t('common.cancel') }}</button></span>
        <span v-else-if="canSubmitManually"><button class="lock-key plain" :disabled="blocked || busy" @click="submit">{{ $t('lock.ok') }}</button></span>
        <span v-else></span>
        <button class="lock-key" aria-label="0" :disabled="blocked || busy" @click="press('0')">0<small></small></button>
        <button class="lock-key plain" :aria-label="$t('lock.delete')" @click="back"><f7-icon f7="delete_left" size="28" /></button>
      </div>
      <div class="lock-links">
        <button v-if="session.readonly && canSubmitManually" :disabled="blocked || busy" @click="submit">{{ $t('lock.ok') }}</button>
        <button @click="startReset">{{ $t('lock.forgot') }}</button>
      </div>
    </template>

    <!-- PIN vergessen: Wiederherstellungscode bzw. reset-pin-Datei -->
    <form v-else-if="stage === 'reset'" class="lock-form" @submit.prevent="doReset">
      <h2>{{ $t('reset.title') }}</h2>
      <p class="lock-text">{{ resetFile ? $t('reset.fileFound') : $t('reset.intro') }}</p>
      <label v-if="!resetFile">{{ $t('reset.code') }}
        <input v-model="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required />
      </label>
      <label>{{ $t('reset.newPin') }}
        <input v-model="newPin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="new-password" maxlength="8" required />
      </label>
      <label>{{ $t('reset.repeatPin') }}
        <input v-model="newPin2" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="new-password" maxlength="8" required />
      </label>
      <div class="lock-msg" aria-live="polite">{{ msg }}</div>
      <div class="lock-actions">
        <button type="button" class="lock-btn plain" @click="toPin">{{ $t('common.cancel') }}</button>
        <button type="submit" class="lock-btn" :disabled="busy || blocked">{{ $t('reset.submit') }}</button>
      </div>
      <p class="lock-hint">{{ $t('reset.fileHint') }}</p>
    </form>

    <!-- Neuer Wiederherstellungscode -->
    <div v-else class="lock-form">
      <h2>{{ $t('reset.doneTitle') }}</h2>
      <p class="lock-text">{{ $t('reset.newCodeText') }}</p>
      <div class="lock-code" aria-live="polite">{{ newCode }}</div>
      <div class="lock-actions">
        <button class="lock-btn" @click="finishReset">{{ $t('reset.noted') }}</button>
      </div>
    </div>
  </div>
</template>
<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import Logo from './Logo.vue';
import * as api from '../api/index.js';
import { useSession } from '../stores/session.js';
import { t, errorText } from '../i18n/index.js';

const LEN_KEY = 'quitt.pinLength';
const session = useSession();
const keys = [['1', ''], ['2', 'ABC'], ['3', 'DEF'], ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'], ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ']].map(([d, l]) => ({ d, l }));
const stage = ref('pin');
const pin = ref('');
const msg = ref(t('lock.enter'));
const shake = ref(false);
const busy = ref(false);
const blockedUntil = ref(0);
const nowTs = ref(Date.now());
let ticker = null;

// Bekannte PIN-Laenge (4–8) -> automatisch absenden; sonst "OK"-Taste.
const knownLen = Number(localStorage.getItem(LEN_KEY)) || 0;
const dotCount = computed(() => Math.max(knownLen || 4, pin.value.length));
const canSubmitManually = computed(() => !knownLen && pin.value.length >= 4);
const blocked = computed(() => blockedUntil.value > nowTs.value);

function startBlock(seconds) {
  blockedUntil.value = Date.now() + (seconds || 30) * 1000;
  clearInterval(ticker);
  ticker = setInterval(() => {
    nowTs.value = Date.now();
    const left = Math.ceil((blockedUntil.value - nowTs.value) / 1000);
    if (left <= 0) { clearInterval(ticker); msg.value = stage.value === 'pin' ? t('lock.enter') : ''; } else msg.value = t('lock.wait', { s: left });
  }, 500);
  msg.value = t('lock.wait', { s: Math.ceil(seconds || 30) });
}

function wobble() {
  shake.value = true;
  setTimeout(() => { shake.value = false; pin.value = ''; }, 500);
}

function press(d) {
  if (blocked.value || busy.value || pin.value.length >= 8) return;
  pin.value += d;
  if (knownLen && pin.value.length === knownLen) setTimeout(submit, 120);
}
function back() { pin.value = pin.value.slice(0, -1); }

async function submit() {
  if (pin.value.length < 4 || busy.value || blocked.value) { wobble(); return; }
  busy.value = true;
  try {
    await session.login(pin.value);
    localStorage.setItem(LEN_KEY, String(pin.value.length));
    pin.value = '';
  } catch (e) {
    if (e.code === 'rate_limited') { startBlock(e.retryAfter); pin.value = ''; }
    else if (e.code === 'unauthorized') { msg.value = t('lock.wrong'); wobble(); }
    else { msg.value = errorText(e); pin.value = ''; }
  } finally {
    busy.value = false;
  }
}

// --- PIN vergessen ---
const resetFile = ref(false);
const code = ref('');
const newPin = ref('');
const newPin2 = ref('');
const newCode = ref('');
let pendingToken = null;

async function startReset() {
  msg.value = '';
  stage.value = 'reset';
  try { resetFile.value = !!(await api.resetInfo()).reset_file_present; } catch (e) { msg.value = errorText(e); }
}
function toPin() { stage.value = 'pin'; msg.value = t('lock.enter'); }
async function doReset() {
  if (!/^\d{4,8}$/.test(newPin.value)) { msg.value = t('pin.invalid'); return; }
  if (newPin.value !== newPin2.value) { msg.value = t('pin.mismatch'); return; }
  busy.value = true;
  try {
    const res = await api.resetPin({ recoveryCode: resetFile.value ? null : code.value.trim(), newPin: newPin.value });
    localStorage.setItem(LEN_KEY, String(newPin.value.length));
    pendingToken = res;
    newCode.value = res.recovery_code;
    stage.value = 'code';
    msg.value = '';
  } catch (e) {
    if (e.code === 'rate_limited') startBlock(e.retryAfter);
    else msg.value = e.code === 'unauthorized' ? t('reset.wrongCode') : errorText(e);
  } finally {
    busy.value = false;
  }
}
function finishReset() {
  session.adoptToken(pendingToken.token, pendingToken.expires_at);
}

function onKey(e) {
  if (stage.value !== 'pin') { if (e.key === 'Escape' && stage.value === 'reset') toPin(); return; }
  if (/^[0-9]$/.test(e.key)) press(e.key);
  else if (e.key === 'Backspace') back();
  else if (e.key === 'Enter') submit();
  else if (e.key === 'Escape') session.cancelLock();
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => { window.removeEventListener('keydown', onKey); clearInterval(ticker); });
</script>
