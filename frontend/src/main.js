import { createApp } from 'vue';
import { createPinia } from 'pinia';
import Framework7 from 'framework7/lite-bundle';
import Framework7Vue, { registerComponents } from 'framework7-vue/bundle';
import 'framework7/css/bundle';
import 'framework7-icons/css/framework7-icons.css';
import './styles.css';
import App from './App.vue';
import { t } from './i18n/index.js';
import * as api from './api/index.js';
import { installClientLogging } from './lib/clientLog.js';

Framework7.use(Framework7Vue);
const app = createApp(App);
app.use(createPinia());
app.config.globalProperties.$t = t;
registerComponents(app);

// Fehler aus der App landen gedrosselt im Server-Log (POST /logs/client).
installClientLogging(app, api.logClient);

app.mount('#app');
