// Hilfe: Themen der Uebersicht und Kontexte der "?"-Knoepfe (Texte: i18n/help.de.json).
export const HELP_TOPICS = ['start', 'book', 'scan', 'recognition', 'categories', 'trash', 'statement', 'offline', 'homescreen', 'https', 'pin', 'readonly', 'backup', 'update', 'diagnose'];
export const HELP_CONTEXTS = ['home', 'ledger', 'categories', 'detail', 'form', 'scan', 'recognition', 'trash', 'statement', 'settings', 'backup', 'update', 'diagnose', 'helpPage'];
export const helpContext = (key) => (HELP_CONTEXTS.includes(key) ? key : 'generic');
