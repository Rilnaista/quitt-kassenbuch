<template>
  <f7-page name="settings">
    <f7-navbar :title="$t('settings.title')">
      <f7-nav-left v-if="session.readonly"><LockButton /></f7-nav-left>
      <f7-nav-right><HelpButton topic="settings" /></f7-nav-right>
    </f7-navbar>

    <!-- Allgemein (serverseitig, fuer alle Geraete) -->
    <f7-block-title>{{ $t('settings.general') }}</f7-block-title>
    <f7-list strong-ios inset>
      <f7-list-input type="text" :label="$t('settings.groupName')" :value="groupName" :disabled="!canChangeServer" :info="serverHint"
        @input="groupName = $event.target.value" @change="saveGroupName" @keydown.enter="$event.target.blur()" />
    </f7-list>

    <!-- Darstellung (geraete-lokal) -->
    <f7-block-title>{{ $t('settings.appearance') }}</f7-block-title>
    <f7-block-header>{{ $t('settings.appearanceLocal') }}</f7-block-header>
    <f7-block>
      <f7-segmented strong>
        <f7-button v-for="m in DARK_MODES" :key="m" :active="look.darkMode === m" @click="look.set('darkMode', m)">{{ $t('settings.mode.' + m) }}</f7-button>
      </f7-segmented>
    </f7-block>
    <f7-block-title medium>{{ $t('settings.accent') }}</f7-block-title>
    <div class="accent-row" role="radiogroup" :aria-label="$t('settings.accent')">
      <button v-for="c in ACCENTS" :key="c.hex" class="accent-dot" :class="{ sel: look.accent === c.hex }" :style="{ background: c.hex }" role="radio" :aria-checked="look.accent === c.hex" :aria-label="$t('settings.accents.' + c.id)" @click="look.set('accent', c.hex)">
        <f7-icon v-if="look.accent === c.hex" f7="checkmark" size="20" />
      </button>
    </div>
    <f7-block-title medium>{{ $t('settings.amounts') }}</f7-block-title>
    <f7-list strong-ios inset>
      <f7-list-item v-for="s in AMOUNT_SCHEMES" :key="s" radio radio-icon="start" name="scheme" :value="s" :checked="look.amountScheme === s" :title="$t('settings.scheme.' + s)" @change="look.set('amountScheme', s)">
        <template #footer>
          <span :class="'amt-prev amt-prev-' + s" style="display:flex;gap:16px;margin-top:4px"><Amount type="in" :cents="1250" /><Amount type="out" :cents="790" /></span>
        </template>
      </f7-list-item>
    </f7-list>

    <!-- Zugriff -->
    <f7-block-title>{{ $t('settings.access') }}</f7-block-title>
    <f7-list strong-ios inset>
      <f7-list-item link="#" :title="$t('settings.changePin')" :class="{ disabled: !canChangeServer }" @click="pinOpen = true" />
      <f7-list-item :title="$t('settings.readNoPin')" :footer="$t('settings.readNoPinHint')">
        <template #after><f7-toggle :checked="settings.read_without_pin" :disabled="!canChangeServer" @toggle:change="saveReadNoPin" /></template>
      </f7-list-item>
      <f7-list-item v-if="session.canEdit" link="#" :title="$t('settings.lockNow')" @click="lockNow" />
      <f7-list-item v-else-if="session.readonly" link="#" :title="$t('lock.unlockToEdit')" @click="session.openLock()" />
    </f7-list>

    <!-- Folgende Bereiche: PLATZHALTER fuer spaetere Schritte -->
    <f7-block-title>{{ $t('settings.more') }}</f7-block-title>
    <f7-list strong-ios inset>
      <f7-list-item link="/belegerkennung/" :title="$t('settings.recognition')"><template #media><f7-icon f7="viewfinder" /></template></f7-list-item>
      <f7-list-item link="/sicherung/" :title="$t('settings.backup')"><template #media><f7-icon f7="archivebox" /></template></f7-list-item>
      <f7-list-item link="/update/" :title="$t('settings.update')" :after="session.version"><template #media><f7-icon f7="arrow_down_circle" /></template><template #inner-end><span v-if="upd.hasHint" class="update-dot" role="img" :aria-label="$t('settings.updateAvailable')"></span></template></f7-list-item>
      <f7-list-item link="/diagnose/" :title="$t('settings.diagnose')"><template #media><f7-icon f7="waveform_path_ecg" /></template></f7-list-item>
      <f7-list-item link="/papierkorb/" :title="$t('nav.trash')" :badge="data.trashed.length || undefined"><template #media><f7-icon f7="trash" /></template></f7-list-item>
      <f7-list-item link="/hilfe/" :title="$t('nav.help')"><template #media><f7-icon f7="question_circle" /></template></f7-list-item>
      <f7-list-item link="/ueber/" :title="$t('settings.about')"><template #media><f7-icon f7="info_circle" /></template></f7-list-item>
    </f7-list>
    <div style="height:40px"></div>

    <PinChange v-model:opened="pinOpen" />
  </f7-page>
</template>
<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { f7 } from 'framework7-vue';
import Amount from '../components/Amount.vue';
import LockButton from '../components/LockButton.vue';
import HelpButton from '../components/HelpButton.vue';
import PinChange from '../components/PinChange.vue';
import { useAppearance, ACCENTS, DARK_MODES, AMOUNT_SCHEMES } from '../stores/appearance.js';
import { useSettings } from '../stores/settings.js';
import { useSession } from '../stores/session.js';
import { useSync } from '../stores/sync.js';
import { useData } from '../stores/data.js';
import { useUpdate } from '../stores/update.js';
import { setDirty } from '../lib/dirty.js';
import { t, errorText } from '../i18n/index.js';

const look = useAppearance();
const settings = useSettings();
const session = useSession();
const sync = useSync();
const data = useData();
const upd = useUpdate();
const pinOpen = ref(false);
const canChangeServer = computed(() => session.canEdit && sync.online);
const serverHint = computed(() => (!session.canEdit ? t('settings.needUnlock') : !sync.online ? t('settings.needOnline') : ''));

const groupName = ref(settings.group_name);
watch(() => settings.group_name, (v) => { groupName.value = v; });
watch(groupName, (v) => setDirty('settings-group', v !== settings.group_name));

const toast = (text) => f7.toast.create({ text, closeTimeout: 2000, position: 'center' }).open();
async function saveGroupName() {
  const v = groupName.value.trim();
  if (!v) { groupName.value = settings.group_name; setDirty('settings-group', false); return; }
  if (v === settings.group_name) return;
  try { await settings.save({ group_name: v }); toast(t('settings.saved')); } catch (e) { f7.dialog.alert(errorText(e)); groupName.value = settings.group_name; }
  setDirty('settings-group', false);
}
async function saveReadNoPin(v) {
  if (v === settings.read_without_pin) return;
  try {
    await settings.save({ read_without_pin: v });
    session.setReadWithoutPin(v);
    toast(t('settings.saved'));
  } catch (e) {
    f7.dialog.alert(errorText(e));
    // Schalter optisch zuruecksetzen (Store-Wert hat sich nicht geaendert -> erzwingen)
    settings.read_without_pin = v;
    await nextTick();
    settings.read_without_pin = !v;
  }
}
function lockNow() {
  const msg = sync.pending ? t('settings.lockPending', { n: sync.pending }) : t('settings.lockConfirm');
  f7.dialog.confirm(msg, t('settings.lockNow'), () => session.lock());
}
</script>
