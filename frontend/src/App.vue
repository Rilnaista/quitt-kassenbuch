<template>
  <f7-app v-bind="f7params">
    <f7-panel left cover :visible-breakpoint="1024" class="side-nav">
      <f7-page>
        <div class="side-brand"><Logo style="width:34px;height:34px" /> Quitt</div>
        <div class="side-group">{{ settings.group_name }}</div>
        <f7-list>
          <f7-list-item v-for="n in nav" :key="n.id" :title="$t(n.label)" link="#" :class="{ 'side-sel': ui.tab === n.tab && !n.url }" @click="goto(n.tab, n.url)">
            <template #media><f7-icon :f7="n.icon" /></template>
          </f7-list-item>
        </f7-list>
      </f7-page>
    </f7-panel>

    <f7-views tabs class="safe-areas">
      <f7-toolbar tabbar icons bottom class="main-tabbar">
        <f7-toolbar-pane>
          <f7-link v-for="tb in tabs" :key="tb.id" :class="{ 'has-dot': tb.id === 'settings' && upd.hasHint }" :tab-link="'#view-' + tb.id" :tab-link-active="tb.id === 'home'" :icon-f7="tb.icon" :text="$t(tb.label)" />
        </f7-toolbar-pane>
      </f7-toolbar>
      <f7-view v-for="tb in tabs" :id="'view-' + tb.id" :key="tb.id" :main="tb.id === 'home'" tab :tab-active="tb.id === 'home'" :url="tb.url" :master-detail-breakpoint="1024" />
    </f7-views>

    <f7-fab v-if="fabVisible && session.canEdit" class="global-fab" position="right-bottom" :text="$t('add.fab')" @click="ui.addOpen = true">
      <f7-icon f7="plus" />
    </f7-fab>

    <f7-actions :opened="ui.addOpen" @actions:closed="ui.addOpen = false">
      <f7-actions-group>
        <f7-actions-label>{{ $t('add.title') }}</f7-actions-label>
        <f7-actions-button bold @click="open('/beleg/')">{{ $t('add.scan') }}</f7-actions-button>
        <f7-actions-button @click="open('/neu/ausgabe/')">{{ $t('add.expense') }}</f7-actions-button>
        <f7-actions-button @click="open('/neu/einnahme/')">{{ $t('add.income') }}</f7-actions-button>
      </f7-actions-group>
      <f7-actions-group>
        <f7-actions-button color="red">{{ $t('common.cancel') }}</f7-actions-button>
      </f7-actions-group>
    </f7-actions>

    <HelpPopup />

    <div v-if="ui.updateReady" class="update-banner" role="status">
      <span>{{ $t('update.ready') }}</span>
      <f7-button small fill @click="reloadNow">{{ $t('update.reload') }}</f7-button>
    </div>

    <NotInstalled v-if="session.mode === 'not_installed'" />
    <Lock v-else-if="session.showLock" />
    <div v-else-if="session.mode === 'loading'" class="boot-screen"><Logo class="lock-logo" /></div>
  </f7-app>
</template>
<script setup>
import { computed, onMounted, watch } from 'vue';
import { f7 } from 'framework7-vue';
import Logo from './components/Logo.vue';
import Lock from './components/Lock.vue';
import NotInstalled from './components/NotInstalled.vue';
import HelpPopup from './components/HelpPopup.vue';
import { useUpdate } from './stores/update.js';
import routes from './routes.js';
import * as api from './api/index.js';
import { useSession } from './stores/session.js';
import { useSettings } from './stores/settings.js';
import { useData } from './stores/data.js';
import { useSync } from './stores/sync.js';
import { useUi } from './stores/ui.js';
import { useAppearance } from './stores/appearance.js';
import { initPwa, applyUpdate } from './lib/pwa.js';
import { t } from './i18n/index.js';

const session = useSession();
const settings = useSettings();
const data = useData();
const sync = useSync();
const ui = useUi();
const look = useAppearance();
const upd = useUpdate();

// ?theme=ios|md erzwingt den Plattform-Look (Test am Desktop); Standard: auto.
const qTheme = new URLSearchParams(location.search).get('theme');
const f7params = {
  name: 'Quitt',
  theme: qTheme === 'ios' || qTheme === 'md' ? qTheme : 'auto',
  darkMode: look.darkMode === 'auto' ? 'auto' : look.darkMode === 'dark',
  colors: { primary: look.accent },
  routes,
  view: { masterDetailBreakpoint: 1024, animate: true },
  touch: { tapHold: false },
  navbar: { iosCenterTitle: false, mdCenterTitle: false }, // Titel linksbuendig, dezent
  dialog: { buttonOk: 'OK', buttonCancel: t('common.cancel') }, // Standard waere englisch ("Cancel")
};

const tabs = [
  { id: 'home', label: 'nav.home', icon: 'house', url: '/' },
  { id: 'ledger', label: 'nav.ledger', icon: 'list_bullet', url: '/buchungen/' },
  { id: 'categories', label: 'nav.categories', icon: 'tag', url: '/kategorien/' },
  { id: 'statement', label: 'nav.tabStatement', icon: 'doc_text', url: '/auszug/' },
  { id: 'settings', label: 'nav.tabSettings', icon: 'gear_alt', url: '/einstellungen/' },
];
const nav = [
  ...tabs.map((x) => ({ id: x.id, tab: x.id, label: x.id === 'statement' ? 'nav.statement' : x.id === 'settings' ? 'nav.settings' : x.label, icon: x.icon })),
  { id: 'trash', tab: 'settings', url: '/papierkorb/', label: 'nav.trash', icon: 'trash' },
  { id: 'help', tab: 'settings', url: '/hilfe/', label: 'nav.help', icon: 'question_circle' },
];

function goto(tab, url) {
  f7.tab.show('#view-' + tab);
  const v = f7.views.get('#view-' + tab);
  if (!v) return;
  if (url) v.router.navigate(url);
  else if (v.router.history.length > 1) v.router.back(v.router.history[0], { force: true });
}
function open(url) { const v = f7.views.current; (v || f7.views.get('#view-home')).router.navigate(url); }
const fabVisible = computed(() => !!ui.fabs['view-' + ui.tab]);
function reloadNow() { ui.updateReady = false; applyUpdate(true); }

function applyAmountScheme() {
  const c = document.documentElement.classList;
  ['classic', 'cb', 'icons'].forEach((s) => c.remove('amt-' + s));
  c.add('amt-' + look.amountScheme);
}
applyAmountScheme();
watch(() => look.amountScheme, applyAmountScheme);
watch(() => look.darkMode, (v) => f7.setDarkMode(v === 'auto' ? 'auto' : v === 'dark'));
watch(() => look.accent, (v) => f7.setColorTheme(v));
watch(() => settings.group_name, (v) => { document.title = v ? `${v} – Quitt` : 'Quitt'; }, { immediate: true });

// Zugriff geaendert (entsperrt/gesperrt): Einstellungen + Daten neu holen.
// sync.init() ist idempotent und startet beim ersten Aufruf den ersten Sync (Trigger "Start").
watch(() => session.mode, (m, old) => {
  if (m === 'loading' || m === 'not_installed') return;
  sync.init();
  if (m === 'unlocked' || m === 'readonly') { settings.load(); if (old !== 'loading') sync.syncNow(); }
  if (m === 'unlocked') upd.startupCheck(); // still, hoechstens 1x/Tag
});
watch(() => sync.conflicts, (n, o) => {
  if (n > o) f7.toast.create({ text: t('sync.conflictToast'), closeTimeout: 3500, position: 'center' }).open();
});

api.onAuthProblem((err) => session.handleAuthProblem(err));

onMounted(async () => {
  f7.on('tabShow', (el) => { if (el && el.id) ui.tab = el.id.replace('view-', ''); });
  f7.on('pageAfterIn', (page) => {
    const r = page.route && page.route.route;
    const vid = page.view && page.view.el && page.view.el.id;
    if (vid) ui.fabs[vid] = !!(r && r.fab);
  });
  initPwa({ onDeferred: (v) => { ui.updateReady = v; } });
  await data.load();
  await session.init();
});
</script>
