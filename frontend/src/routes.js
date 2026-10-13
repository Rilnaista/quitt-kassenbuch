import Home from './pages/Home.vue';
import Ledger from './pages/Ledger.vue';
import Categories from './pages/Categories.vue';
import CategoryDetail from './pages/CategoryDetail.vue';
import BookingDetail from './pages/BookingDetail.vue';
import BookingForm from './pages/BookingForm.vue';
import Trash from './pages/Trash.vue';
import Settings from './pages/Settings.vue';
import About from './pages/About.vue';
import Statement from './pages/Statement.vue';
import Backup from './pages/Backup.vue';
import Update from './pages/Update.vue';
import Diagnose from './pages/Diagnose.vue';
import Help from './pages/Help.vue';
import Placeholder from './pages/Placeholder.vue';
import ReceiptFlow from './pages/ReceiptFlow.vue';
import RecognitionSettings from './pages/RecognitionSettings.vue';

// fab: true -> globaler "+"-Button sichtbar.
// PLATZHALTER (spaetere Schritte): Beleg-Flow, Kontoauszug, Belegerkennung, Sicherung, Update, Diagnose, Hilfe.
const ph = (path, key, icon) => ({ path, component: Placeholder, options: { props: { titleKey: key, icon } } });

export default [
  { path: '/', component: Home, fab: true },
  {
    path: '/buchungen/', component: Ledger, master: true, fab: true,
    detailRoutes: [{ path: '/buchungen/:id/', component: BookingDetail, fab: true }],
  },
  {
    path: '/kategorien/', component: Categories, master: true, fab: true,
    detailRoutes: [{ path: '/kategorien/:name/', component: CategoryDetail, fab: true }],
  },
  { path: '/einstellungen/', component: Settings },
  { path: '/buchung/:id/', component: BookingDetail },
  { path: '/kategorie/:name/', component: CategoryDetail },
  { path: '/neu/ausgabe/', component: BookingForm, options: { props: { kind: 'out' } } },
  { path: '/neu/einnahme/', component: BookingForm, options: { props: { kind: 'in' } } },
  { path: '/bearbeiten/:id/', component: BookingForm },
  { path: '/papierkorb/', component: Trash },
  { path: '/ueber/', component: About },
  { path: '/auszug/', component: Statement },
  { path: '/beleg/', component: ReceiptFlow },
  { path: '/belegerkennung/', component: RecognitionSettings },
  { path: '/sicherung/', component: Backup },
  { path: '/update/', component: Update },
  { path: '/diagnose/', component: Diagnose },
  { path: '/hilfe/', component: Help },
];
