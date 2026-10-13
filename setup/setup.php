<?php
/*
 * Quitt - Einrichtungsassistent (REQ-060). Einzige hochzuladende Datei.
 *
 * Ablauf: Systemcheck -> Art (neu / Sicherung) -> Programm holen (GitHub, lokales quitt-*.zip oder bereits
 * entpackt/Docker) -> Gruppenname, PIN, Belegerkennung ODER Sicherung einspielen -> Wiederherstellungscode
 * -> Abschluss (Datei loescht sich selbst; Docker/Fallback: Sperrdatei data/.setup-lock).
 *
 * Absicherung:
 * - Nach der Installation (PIN gesetzt oder Sperrdatei vorhanden) wird jeder Aufruf verweigert; einzige Ausnahme
 *   ist die Anzeige des Wiederherstellungscodes fuer den Browser, der die Einrichtung gerade durchfuehrt.
 * - Die Einrichtung gehoert dem Browser, der sie gestartet hat (Cookie, nur Hash im Status). Ein zweiter Browser
 *   wird abgewiesen, bis der Vorgang 2 h ruht.
 * - Formulare mit CSRF-Token, Cookie SameSite=Strict. Keine externen Ressourcen, strenge CSP.
 * - Paketeintraege werden vor dem Entpacken geprueft (kein Pfad-Ausbruch, nie data/ oder setup.php).
 * - Jeder Schritt landet im Protokoll data/logs/quitt-YYYY-MM.log (Kanal "setup"), nie PIN/Schluessel/Code.
 *
 * Syntax bewusst PHP-7.x-kompatibel, damit auch zu alte Server eine verstaendliche Meldung sehen.
 * Programmcode der App wird erst ab PHP 8.1 geladen. Lizenz: AGPL-3.0-or-later.
 */

// Quelle der Installationspakete (Forks: hier eigenes Repo eintragen)
const QUITT_REPO = 'Rilnaista/quitt-kassenbuch';
const QUITT_MIN_PHP = '8.1.0';
const QUITT_SETUP_STALE = 7200;
// Eintraege der obersten Ebene eines Pakets (fuer die Rueckfrage vor dem Ueberschreiben, wenn das Paket noch nicht vorliegt)
const QUITT_PACKAGE_NAMES = ['index.html', 'assets', 'sw.js', 'manifest.webmanifest', 'icons', 'offline.html', 'favicon.svg', 'ocr',
    'api', 'app', '.htaccess', 'version.json', 'diagnose.html'];

// Startseiten, die Hoster in neue Ordner legen und die die App verdecken wuerden (Kleinschreibung)
const QUITT_START_PAGES = ['index.htm', 'index.html', 'index.php', 'default.htm', 'default.html', 'index.shtml', 'home.htm', 'home.html'];
// Namen des Release-Layouts, die mit vorhandenen Dateien kollidieren koennen
const QUITT_LAYOUT_NAMES = ['assets', 'api', 'app', 'app-icons', 'sw.js', 'manifest.webmanifest', '.htaccess', 'offline.html', 'diagnose.html', 'version.json'];

error_reporting(E_ALL);
ini_set('display_errors', '0');

$quittSetup = new QuittSetup(__FILE__);
$quittSetup->run();

final class QuittSetupError extends Exception
{
    /** @var string */
    public $help;

    public function __construct($message, $help = '')
    {
        parent::__construct($message);
        $this->help = $help;
    }
}

final class QuittSetup
{
    /** @var string */
    private $file;
    /** @var string */
    private $root;
    /** @var string */
    private $nonce;
    /** @var array|null */
    private $state;
    /** @var array|null Container der App (ab PHP 8.1, nach dem Entpacken) */
    private $app;
    /** @var array */
    private $flash = [];

    public function __construct($file)
    {
        $this->file = $file;
        $this->root = rtrim(str_replace('\\', '/', dirname($file)), '/');
        $this->nonce = bin2hex(random_bytes(12));
    }

    // ------------------------------------------------------------------ Ablauf

    public function run()
    {
        $this->headers();
        try {
            if (is_file($this->lockFile())) {
                $this->refuse('lock');
                return;
            }
            $this->state = $this->loadState();
            $owner = $this->isOwner();
            if ($this->isInstalled() && !($owner && $this->state['step'] === 'code')) {
                $this->refuse('installed');
                return;
            }
            if (isset($_GET['diagnose'])) {
                $this->sendDiagnostics();
                return;
            }
            if ($this->state !== null && !$owner && !$this->isStale()) {
                $this->page('Einrichtung läuft bereits', $this->card(
                    '<p>Quitt wird gerade in einem anderen Browser eingerichtet. Bitte die Einrichtung dort abschließen.</p>'
                    . '<p class="muted">Wurde der Vorgang abgebrochen, ist diese Seite nach zwei Stunden ohne Aktivität wieder frei.</p>'
                ), 409);
                return;
            }
            if (!$owner) {
                $this->state = null;
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->handlePost();
                return;
            }
            $this->render();
        } catch (Throwable $e) {
            $this->log('error', 'setup failed: ' . get_class($e), ['message' => self::maskText($e->getMessage()), 'line' => $e->getLine()]);
            $msg = $e instanceof QuittSetupError ? $e->getMessage() : 'Ein unerwarteter Fehler ist aufgetreten.';
            $help = $e instanceof QuittSetupError ? $e->help : 'Details stehen im Protokoll. Bitte das Diagnosepaket herunterladen und bei der Fehlersuche beilegen.';
            $this->page('Fehler', $this->errorBox($msg, $help)
                . $this->card('<a class="btn" href="' . $this->h($this->selfUrl()) . '">Zurück</a> ' . $this->diagLink()), 500);
        }
    }

    private function handlePost()
    {
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->flash[] = ['err', 'Die Datei ist zu groß für diesen Server (höchstens ' . $this->formatBytes($this->uploadLimit()) . '). '
                . 'Bitte die Upload-Grenze (upload_max_filesize, post_max_size) im Kundenmenü erhöhen.'];
            $this->render();
            return;
        }
        $action = (string) ($_POST['action'] ?? '');
        // Je Schritt nur die dazu passenden Aktionen (kein Ueberspringen, kein Wiederholen nach der Installation)
        $allowed = [
            'check' => ['start', 'cleanup'], 'mode' => ['mode'], 'package' => ['package', 'back', 'cleanup'],
            'install' => ['install', 'test_ai', 'test_ai_json', 'back'], 'restore' => ['restore', 'back'], 'code' => ['finish'],
        ];
        $step = $this->state['step'] ?? 'check';
        if (!in_array($action, $allowed[$step] ?? [], true)
            || ($this->state !== null && !hash_equals($this->csrf(), (string) ($_POST['csrf'] ?? '')))) {
            $this->log('warning', 'setup action rejected', ['action' => substr($action, 0, 20), 'step' => $step]);
            if ($action === 'test_ai_json') {
                $this->json(['ok' => false, 'message' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.']);
                return;
            }
            $this->redirect();
            return;
        }
        $lock = null;
        if ($this->state !== null) {
            $lock = @fopen($this->dataDir() . '/.setup.lock', 'c');
            if ($lock) {
                flock($lock, LOCK_EX);
            }
        }
        try {
            switch ($action) {
                case 'start':
                    $this->actionStart();
                    break;
                case 'mode':
                    $this->actionMode();
                    break;
                case 'cleanup':
                    $this->actionCleanup();
                    break;
                case 'package':
                    $this->actionPackage();
                    break;
                case 'back':
                    $this->state['step'] = 'mode';
                    $this->saveState();
                    $this->redirect();
                    break;
                case 'test_ai':
                    $this->actionTestAi();
                    break;
                case 'test_ai_json':
                    $this->json($this->runAiTest($this->installInput()));
                    break;
                case 'install':
                    $this->actionInstall();
                    break;
                case 'restore':
                    $this->actionRestore();
                    break;
                case 'finish':
                    $this->actionFinish();
                    break;
                default:
                    $this->redirect();
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function render()
    {
        $step = $this->state['step'] ?? 'check';
        switch ($step) {
            case 'mode':
                $this->pageMode();
                break;
            case 'package':
                $this->pagePackage();
                break;
            case 'install':
                $this->pageInstall([]);
                break;
            case 'restore':
                $this->pageRestore();
                break;
            case 'code':
                $this->pageCode();
                break;
            default:
                $this->pageCheck();
        }
    }

    // ------------------------------------------------------------------ Schritte (Aktionen)

    private function actionStart()
    {
        $checks = $this->checks(true);
        if ($this->hasFailures($checks)) {
            $this->redirect();
            return;
        }
        if ($this->state !== null || ($this->loadState() !== null && !$this->isStale())) {
            $this->redirect();
            return;
        }
        $this->ensureDataDir();
        $token = bin2hex(random_bytes(16));
        $this->setCookie($token);
        $_COOKIE['quitt_setup'] = $token;
        $this->state = ['owner' => hash('sha256', $token), 'step' => 'mode', 'mode' => null, 'package_done' => false, 'started_at' => gmdate('c')];
        $this->saveState();
        $summary = [];
        foreach ($checks as $c) {
            $summary[$c['id']] = $c['status'];
        }
        $this->log('info', 'setup started', ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'docker' => $this->isDocker(), 'base_path' => $this->basePath(), 'checks' => $summary]);
        $this->redirect();
    }

    private function actionMode()
    {
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['new', 'restore'], true)) {
            $this->redirect();
            return;
        }
        $this->state['mode'] = $mode;
        $this->state['step'] = $this->state['package_done'] ? ($mode === 'new' ? 'install' : 'restore') : 'package';
        $this->saveState();
        $this->log('info', 'setup mode chosen', ['mode' => $mode]);
        $this->redirect();
    }

    private function actionPackage()
    {
        @set_time_limit(600);
        ignore_user_abort(true);
        $source = $this->packageSource();
        if ($source['kind'] === 'none') {
            throw new QuittSetupError($source['error'], $source['help']);
        }
        if ($source['kind'] !== 'present') {
            if ($this->scanFolder()['collide'] !== []) {
                $this->log('warning', 'setup package blocked: folder not clean');
                $this->flash[] = ['err', 'Im Ordner liegen noch Dateien, die Quitt verdecken oder überschreiben würden. Bitte zuerst „Aufräumen“ wählen.'];
                $this->pagePackage();
                return;
            }
            $conflicts = $this->conflicts($source);
            if ($conflicts !== [] && empty($_POST['overwrite'])) {
                $this->flash[] = ['err', 'Bitte bestätigen, dass die vorhandenen Dateien ersetzt werden dürfen.'];
                $this->pagePackage();
                return;
            }
            $tmpZip = null;
            try {
                if ($source['kind'] === 'github') {
                    $tmpZip = $this->root . '/.quitt-setup-dl-' . bin2hex(random_bytes(6)) . '.zip';
                    $this->downloadRelease($source, $tmpZip);
                    $zip = $tmpZip;
                } else {
                    $zip = $source['file'];
                    $this->verifyLocalChecksum($zip);
                }
                $this->installPackage($zip, $source['version']);
            } finally {
                if ($tmpZip !== null) {
                    @unlink($tmpZip);
                }
            }
        } else {
            $this->log('info', 'setup uses present files', ['version' => $source['version']]);
        }
        $this->state['package_done'] = true;
        $this->state['version'] = $source['version'];
        $this->state['step'] = $this->state['mode'] === 'restore' ? 'restore' : 'install';
        $this->saveState();
        $this->redirect();
    }

    /** Benennt kollidierende Dateien in <name>.vor-quitt um (nichts wird geloescht) und zeigt die Seite erneut. */
    private function actionCleanup()
    {
        $moved = [];
        $failed = [];
        foreach ($this->scanFolder()['collide'] as $name) {
            $to = $name . '.vor-quitt';
            for ($i = 2; file_exists($this->root . '/' . $to) || is_link($this->root . '/' . $to); $i++) {
                $to = $name . '.vor-quitt-' . $i;
            }
            if (@rename($this->root . '/' . $name, $this->root . '/' . $to)) {
                $moved[] = $name;
            } else {
                $failed[] = $name;
            }
        }
        $this->log($failed === [] ? 'info' : 'warning', 'setup cleanup', ['renamed' => $moved, 'failed' => $failed]);
        if ($moved !== []) {
            $this->flash[] = ['ok', 'Umbenannt (Endung „.vor-quitt“, nichts gelöscht): ' . implode(', ', $moved)];
        }
        if ($failed !== []) {
            $this->flash[] = ['err', 'Das Aufräumen war nicht überall möglich (fehlende Rechte): ' . implode(', ', $failed)
                . '. Bitte benenne diese Dateien in deinem FTP-Programm selbst um (z. B. „index.htm“ in „index.htm.vor-quitt“) oder lösche sie, '
                . 'und lade diese Seite danach neu.'];
        }
        $this->render();
    }

    private function runAiTest(array $in)
    {
        try {
            $c = $this->app();
            $result = $c->get('Quitt\\Recognition\\RecognitionService')->test([
                'ai_provider' => $in['ai_provider'],
                'ai_model' => $in['ai_model'],
                'ai_base_url' => $in['ai_base_url'],
                'ai_key' => $in['ai_key'],
            ]);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $this->apiMessage($e)];
        }
        $this->log('info', 'setup recognition test', ['provider' => $in['ai_provider'], 'ok' => (bool) $result['ok']]);
        return ['ok' => (bool) $result['ok'], 'message' => (string) $result['message']];
    }

    /** Fallback ohne JavaScript: Test per Submit; Formular wird ohne PIN wieder befuellt. */
    private function actionTestAi()
    {
        $in = $this->installInput();
        $this->pageInstall($in, $this->runAiTest($in));
    }

    private function json(array $data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function actionInstall()
    {
        $in = $this->installInput();
        $errors = [];
        if ($in['pin'] !== $in['pin2']) {
            $errors['pin2'] = 'Die beiden PINs stimmen nicht überein.';
        }
        $this->assertDataProtected();
        if ($errors === []) {
            try {
                $c = $this->app();
                $c->get('Quitt\\Db\\Migrator')->migrate();
                $patch = ['recognition_mode' => $in['recognition_mode']];
                if ($in['recognition_mode'] === 'ai') {
                    $patch += ['ai_provider' => $in['ai_provider'], 'ai_model' => $in['ai_model'], 'ai_base_url' => $in['ai_base_url'], 'ai_key' => $in['ai_key']];
                }
                $c->get('Quitt\\Service\\SettingsService')->update($patch);
                $code = $c->get('Quitt\\Service\\InstallService')->install($in['group_name'], $in['pin']);
            } catch (Throwable $e) {
                $errors = $this->apiFields($e);
                if ($errors === []) {
                    throw $e;
                }
            }
        }
        if ($errors !== []) {
            $this->log('warning', 'setup install rejected', ['fields' => array_keys($errors)]);
            $this->pageInstall($in, null, $errors);
            return;
        }
        $this->state['step'] = 'code';
        $this->state['code'] = $code;
        $this->saveState();
        $this->log('info', 'setup installed', ['recognition_mode' => $in['recognition_mode'], 'ai_provider' => $in['ai_provider']]);
        $this->redirect();
    }

    private function actionRestore()
    {
        @set_time_limit(600);
        ignore_user_abort(true);
        $this->assertDataProtected();
        $f = $_FILES['file'] ?? null;
        $err = is_array($f) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Die Datei ist zu groß für diesen Server (höchstens ' . $this->formatBytes($this->uploadLimit()) . ').'
                : 'Bitte eine Sicherungsdatei (quitt-sicherung-….zip) auswählen.';
            $this->flash[] = ['err', $msg];
            $this->log('warning', 'setup restore upload failed', ['upload_error' => $err]);
            $this->pageRestore();
            return;
        }
        try {
            $c = $this->app();
            $c->get('Quitt\\Db\\Migrator')->migrate();
            $result = $c->get('Quitt\\Service\\RestoreService')->restoreFile((string) $f['tmp_name']);
        } catch (Throwable $e) {
            $fields = $this->apiFields($e);
            $msg = $fields !== [] ? implode(' ', $fields) : $this->apiMessage($e);
            $this->log('warning', 'setup restore failed', ['error' => get_class($e)]);
            $this->flash[] = ['err', $msg];
            $this->pageRestore();
            return;
        }
        if (!$c->get('Quitt\\Service\\AuthService')->isInstalled()) {
            throw new QuittSetupError('Die Sicherung enthält keine PIN.', 'Bitte eine vollständige Quitt-Sicherung verwenden.');
        }
        $this->state['step'] = 'code';
        $this->state['restored'] = ['entries' => (int) $result['entries'], 'receipts' => (int) $result['receipts']];
        $this->saveState();
        $this->log('info', 'setup restored backup', $this->state['restored']);
        $this->redirect();
    }

    private function actionFinish()
    {
        if (($this->state['step'] ?? '') !== 'code') {
            $this->redirect();
            return;
        }
        $restored = $this->state['restored'] ?? null;
        @file_put_contents($this->lockFile(), json_encode(['locked_at' => gmdate('c'), 'version' => $this->state['version'] ?? null]) . "\n");
        @unlink($this->stateFile());
        @unlink($this->dataDir() . '/.setup.lock');
        foreach (glob($this->root . '/quitt-*.zip*') ?: [] as $pkg) {
            if (preg_match('/^quitt-\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?\.zip(\.sha256)?$/', basename($pkg))) {
                @unlink($pkg);
            }
        }
        $deleted = false;
        if (!$this->isDocker()) {
            $deleted = @unlink($this->file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->file, true);
            }
        }
        $this->setCookie('', true);
        $this->log('info', 'setup finished', ['self_deleted' => $deleted, 'locked' => is_file($this->lockFile())]);
        $this->pageDone($deleted, $restored !== null);
    }

    // ------------------------------------------------------------------ Seiten

    private function pageCheck()
    {
        $checks = $this->checks(true);
        $fail = $this->hasFailures($checks);
        $rows = '';
        foreach ($checks as $c) {
            $rows .= '<li class="check ' . $c['status'] . '"><span class="dot" aria-hidden="true"></span><div><strong>' . $this->h($c['label'])
                . '</strong> <span class="sr">' . ['ok' => 'in Ordnung', 'warn' => 'Hinweis', 'fail' => 'Problem'][$c['status']] . '</span><br>'
                . '<span>' . $this->h($c['detail']) . '</span>'
                . ($c['help'] !== '' && $c['status'] !== 'ok' ? '<p class="help">' . $this->h($c['help']) . '</p>' : '') . '</div></li>';
        }
        $body = $this->card('<h2>Willkommen!</h2><p>Dieser Assistent richtet Quitt in wenigen Schritten ein. Zuerst prüfen wir, ob der Server alles mitbringt.</p>'
            . '<p class="muted">Adresse der App: <strong>' . $this->h($this->appUrl()) . '</strong>'
            . ($this->basePath() !== '' ? ' (Unterordner <code>' . $this->h($this->basePath()) . '</code>)' : '') . '</p>'
            . ($this->isDocker() && $this->presentVersion() !== null ? '<p class="muted">Docker-Installation erkannt: Das Programm ist bereits enthalten.</p>' : ''));
        $body .= $this->card('<h2>Systemcheck</h2><ul class="checks">' . $rows . '</ul>' . $this->cleanupBox());
        if ($fail) {
            $body .= $this->errorBox('Bitte zuerst die rot markierten Punkte beheben.', 'Danach diese Seite neu laden. Bei Fragen hilft das Diagnosepaket deinem Hoster oder Helfer.')
                . $this->card('<a class="btn" href="' . $this->h($this->selfUrl()) . '">Erneut prüfen</a> ' . $this->diagLink());
        } else {
            $body .= $this->card('<form method="post">' . $this->hidden('start') . '<button class="btn" type="submit">Einrichtung starten</button></form>'
                . '<p class="muted small">' . $this->diagLink() . '</p>');
        }
        $this->page('Systemcheck', $body, 200, 1);
    }

    private function pageMode()
    {
        $body = $this->card('<h2>Wie möchtest du starten?</h2>'
            . '<form method="post" class="choices">' . $this->hidden('mode')
            . '<button class="choice" name="mode" value="new" type="submit"><strong>Neu einrichten</strong><span>Leeres Kassenbuch mit eigenem Namen und PIN.</span></button>'
            . '<button class="choice" name="mode" value="restore" type="submit"><strong>Sicherung einspielen</strong><span>Umzug oder Neuanfang mit einer Datei „quitt-sicherung-….zip“.</span></button>'
            . '</form>');
        $this->page('Art der Einrichtung', $body, 200, 2);
    }

    private function pagePackage()
    {
        $source = $this->packageSource();
        if ($source['kind'] === 'none') {
            $body = $this->errorBox($source['error'], $source['help'])
                . $this->card('<a class="btn" href="' . $this->h($this->selfUrl()) . '">Erneut versuchen</a> ' . $this->diagLink());
            $this->page('Programm holen', $body, 200, 3);
            return;
        }
        if ($source['kind'] === 'present') {
            $desc = 'Das Programm (Fassung ' . $this->h($source['version']) . ') liegt bereits in diesem Ordner und wird verwendet.';
        } elseif ($source['kind'] === 'local') {
            $desc = 'Gefunden: <strong>' . $this->h(basename($source['file'])) . '</strong> neben setup.php. Es wird nichts heruntergeladen.'
                . ($source['sha'] ? '' : '<br><span class="muted">Ohne Prüfsummendatei (.sha256) – das Paket wird nicht gegengeprüft.</span>');
        } else {
            $desc = 'Neueste Fassung auf GitHub: <strong>' . $this->h($source['version']) . '</strong>'
                . ($source['prerelease'] ? ' (Vorabversion)' : '') . '. Das Paket wird heruntergeladen und per Prüfsumme kontrolliert.';
        }
        $form = '<form method="post">' . $this->hidden('package');
        $conflicts = $source['kind'] === 'present' ? [] : $this->conflicts($source);
        if ($conflicts !== []) {
            $form .= '<div class="warnbox"><p><strong>In diesem Ordner liegen bereits Dateien, die ersetzt würden:</strong> '
                . $this->h(implode(', ', $conflicts)) . '</p>'
                . '<label class="check-label"><input type="checkbox" name="overwrite" value="1"> Ja, diese Dateien dürfen ersetzt werden.</label></div>';
        }
        $label = $source['kind'] === 'present' ? 'Weiter' : 'Fassung ' . $this->h($source['version']) . ' installieren';
        $form .= '<button class="btn" type="submit" data-busy="Bitte warten …">' . $label . '</button></form>';
        $clean = $source['kind'] === 'present' ? '' : $this->cleanupBox();
        if ($clean !== '') {
            $form = $clean; // erst aufraeumen, dann installieren
        }
        $this->page('Programm holen', $this->card('<h2>Programm holen</h2><p>' . $desc . '</p>' . $form), 200, 3);
    }

    private function pageInstall(array $in, $test = null, array $errors = [])
    {
        $in += ['group_name' => '', 'recognition_mode' => 'local', 'ai_provider' => '', 'ai_model' => '', 'ai_base_url' => '', 'ai_key' => ''];
        $protect = $this->dataProtection();
        $body = $this->protectionNotice($protect);
        if ($protect['status'] === 'fail') {
            $this->page('Einrichten', $body, 200, 4);
            return;
        }
        $providers = [];
        try {
            $providers = $this->app()->get('Quitt\\Service\\AiProviders')->all();
        } catch (Throwable $e) {
            $this->log('warning', 'setup could not load providers', ['error' => get_class($e)]);
        }
        $err = function ($field) use ($errors) {
            return isset($errors[$field]) ? '<span class="field-error">' . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</span>' : '';
        };
        $opts = '';
        foreach ($providers as $p) {
            $opts .= '<option value="' . $this->h($p['id']) . '" data-model="' . $this->h((string) $p['default_model']) . '" data-key="' . ($p['needs_key'] ? '1' : '0')
                . '" data-url="' . ($p['needs_base_url'] ? '1' : '0') . '"' . ($in['ai_provider'] === $p['id'] ? ' selected' : '') . '>' . $this->h($p['name']) . '</option>';
        }
        $mode = function ($v) use ($in) {
            return $in['recognition_mode'] === $v ? ' checked' : '';
        };
        $form = '<form method="post" autocomplete="off">' . $this->hidden('install')
            . '<label>Name der Kasse<input name="group_name" maxlength="100" required value="' . $this->h($in['group_name'] !== '' ? $in['group_name'] : 'Unsere Kasse') . '"></label>' . $err('group_name')
            . '<label>PIN (4 bis 8 Ziffern)<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" autocomplete="new-password" required></label>' . $err('pin')
            . '<label>PIN wiederholen<input name="pin2" type="password" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" autocomplete="new-password" required></label>' . $err('pin2')
            . ($test !== null || $errors !== [] ? '<p class="errbox small" role="alert"><strong>Bitte PIN erneut eingeben.</strong> Aus Sicherheitsgründen wird die PIN nicht wieder eingefüllt.</p>' : '')
            . '<p class="muted small">Mit der PIN wird auf jedem Gerät einmal angemeldet. Die PIN schützt das Bearbeiten der Kasse.</p>'
            . '<fieldset><legend>Belegerkennung (optional)</legend>'
            . '<label class="radio"><input type="radio" name="recognition_mode" value="off"' . $mode('off') . '> Aus</label>'
            . '<label class="radio"><input type="radio" name="recognition_mode" value="local"' . $mode('local') . '> Auf dem Gerät (ohne Internetdienst)</label>'
            . '<label class="radio"><input type="radio" name="recognition_mode" value="ai"' . $mode('ai') . '> KI-Dienst (genauer, Daten gehen an den Anbieter)</label>'
            . $err('recognition_mode')
            . '<div id="ai-fields"' . ($in['recognition_mode'] === 'ai' ? '' : ' class="js-hide"') . '>'
            . '<label>Anbieter<select name="ai_provider" id="ai-provider"><option value="">– bitte wählen –</option>' . $opts . '</select></label>' . $err('ai_provider')
            . '<label id="ai-key-row">Schlüssel (API-Key)<input name="ai_key" type="password" autocomplete="off" value="' . $this->h($in['ai_key']) . '"></label>' . $err('ai_key')
            . '<label>Modell (leer = Standard)<input name="ai_model" id="ai-model" value="' . $this->h($in['ai_model']) . '"></label>' . $err('ai_model')
            . '<label id="ai-url-row">Ollama-Adresse<input name="ai_base_url" placeholder="http://192.168.1.5:11434" value="' . $this->h($in['ai_base_url']) . '"></label>' . $err('ai_base_url')
            . '<button class="btn secondary" type="submit" name="action" value="test_ai" formnovalidate>Verbindung testen</button>'
            . '<p id="ai-test-result" aria-live="polite"' . ($test !== null ? ' class="' . ($test['ok'] ? 'okbox' : 'errbox') . '"' : '') . '>' . ($test !== null ? $this->h($test['message']) : '') . '</p>'
            . '</div></fieldset>'
            . '<button class="btn" type="submit" data-busy="Wird eingerichtet …">Quitt einrichten</button></form>'
            . '<form method="post">' . $this->hidden('back') . '<button class="link" type="submit">Zurück zur Auswahl</button></form>';
        $body .= $this->card('<h2>Richte deine Kasse ein</h2>' . $form);
        $this->page('Einrichten', $body, 200, 4);
    }

    private function pageRestore()
    {
        $protect = $this->dataProtection();
        $body = $this->protectionNotice($protect);
        if ($protect['status'] !== 'fail') {
            $body .= $this->card('<h2>Sicherung einspielen</h2>'
                . '<p>Wähle die Sicherungsdatei <strong>quitt-sicherung-….zip</strong>. Buchungen, Belege und Einstellungen werden übernommen; '
                . 'es gelten danach die PIN und der Wiederherstellungscode aus der Sicherung.</p>'
                . '<form method="post" enctype="multipart/form-data">' . $this->hidden('restore')
                . '<label>Sicherungsdatei<input type="file" name="file" accept=".zip,application/zip" required></label>'
                . '<p class="muted small">Höchstens ' . $this->h($this->formatBytes($this->uploadLimit())) . ' (Grenze dieses Servers).</p>'
                . '<button class="btn" type="submit" data-busy="Wird eingespielt …">Sicherung einspielen</button></form>'
                . '<form method="post">' . $this->hidden('back') . '<button class="link" type="submit">Zurück zur Auswahl</button></form>');
        }
        $this->page('Sicherung einspielen', $body, 200, 4);
    }

    private function pageCode()
    {
        $restored = $this->state['restored'] ?? null;
        if ($restored !== null) {
            $inner = '<h2>Sicherung eingespielt</h2><p>Übernommen: <strong>' . (int) $restored['entries'] . '</strong> Buchungen und <strong>'
                . (int) $restored['receipts'] . '</strong> Belege.</p>'
                . '<p>Melde dich mit der <strong>PIN aus der Sicherung</strong> an. Der Wiederherstellungscode aus der Sicherung gilt weiterhin – '
                . 'falls du ihn nicht mehr hast, kannst du in der App jederzeit einen neuen erzeugen, indem du die PIN änderst.</p>';
        } else {
            $code = (string) ($this->state['code'] ?? '');
            $inner = '<h2>Dein Wiederherstellungscode</h2>'
                . '<p>Mit diesem Code kannst du eine vergessene PIN zurücksetzen. Er wird <strong>nur jetzt</strong> angezeigt.</p>'
                . '<div class="code" aria-label="Wiederherstellungscode">' . $this->h($code) . '</div>'
                . '<p><strong>Bitte aufschreiben oder ausdrucken</strong> und gut aufbewahren – zum Beispiel bei den Kassenunterlagen.</p>'
                . '<p class="noprint"><button class="btn secondary" type="button" id="print">Drucken</button></p>';
        }
        $inner .= '<form method="post" class="noprint">' . $this->hidden('finish')
            . ($restored === null ? '<label class="check-label"><input type="checkbox" required> Ich habe den Code notiert.</label>' : '')
            . '<button class="btn" type="submit">Einrichtung abschließen</button></form>';
        $this->page('Wiederherstellungscode', $this->card($inner), 200, 5);
    }

    private function pageDone($deleted, $restored)
    {
        $url = $this->appUrl();
        $body = $this->card('<h2>Fertig – Quitt ist eingerichtet!</h2>'
            . '<p><a class="btn" href="' . $this->h($url) . '">Quitt öffnen</a></p>'
            . '<p class="muted">Adresse: <strong>' . $this->h($url) . '</strong></p>'
            . ($deleted
                ? '<p class="muted">Der Einrichtungsassistent hat sich aus Sicherheitsgründen gelöscht.</p>'
                : '<p class="muted">Der Einrichtungsassistent ist jetzt gesperrt.' . ($this->isDocker() ? '' : ' Bitte die Datei <code>setup.php</code> zusätzlich per FTP löschen.') . '</p>'));
        $body .= $this->card('<h2>Als App auf das Handy</h2>'
            . '<p><strong>iPhone/iPad (Safari):</strong> Adresse öffnen → Teilen-Symbol → „Zum Home-Bildschirm“.</p>'
            . '<p><strong>Android (Chrome):</strong> Adresse öffnen → Menü ⋮ → „App installieren“ bzw. „Zum Startbildschirm hinzufügen“.</p>'
            . (!$this->isHttps() ? '<p class="warnbox">Ohne HTTPS bieten Handys die Installation als App und die Offline-Nutzung nicht an. Bitte ein Zertifikat aktivieren.</p>' : '')
            . ($restored ? '' : '<p class="muted small">Tipp: Unter Einstellungen → Sicherung regelmäßig eine Sicherung herunterladen.</p>'));
        $this->page('Fertig', $body, 200, 6);
    }

    private function refuse($why)
    {
        $this->log('warning', 'setup refused', ['reason' => $why, 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);
        $this->page('Bereits eingerichtet', $this->card('<h2>Quitt ist bereits eingerichtet</h2>'
            . '<p>Aus Sicherheitsgründen ist dieser Einrichtungsassistent gesperrt und ändert nichts mehr.</p>'
            . '<p><a class="btn" href="' . $this->h($this->appUrl()) . '">Quitt öffnen</a></p>'
            . '<p class="muted small">Die Datei <code>setup.php</code> kann gefahrlos gelöscht werden. Updates gibt es direkt in der App unter Einstellungen.</p>'), 403);
    }

    // ------------------------------------------------------------------ Systemcheck

    /** @return array<int, array{id: string, label: string, status: string, detail: string, help: string}> */
    private function checks($probe)
    {
        $out = [];
        $add = function ($id, $label, $status, $detail, $help = '') use (&$out) {
            $out[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'help' => $help];
        };
        $phpOk = version_compare(PHP_VERSION, QUITT_MIN_PHP, '>=');
        $add('php', 'PHP-Version', $phpOk ? 'ok' : 'fail',
            $phpOk ? 'PHP ' . PHP_VERSION . ' – passt.' : 'PHP ' . PHP_VERSION . ' ist zu alt. Quitt braucht mindestens PHP 8.1.',
            'Im Kundenmenü deines Webhosters die PHP-Version auf 8.1 oder neuer umstellen (meist unter „Domains“ oder „PHP-Einstellungen“). Danach diese Seite neu laden.');
        $exts = [
            'pdo_sqlite' => ['Datenbank (pdo_sqlite)', 'Die PHP-Erweiterung „pdo_sqlite“ fehlt. Bitte im Kundenmenü aktivieren oder beim Hoster nachfragen.'],
            'zip' => ['ZIP-Unterstützung (zip)', 'Die PHP-Erweiterung „zip“ fehlt. Diese Erweiterung wird zum Entpacken und für Sicherungen gebraucht. Bitte beim Hoster aktivieren lassen.'],
            'json' => ['JSON (json)', 'Die PHP-Erweiterung „json“ fehlt. Bitte beim Hoster aktivieren lassen.'],
            'mbstring' => ['Umlaute & Sonderzeichen (mbstring)', 'Die PHP-Erweiterung „mbstring“ fehlt. Bitte im Kundenmenü aktivieren oder beim Hoster nachfragen.'],
        ];
        foreach ($exts as $ext => $t) {
            $ok = extension_loaded($ext);
            $add('ext_' . $ext, $t[0], $ok ? 'ok' : 'fail', $ok ? 'vorhanden' : 'fehlt', $t[1]);
        }
        $net = $this->canHttp();
        $haveLocal = $this->localPackage() !== null || $this->presentVersion() !== null;
        $add('internet', 'Internetzugriff (curl oder allow_url_fopen)', $net ? 'ok' : ($haveLocal ? 'warn' : 'fail'),
            $net ? (function_exists('curl_init') ? 'curl vorhanden' : 'allow_url_fopen aktiv') : 'nicht verfügbar',
            'Ohne Internetzugriff kann sich Quitt nicht selbst herunterladen oder aktualisieren, und die KI-Belegerkennung funktioniert nicht. '
            . 'Abhilfe: curl beim Hoster aktivieren lassen – oder das Paket quitt-X.Y.Z.zip von GitHub laden und neben setup.php hochladen.');

        $writable = is_writable($this->root);
        $dataDir = $this->dataDir();
        $dataOk = is_dir($dataDir) ? is_writable($dataDir) : is_writable(dirname($dataDir));
        $add('writable', 'Schreibrechte', $writable && $dataOk ? 'ok' : 'fail',
            $writable && $dataOk ? 'Ordner ist beschreibbar.' : ($writable ? 'Datenordner ' . $dataDir . ' ist nicht beschreibbar.' : 'Der Ordner von setup.php ist nicht beschreibbar.'),
            'Bitte per FTP-Programm die Rechte des Ordners auf 755 (bei manchen Hostern 775) setzen oder beim Hoster nachfragen.');

        $free = @disk_free_space($this->root);
        if ($free === false) {
            $add('disk', 'Freier Speicher', 'warn', 'unbekannt', 'Der freie Speicher ließ sich nicht ermitteln. Quitt braucht etwa 30 MB plus Platz für Belege und Sicherungen.');
        } else {
            $mb = (int) floor($free / 1048576);
            $add('disk', 'Freier Speicher', $mb < 30 ? 'fail' : ($mb < 100 ? 'warn' : 'ok'), $this->formatBytes((int) $free) . ' frei',
                'Quitt braucht etwa 30 MB plus Platz für Belege und Sicherungen. Bitte Speicher freigeben oder den Tarif erweitern.');
        }

        if ($probe) {
            foreach ($this->htaccessProbe() as $c) {
                $out[] = $c;
            }
        }

        $scan = $this->scanFolder();
        if ($scan['existing']) {
            $add('clean', 'Ordner frei', 'ok', 'Bestehende Quitt-Installation erkannt – es wird nichts ohne Rückfrage überschrieben.');
        } elseif ($scan['collide'] !== []) {
            $add('clean', 'Ordner frei', 'warn', 'Gefunden: ' . implode(', ', $scan['collide']) . '.'
                . ($scan['info'] !== [] ? ' Weitere Ordner/Dateien (bleiben unberührt): ' . implode(', ', $scan['info']) . '.' : ''),
                'Hoster legen oft eine Platzhalter-Startseite (z. B. index.htm) in neue Ordner. Sie würde Quitt verdecken. '
                . 'Mit „Aufräumen“ benennt der Assistent diese Dateien in „<name>.vor-quitt“ um (nichts wird gelöscht). Alternativ kannst du sie per FTP selbst umbenennen.');
        } else {
            $add('clean', 'Ordner frei', 'ok', 'Keine störenden Dateien gefunden.'
                . ($scan['info'] !== [] ? ' Weitere Ordner/Dateien (bleiben unberührt): ' . implode(', ', $scan['info']) . '.' : ''));
        }

        $https = $this->isHttps();
        $add('https', 'Verschlüsselte Verbindung (HTTPS)', $https ? 'ok' : 'warn', $https ? 'Verbindung ist verschlüsselt.' : 'Keine verschlüsselte Verbindung.',
            'Ohne HTTPS werden PIN und Daten unverschlüsselt übertragen, und Handys erlauben weder die Installation als App noch die Offline-Nutzung. '
            . 'Bitte im Kundenmenü ein (meist kostenloses) Let’s-Encrypt-Zertifikat aktivieren und diese Seite mit https:// aufrufen. Du kannst trotzdem fortfahren.');
        return $out;
    }

    /**
     * Sucht im Installationsordner nach Startseiten/Namen, die mit Quitt kollidieren.
     * @return array{existing: bool, collide: string[], info: string[]}
     */
    private function scanFolder()
    {
        $res = ['existing' => $this->presentVersion() !== null || $this->isInstalled(), 'collide' => [], 'info' => []];
        if ($res['existing']) {
            return $res;
        }
        $names = array_unique(array_merge(QUITT_LAYOUT_NAMES, QUITT_PACKAGE_NAMES));
        foreach (array_diff(@scandir($this->root) ?: [], ['.', '..']) as $n) {
            $low = strtolower($n);
            if (in_array($low, QUITT_START_PAGES, true) || in_array($n, $names, true)) {
                $res['collide'][] = $n;
                continue;
            }
            if ($n === basename($this->file) || $n[0] === '.' || $n === 'data' || $n === 'cgi-bin'
                || preg_match('/^quitt-.*\.zip(\.sha256)?$/', $n) || strpos($n, 'quitt-probe-') === 0 || substr($n, -10) === '.vor-quitt'
                || strpos($n, '.vor-quitt-') !== false) {
                continue;
            }
            $res['info'][] = $n . (is_dir($this->root . '/' . $n) ? '/' : '');
        }
        return $res;
    }

    private function cleanupBox()
    {
        $c = $this->scanFolder()['collide'];
        if ($c === []) {
            return '';
        }
        return '<div class="warnbox"><p><strong>Im Ordner liegen Dateien, die Quitt verdecken oder überschreiben würden:</strong> ' . $this->h(implode(', ', $c)) . '</p>'
            . '<p>Mit „Aufräumen“ werden sie in „<em>name</em>.vor-quitt“ umbenannt – nichts wird gelöscht.</p>'
            . '<form method="post">' . $this->hidden('cleanup') . '<button class="btn secondary" type="submit">Aufräumen</button></form></div>';
    }

    private function hasFailures(array $checks)
    {
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                return true;
            }
        }
        return false;
    }

    /**
     * Prueft per Aufruf an den eigenen Server, ob .htaccess + mod_rewrite wirken und gesperrte Ordner gesperrt sind.
     * Legt dazu kurzzeitig einen Testordner an. Ohne Rueckruf-Moeglichkeit: Hinweis statt Fehler.
     */
    private function htaccessProbe()
    {
        $rw = ['id' => 'rewrite', 'label' => '.htaccess und mod_rewrite', 'status' => 'warn', 'detail' => '', 'help' => ''];
        $deny = ['id' => 'protect', 'label' => 'Datenordner nicht öffentlich', 'status' => 'warn', 'detail' => '', 'help' => ''];
        if (PHP_SAPI === 'cli-server') {
            $rw['detail'] = 'Entwicklungsserver (php -S): .htaccess wird hier nicht ausgewertet.';
            $deny['detail'] = 'Entwicklungsserver: Schutz muss der vorgeschaltete Server übernehmen.';
            return [$rw, $deny];
        }
        if ($this->isOutsideRoot($this->dataDir())) {
            $deny['status'] = 'ok';
            $deny['detail'] = 'Der Datenordner liegt außerhalb des Webordners.';
        }
        $unknownHelp = 'Das ist bei manchen Hostern normal. Nach der Einrichtung prüft der Assistent den Datenordner erneut. '
            . 'Falls die App danach keine Verbindung bekommt, bitte beim Hoster mod_rewrite und .htaccess-Dateien („AllowOverride All“) aktivieren lassen.';
        if (!$this->canHttp() || !is_writable($this->root)) {
            $rw['detail'] = 'Konnte nicht automatisch geprüft werden.';
            $rw['help'] = $unknownHelp;
            if ($deny['status'] !== 'ok') {
                $deny['detail'] = 'Konnte nicht automatisch geprüft werden.';
                $deny['help'] = $unknownHelp;
            }
            return [$rw, $deny];
        }
        $dir = $this->root . '/quitt-probe-' . bin2hex(random_bytes(5));
        $tokOk = 'quitt-ok-' . bin2hex(random_bytes(8));
        $tokSecret = 'quitt-geheim-' . bin2hex(random_bytes(8));
        try {
            @mkdir($dir . '/denied', 0775, true);
            file_put_contents($dir . '/ok.txt', $tokOk);
            file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^rw$ ok.txt [L]\n</IfModule>\n"
                . "<IfModule mod_headers.c>\n    Header set X-Quitt-Probe \"1\"\n</IfModule>\n");
            file_put_contents($dir . '/denied/.htaccess', self::denyHtaccess());
            file_put_contents($dir . '/denied/t.txt', $tokSecret);
            $base = $this->basePath() . '/' . basename($dir);
            $plain = $this->loopback($base . '/ok.txt');
            if ($plain === null || $plain['status'] !== 200 || trim($plain['body']) !== $tokOk) {
                $rw['detail'] = 'Konnte nicht automatisch geprüft werden (der Server kann sich selbst nicht aufrufen).';
                $rw['help'] = $unknownHelp;
                if ($deny['status'] !== 'ok') {
                    $deny['detail'] = $rw['detail'];
                    $deny['help'] = $unknownHelp;
                }
                $this->log('info', 'setup probe: loopback unavailable', ['status' => $plain === null ? null : $plain['status']]);
                return [$rw, $deny];
            }
            $r = $this->loopback($base . '/rw');
            if ($r !== null && $r['status'] === 200 && trim($r['body']) === $tokOk) {
                $rw['status'] = 'ok';
                $rw['detail'] = 'wirksam';
            } elseif ($r !== null && $r['status'] >= 500) {
                $rw['status'] = 'fail';
                $rw['detail'] = 'Der Server lehnt Anweisungen in .htaccess-Dateien ab (Fehler ' . $r['status'] . ').';
                $rw['help'] = 'Bitte beim Hoster „AllowOverride All“ für diesen Ordner aktivieren lassen.';
            } else {
                $rw['status'] = 'fail';
                $rw['detail'] = 'mod_rewrite ist nicht aktiv oder .htaccess-Dateien werden ignoriert.';
                $rw['help'] = 'Quitt braucht einen Apache-Webserver mit mod_rewrite und erlaubten .htaccess-Dateien. Bei den meisten Hostern ist das Standard; '
                    . 'sonst bitte beim Hoster nachfragen. Alternative: das Docker-Image verwenden.';
            }
            if ($deny['status'] !== 'ok') {
                $d = $this->loopback($base . '/denied/t.txt');
                if ($d !== null && $d['status'] === 200 && strpos($d['body'], $tokSecret) !== false) {
                    $deny['status'] = 'fail';
                    $deny['detail'] = 'Der Server ignoriert Zugriffssperren – der Datenordner wäre öffentlich abrufbar (Buchungen, Belege, Sicherungen).';
                    $deny['help'] = 'Bitte beim Hoster „AllowOverride All“ aktivieren lassen. Alternativ den Datenordner außerhalb des Webordners ablegen '
                        . '(Umgebungsvariable QUITT_DATA_DIR) oder das Docker-Image verwenden.';
                } else {
                    $deny['status'] = 'ok';
                    $deny['detail'] = 'Gesperrte Ordner sind von außen nicht abrufbar.';
                }
            }
            $this->log('info', 'setup probe', ['rewrite' => $rw['status'], 'protect' => $deny['status']]);
        } finally {
            self::rmrf($dir);
        }
        return [$rw, $deny];
    }

    /** Echter Test am angelegten Datenordner (nach dem Entpacken). @return array{status: string, detail: string, url: string} */
    private function dataProtection()
    {
        $dataDir = $this->dataDir();
        $probeFile = $dataDir . '/schutz-test.txt';
        $url = '';
        if ($this->isOutsideRoot($dataDir)) {
            return ['status' => 'ok', 'detail' => 'Der Datenordner liegt außerhalb des Webordners.', 'url' => ''];
        }
        $rel = substr($dataDir, strlen($this->root));
        $url = $this->origin() . $this->basePath() . $rel . '/schutz-test.txt';
        $token = bin2hex(random_bytes(8));
        @file_put_contents($probeFile, "Wenn du diesen Text im Browser sehen, ist der Quitt-Datenordner NICHT geschützt. ($token)\n");
        if (PHP_SAPI === 'cli-server' || !$this->canHttp()) {
            return ['status' => 'warn', 'detail' => 'Konnte nicht automatisch geprüft werden.', 'url' => $url];
        }
        $r = $this->loopback($this->basePath() . $rel . '/schutz-test.txt');
        if ($r === null) {
            return ['status' => 'warn', 'detail' => 'Konnte nicht automatisch geprüft werden.', 'url' => $url];
        }
        if ($r['status'] === 200 && strpos($r['body'], $token) !== false) {
            $this->log('error', 'setup: data dir is publicly readable');
            return ['status' => 'fail', 'detail' => 'Der Datenordner ist öffentlich abrufbar.', 'url' => $url];
        }
        return ['status' => 'ok', 'detail' => 'Der Datenordner ist gesperrt.', 'url' => $url];
    }

    private function protectionNotice(array $p)
    {
        if ($p['status'] === 'ok') {
            return '';
        }
        if ($p['status'] === 'fail') {
            return $this->errorBox('Der Datenordner ist öffentlich abrufbar – so darf Quitt nicht eingerichtet werden.',
                'Der Server beachtet die Sperre in data/.htaccess nicht. Bitte beim Hoster „AllowOverride All“ aktivieren lassen oder den Datenordner '
                . 'außerhalb des Webordners ablegen (QUITT_DATA_DIR). Danach diese Seite neu laden.')
                . $this->card('<a class="btn" href="' . $this->h($this->selfUrl()) . '">Erneut prüfen</a> ' . $this->diagLink());
        }
        return '<div class="card warnbox"><p><strong>Bitte kurz selbst prüfen:</strong> Öffne <a href="' . $this->h($p['url']) . '" target="_blank" rel="noopener">diese Adresse</a>. '
            . 'Dort muss „Zugriff verweigert“ (Fehler 403) oder „nicht gefunden“ zeigen. Erscheint dort ein Text, ist der Datenordner nicht geschützt – dann bitte nicht fortfahren '
            . 'und beim Hoster „AllowOverride All“ aktivieren lassen.</p></div>';
    }

    private function assertDataProtected()
    {
        if ($this->dataProtection()['status'] === 'fail') {
            throw new QuittSetupError('Der Datenordner ist öffentlich abrufbar.', 'Bitte beim Hoster „AllowOverride All“ aktivieren lassen oder den Datenordner außerhalb des Webordners ablegen.');
        }
    }

    // ------------------------------------------------------------------ Paket

    /** @return array Quelle: kind present|local|github|none */
    private function packageSource()
    {
        $local = $this->localPackage();
        if ($local !== null) {
            return $local;
        }
        $present = $this->presentVersion();
        if ($present !== null) {
            return ['kind' => 'present', 'version' => $present];
        }
        if (!$this->canHttp()) {
            return ['kind' => 'none', 'error' => 'Kein Installationspaket gefunden und kein Internetzugriff.',
                'help' => 'Bitte quitt-X.Y.Z.zip (und quitt-X.Y.Z.zip.sha256) von github.com/' . QUITT_REPO . '/releases laden und neben setup.php hochladen.'];
        }
        $res = $this->http('https://api.github.com/repos/' . QUITT_REPO . '/releases?per_page=30', ['Accept: application/vnd.github+json'], 20);
        $list = $res !== null && $res['status'] === 200 ? json_decode($res['body'], true) : null;
        $best = is_array($list) ? self::pickRelease($list) : null;
        if ($best === null) {
            $this->log('warning', 'setup: no release found', ['status' => $res === null ? null : $res['status']]);
            return ['kind' => 'none', 'error' => 'Die neueste Fassung konnte nicht von GitHub geladen werden.',
                'help' => 'Bitte später erneut versuchen – oder quitt-X.Y.Z.zip (und .sha256) von github.com/' . QUITT_REPO . '/releases laden und neben setup.php hochladen.'];
        }
        return ['kind' => 'github'] + $best;
    }

    /** Hoechstes quitt-X.Y.Z.zip neben setup.php. */
    private function localPackage()
    {
        $best = null;
        foreach (glob($this->root . '/quitt-*.zip') ?: [] as $f) {
            if (preg_match('/^quitt-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\.zip$/', basename($f), $m)
                && ($best === null || version_compare($m[1], $best['version'], '>'))) {
                $best = ['kind' => 'local', 'file' => $f, 'version' => $m[1], 'sha' => is_file($f . '.sha256')];
            }
        }
        return $best;
    }

    /** Bereits entpacktes Programm (Docker oder manuell hochgeladen). */
    private function presentVersion()
    {
        $v = json_decode((string) @file_get_contents($this->root . '/version.json'), true);
        if (is_array($v) && isset($v['version']) && is_file($this->root . '/app/vendor/autoload.php') && is_file($this->root . '/api/index.php')) {
            return (string) $v['version'];
        }
        return null;
    }

    public static function pickRelease(array $list)
    {
        $best = null;
        foreach ($list as $rel) {
            if (!is_array($rel) || !empty($rel['draft'])) {
                continue;
            }
            $version = ltrim((string) ($rel['tag_name'] ?? ''), 'vV');
            if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version)) {
                continue;
            }
            $zip = $sha = null;
            foreach ((array) ($rel['assets'] ?? []) as $a) {
                $url = (string) ($a['browser_download_url'] ?? '');
                if (strpos($url, 'https://') !== 0) {
                    continue;
                }
                if (($a['name'] ?? '') === 'quitt-' . $version . '.zip') {
                    $zip = $url;
                } elseif (($a['name'] ?? '') === 'quitt-' . $version . '.zip.sha256') {
                    $sha = $url;
                }
            }
            if ($zip !== null && $sha !== null && ($best === null || version_compare($version, $best['version'], '>'))) {
                $best = ['version' => $version, 'zip_url' => $zip, 'sha_url' => $sha, 'prerelease' => !empty($rel['prerelease'])];
            }
        }
        return $best;
    }

    private function downloadRelease(array $source, $target)
    {
        $this->log('info', 'setup download started', ['version' => $source['version']]);
        $sha = $this->http($source['sha_url'], [], 30);
        $status = $sha !== null && $sha['status'] === 200 ? $this->httpDownload($source['zip_url'], $target, 600) : null;
        if ($sha === null || $sha['status'] !== 200 || $status !== 200) {
            $this->log('warning', 'setup download failed', ['status' => $status]);
            throw new QuittSetupError('Das Installationspaket konnte nicht heruntergeladen werden.',
                'Bitte später erneut versuchen – oder quitt-' . $source['version'] . '.zip von GitHub laden und neben setup.php hochladen.');
        }
        if (!preg_match('/^\s*([0-9a-fA-F]{64})\b/', $sha['body'], $m) || !hash_equals(strtolower($m[1]), (string) hash_file('sha256', $target))) {
            $this->log('error', 'setup checksum mismatch', ['version' => $source['version']]);
            throw new QuittSetupError('Das heruntergeladene Paket ist beschädigt (Prüfsumme stimmt nicht).', 'Bitte erneut versuchen. Tritt der Fehler wieder auf, das Paket von Hand hochladen.');
        }
        $this->log('info', 'setup download verified', ['version' => $source['version'], 'size' => (int) filesize($target)]);
    }

    private function verifyLocalChecksum($zip)
    {
        if (!is_file($zip . '.sha256')) {
            $this->log('warning', 'setup local package without checksum', ['file' => basename($zip)]);
            return;
        }
        if (!preg_match('/^\s*([0-9a-fA-F]{64})\b/', (string) file_get_contents($zip . '.sha256'), $m)
            || !hash_equals(strtolower($m[1]), (string) hash_file('sha256', $zip))) {
            $this->log('error', 'setup local checksum mismatch', ['file' => basename($zip)]);
            throw new QuittSetupError('Das Paket ' . basename($zip) . ' ist beschädigt (Prüfsumme stimmt nicht).', 'Bitte das Paket erneut hochladen (im Binärmodus übertragen).');
        }
        $this->log('info', 'setup local package verified', ['file' => basename($zip)]);
    }

    /** Pruefen, in Temp-Ordner entpacken, dann Eintrag fuer Eintrag an seinen Platz (mit Rueckweg). */
    private function installPackage($zipPath, $version)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new QuittSetupError('Das Installationspaket ist kein gültiges ZIP.', 'Bitte das Paket erneut herunterladen bzw. hochladen.');
        }
        $stage = $this->root . '/.quitt-setup-' . bin2hex(random_bytes(6));
        $moved = [];
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if (!is_array($st) || !self::isSafeEntry((string) $st['name'])) {
                    throw new QuittSetupError('Das Installationspaket enthält unzulässige Dateinamen.', 'Bitte nur das offizielle Paket von GitHub verwenden.');
                }
                $total += (int) $st['size'];
            }
            if ($total > 300 * 1024 * 1024 || !@mkdir($stage . '/new', 0775, true) || !$zip->extractTo($stage . '/new')) {
                throw new QuittSetupError('Das Installationspaket konnte nicht entpackt werden.', 'Bitte Schreibrechte und freien Speicher prüfen.');
            }
            $zip->close();
            $zip = null;
            $meta = json_decode((string) @file_get_contents($stage . '/new/version.json'), true);
            if (!is_array($meta) || ($meta['version'] ?? null) !== $version || !is_file($stage . '/new/api/index.php') || !is_file($stage . '/new/app/vendor/autoload.php')) {
                throw new QuittSetupError('Das Installationspaket ist unvollständig.', 'Bitte das Paket erneut herunterladen bzw. hochladen.');
            }
            @mkdir($stage . '/old', 0775, true);
            foreach (array_diff(scandir($stage . '/new') ?: [], ['.', '..']) as $name) {
                $target = $this->root . '/' . $name;
                $had = file_exists($target);
                if ($had && !@rename($target, $stage . '/old/' . $name)) {
                    throw new QuittSetupError('Vorhandene Datei „' . $name . '“ lässt sich nicht ersetzen.', 'Bitte Schreibrechte prüfen.');
                }
                if (!@rename($stage . '/new/' . $name, $target)) {
                    if ($had) {
                        @rename($stage . '/old/' . $name, $target);
                    }
                    throw new QuittSetupError('Die Datei „' . $name . '“ konnte nicht angelegt werden.', 'Bitte Schreibrechte prüfen.');
                }
                $moved[] = [$name, $had];
            }
            $moved = [];
            $this->log('info', 'setup package installed', ['version' => $version]);
        } finally {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            foreach (array_reverse($moved) as $m) {
                self::rmrf($this->root . '/' . $m[0]);
                if ($m[1]) {
                    @rename($stage . '/old/' . $m[0], $this->root . '/' . $m[0]);
                }
            }
            self::rmrf($stage);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public static function isSafeEntry($name)
    {
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || strpos($name, ':') !== false || preg_match('/[\x00-\x1f]/', $name)) {
            return false;
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                return false;
            }
        }
        return !in_array($parts[0], ['data', 'setup.php'], true) && strpos($parts[0], '.quitt-') !== 0;
    }

    /** Vorhandene Eintraege, die das Paket ersetzen wuerde (lokales Paket: aus dem ZIP gelesen). @return string[] */
    private function conflicts(array $source)
    {
        $names = QUITT_PACKAGE_NAMES;
        if ($source['kind'] === 'local' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($source['file']) === true) {
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $top = explode('/', (string) $zip->getNameIndex($i))[0];
                    $names[$top] = $top;
                }
                $zip->close();
            }
        }
        $out = [];
        foreach ($names as $n) {
            if (file_exists($this->root . '/' . $n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ App-Anbindung

    /** Container der entpackten App (nur PHP >= 8.1). */
    private function app()
    {
        if ($this->app !== null) {
            return $this->app;
        }
        if (version_compare(PHP_VERSION, QUITT_MIN_PHP, '<') || !is_file($this->root . '/app/vendor/autoload.php')) {
            throw new QuittSetupError('Das Programm ist noch nicht vollständig vorhanden.', 'Bitte den Schritt „Programm holen“ wiederholen.');
        }
        require_once $this->root . '/app/vendor/autoload.php';
        $configClass = 'Quitt\\App\\Config';
        $config = $configClass::fromEnvironment($this->root . '/app', $this->root . '/data');
        $dataDirClass = 'Quitt\\App\\DataDir';
        $dataDirClass::ensure($config->dataDir);
        $bootstrap = 'Quitt\\App\\Bootstrap';
        $c = $bootstrap::container($config);
        $bootstrap::app($c, $this->basePath()); // registriert die Dienste der Module (Erkennung, Wiederherstellung)
        $this->app = $c;
        return $c;
    }

    /** @return array<string, string> Feld => Klartext (aus ApiException::validation) */
    private function apiFields(Throwable $e)
    {
        if (!is_a($e, 'Quitt\\Http\\ApiException') || !isset($e->extra['fields'])) {
            return [];
        }
        $out = [];
        foreach ((array) $e->extra['fields'] as $field => $msg) {
            $out[$field] = $this->t((string) $msg);
        }
        return $out;
    }

    private function apiMessage(Throwable $e)
    {
        if (is_a($e, 'Quitt\\Http\\ApiException')) {
            return $this->t($e->messageKey !== null ? $e->messageKey : 'error.' . $e->errorCode);
        }
        if ($e instanceof QuittSetupError) {
            return $e->getMessage();
        }
        return 'Ein unerwarteter Fehler ist aufgetreten. Details stehen im Protokoll.';
    }

    private function t($key)
    {
        try {
            $lang = $this->app()->get('Quitt\\I18n\\Lang');
            return $lang->has($key) ? $lang->t($key) : $key;
        } catch (Throwable $e) {
            return $key;
        }
    }

    private function installInput()
    {
        $s = function ($k) {
            return trim((string) ($_POST[$k] ?? ''));
        };
        $mode = $s('recognition_mode');
        return [
            'group_name' => $s('group_name'),
            'pin' => (string) ($_POST['pin'] ?? ''),
            'pin2' => (string) ($_POST['pin2'] ?? ''),
            'recognition_mode' => in_array($mode, ['off', 'local', 'ai'], true) ? $mode : 'local',
            'ai_provider' => $s('ai_provider') !== '' ? $s('ai_provider') : null,
            'ai_model' => $s('ai_model'),
            'ai_base_url' => $s('ai_base_url'),
            'ai_key' => $s('ai_key'),
        ];
    }

    private function isInstalled()
    {
        $db = $this->dataDir() . '/quitt.sqlite';
        if (!is_file($db) || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return false;
        }
        try {
            $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'meta'")->fetchColumn()) {
                return false;
            }
            $st = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
            $st->execute(['pin_hash']);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null && $v !== '';
        } catch (Throwable $e) {
            return true; // im Zweifel als installiert betrachten (nichts ueberschreiben)
        }
    }

    // ------------------------------------------------------------------ Status, Cookie, Protokoll

    private function dataDir()
    {
        $env = getenv('QUITT_DATA_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }
        $local = $this->root . '/app/config.local.php';
        if (is_file($local)) {
            $cfg = include $local;
            if (is_array($cfg) && !empty($cfg['data_dir'])) {
                return rtrim(str_replace('\\', '/', (string) $cfg['data_dir']), '/');
            }
        }
        return $this->root . '/data';
    }

    private function lockFile()
    {
        return $this->dataDir() . '/.setup-lock';
    }

    private function stateFile()
    {
        return $this->dataDir() . '/.setup-state.json';
    }

    private function loadState()
    {
        $s = json_decode((string) @file_get_contents($this->stateFile()), true);
        return is_array($s) && isset($s['owner'], $s['step']) ? $s : null;
    }

    private function saveState()
    {
        if (@file_put_contents($this->stateFile(), json_encode($this->state), LOCK_EX) === false) {
            throw new QuittSetupError('Der Datenordner ist nicht beschreibbar.', 'Bitte Schreibrechte prüfen.');
        }
    }

    private function isOwner()
    {
        $cookie = (string) ($_COOKIE['quitt_setup'] ?? '');
        return $this->state !== null && $cookie !== '' && hash_equals((string) $this->state['owner'], hash('sha256', $cookie));
    }

    private function isStale()
    {
        $m = @filemtime($this->stateFile());
        return $m === false || $m < time() - QUITT_SETUP_STALE;
    }

    private function csrf()
    {
        return hash_hmac('sha256', 'csrf', (string) ($_COOKIE['quitt_setup'] ?? ''));
    }

    private function setCookie($value, $delete = false)
    {
        setcookie('quitt_setup', $value, [
            'expires' => $delete ? time() - 3600 : 0,
            'path' => $this->basePath() . '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function ensureDataDir()
    {
        $dir = $this->dataDir();
        foreach (['', '/receipts', '/logs', '/backups'] as $sub) {
            if (!is_dir($dir . $sub) && !@mkdir($dir . $sub, 0775, true) && !is_dir($dir . $sub)) {
                throw new QuittSetupError('Der Datenordner konnte nicht angelegt werden: ' . $dir, 'Bitte Schreibrechte prüfen.');
            }
            if (!is_file($dir . $sub . '/index.php')) {
                @file_put_contents($dir . $sub . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n");
            }
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::denyHtaccess() . "Options -Indexes\n");
        }
    }

    private static function denyHtaccess()
    {
        return "# Quitt-Datenordner: niemals oeffentlich ausliefern\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    }

    /** JSON-Lines im Format des App-Loggers; ohne Datenordner wird nichts geschrieben. */
    private function log($level, $message, array $context = [])
    {
        $dir = $this->dataDir() . '/logs';
        if (!is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'level' => $level,
            'channel' => 'setup',
            'message' => $message,
            'context' => $context === [] ? new stdClass() : $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($dir . '/quitt-' . gmdate('Y-m') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function maskText($text)
    {
        return (string) preg_replace('/\b(sk-[A-Za-z0-9_-]{8,}|[A-Za-z0-9_-]{32,})\b/', '***', (string) $text);
    }

    /** Diagnosepaket (quitt-diagnostics/1) aus Systemcheck und Setup-Protokoll - ohne Geheimnisse. */
    private function sendDiagnostics()
    {
        $checks = [];
        foreach ($this->checks(false) as $c) {
            $checks[] = ['name' => $c['label'], 'ok' => $c['status'] !== 'fail', 'detail' => $c['detail']];
        }
        $logs = [];
        foreach (array_reverse(glob($this->dataDir() . '/logs/quitt-*.log') ?: []) as $f) {
            foreach (array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
                $row = json_decode($l, true);
                if (is_array($row) && ($row['channel'] ?? '') === 'setup') {
                    $logs[] = $row;
                }
                if (count($logs) >= 200) {
                    break 2;
                }
            }
        }
        $mods = [];
        foreach (['pdo_sqlite', 'json', 'mbstring', 'zip', 'curl'] as $m) {
            if (extension_loaded($m)) {
                $mods[] = $m;
            }
        }
        $free = @disk_free_space($this->root);
        $data = [
            'format' => 'quitt-diagnostics/1',
            'meta' => [
                'version' => $this->presentVersion() !== null ? $this->presentVersion() : 'setup',
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'modules' => $mods,
                'base_path' => $this->basePath() . '/',
                'https' => $this->isHttps(),
                'docker' => $this->isDocker(),
                'disk_free' => $free === false ? 0 : (int) $free,
                'db_size' => is_file($this->dataDir() . '/quitt.sqlite') ? (int) filesize($this->dataDir() . '/quitt.sqlite') : 0,
                'counts' => new stdClass(),
                'settings' => new stdClass(),
            ],
            'checks' => $checks,
            'logs' => $logs,
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="quitt-diagnose-setup-' . gmdate('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ------------------------------------------------------------------ Umgebung & HTTP

    private function isDocker()
    {
        return getenv('QUITT_DOCKER') === '1' || is_file('/.dockerenv');
    }

    private function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https' || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';
    }

    /** "" (Domain-Wurzel) oder "/unterordner" - aus dem Ort von setup.php abgeleitet. */
    private function basePath()
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/setup.php')));
        return rtrim($dir === '.' ? '' : $dir, '/');
    }

    private function origin()
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'localhost';
        }
        return ($this->isHttps() ? 'https' : 'http') . '://' . $host;
    }

    private function appUrl()
    {
        return $this->origin() . $this->basePath() . '/';
    }

    private function selfUrl()
    {
        return $this->basePath() . '/' . basename($this->file);
    }

    private function isOutsideRoot($dir)
    {
        $real = realpath($dir);
        $root = realpath($this->root);
        $path = $real !== false ? $real : $dir;
        return $root !== false && strpos(rtrim($path, '/') . '/', rtrim($root, '/') . '/') !== 0;
    }

    private function canHttp()
    {
        return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Aufruf an den eigenen Server: erst ueber den Hostnamen, sonst 127.0.0.1 mit Host-Header. */
    private function loopback($path)
    {
        $r = $this->http($this->origin() . $path, [], 8, false);
        if ($r !== null) {
            return $r;
        }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $port = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $this->http(($https ? 'https' : 'http') . '://127.0.0.1:' . $port . $path, ['Host: ' . $host], 8, false);
    }

    /** @return array{status: int, body: string}|null null = nicht erreichbar */
    private function http($url, array $headers, $timeout, $verify = true)
    {
        $headers[] = 'User-Agent: Quitt-Setup';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => $verify,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            unset($ch);
            return $body === false || $errno !== 0 ? null : ['status' => $status, 'body' => (string) $body];
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => $verify ? 1 : 0],
            'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            return null;
        }
        $meta = stream_get_meta_data($in);
        $body = (string) stream_get_contents($in);
        fclose($in);
        return ['status' => self::statusLine((array) ($meta['wrapper_data'] ?? [])), 'body' => $body];
    }

    private function httpDownload($url, $target, $timeout)
    {
        $headers = ['User-Agent: Quitt-Setup'];
        $out = @fopen($target, 'wb');
        if ($out === false) {
            return null;
        }
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $out, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                ]);
                $ok = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($ch);
                unset($ch);
                return $ok === false || $errno !== 0 ? null : $status;
            }
            $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1]]);
            $in = @fopen($url, 'rb', false, $ctx);
            if ($in === false) {
                return null;
            }
            $meta = stream_get_meta_data($in);
            stream_copy_to_stream($in, $out);
            fclose($in);
            return self::statusLine((array) ($meta['wrapper_data'] ?? []));
        } finally {
            fclose($out);
        }
    }

    /** Status der letzten Antwortzeile (nach Weiterleitungen). */
    private static function statusLine(array $lines)
    {
        $status = 0;
        foreach ($lines as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    private function uploadLimit()
    {
        $toBytes = function ($v) {
            $v = trim((string) $v);
            $n = (float) $v;
            switch (strtolower(substr($v, -1))) {
                case 'g':
                    $n *= 1024;
                    // no break
                case 'm':
                    $n *= 1024;
                    // no break
                case 'k':
                    $n *= 1024;
            }
            return (int) $n;
        };
        $limits = array_filter([$toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size'))]);
        return $limits === [] ? 0 : min($limits);
    }

    private function formatBytes($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
        }
        return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
    }

    private static function rmrf($dir)
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            self::rmrf($dir . '/' . $f);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ HTML

    private function headers()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . $this->nonce . "'; img-src data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Robots-Tag: noindex');
    }

    private function redirect()
    {
        header('Location: ' . $this->selfUrl(), true, 303);
    }

    private function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function hidden($action)
    {
        return '<input type="hidden" name="action" value="' . $this->h($action) . '">'
            . ($this->state !== null ? '<input type="hidden" name="csrf" value="' . $this->h($this->csrf()) . '">' : '');
    }

    private function card($html)
    {
        return '<section class="card">' . $html . '</section>';
    }

    private function errorBox($msg, $help)
    {
        return '<section class="card errbox" role="alert"><p><strong>' . $this->h($msg) . '</strong></p>' . ($help !== '' ? '<p>' . $this->h($help) . '</p>' : '') . '</section>';
    }

    private function diagLink()
    {
        return '<a class="link" href="' . $this->h($this->selfUrl()) . '?diagnose=1">Diagnosepaket herunterladen</a>';
    }

    private function page($title, $body, $status = 200, $step = 0)
    {
        http_response_code($status);
        $flash = '';
        foreach ($this->flash as $f) {
            $flash .= '<section class="card ' . ($f[0] === 'err' ? 'errbox' : 'okbox') . '" role="alert"><p>' . $this->h($f[1]) . '</p></section>';
        }
        $steps = ['Prüfen', 'Art', 'Programm', 'Einrichten', 'Code', 'Fertig'];
        $nav = '';
        if ($step > 0) {
            $nav = '<ol class="steps" aria-label="Fortschritt">';
            foreach ($steps as $i => $s) {
                $nav .= '<li class="' . ($i + 1 < $step ? 'done' : ($i + 1 === $step ? 'now' : '')) . '"' . ($i + 1 === $step ? ' aria-current="step"' : '') . '>' . $s . '</li>';
            }
            $nav .= '</ol>';
        }
        $n = $this->h($this->nonce);
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="robots" content="noindex"><meta name="theme-color" content="#1F8A5B"><title>' . $this->h($title) . ' · Quitt einrichten</title><style>' . self::css() . '</style></head>'
            . '<body><header><div class="logo" aria-hidden="true">Q</div><h1>Quitt einrichten</h1></header><main>' . $nav . $flash . $body . '</main>'
            . '<footer>Quitt · freie Software (AGPL-3.0)</footer>'
            . '<script nonce="' . $n . '">' . self::js() . '</script></body></html>';
    }

    private static function css()
    {
        return ':root{--g:#1F8A5B;--gd:#176B46;--bg:#F4F7F5;--fg:#1B2420;--mu:#5B6B63;--card:#fff;--line:#DCE5E0;--red:#C0392B;--yel:#B7791F}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#121816;--fg:#E8EFEB;--mu:#9DB0A6;--card:#1C2421;--line:#2C3833}}'
            . '*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--fg)}'
            . 'header{background:var(--g);color:#fff;display:flex;align-items:center;gap:.75rem;padding:1rem max(1rem,env(safe-area-inset-left))}'
            . 'header h1{font-size:1.2rem;margin:0}.logo{width:2.2rem;height:2.2rem;border-radius:.6rem;background:#fff;color:var(--g);display:grid;place-items:center;font-weight:800;font-size:1.3rem}'
            . 'main{max-width:40rem;margin:0 auto;padding:1rem}footer{text-align:center;color:var(--mu);font-size:.8rem;padding:1rem 1rem 2rem}'
            . '.card{background:var(--card);border:1px solid var(--line);border-radius:1rem;padding:1rem 1.2rem;margin:0 0 1rem}.card h2{margin:.2rem 0 .6rem;font-size:1.2rem}'
            . '.muted{color:var(--mu)}.small{font-size:.875rem}code{background:var(--bg);padding:.1rem .3rem;border-radius:.3rem}'
            . '.btn{display:inline-block;background:var(--g);color:#fff;border:0;border-radius:.7rem;padding:.8rem 1.3rem;font:inherit;font-weight:600;text-decoration:none;cursor:pointer;min-height:44px}'
            . '.btn:hover{background:var(--gd)}.btn.secondary{background:transparent;color:var(--g);border:2px solid var(--g)}.btn[disabled]{opacity:.6}'
            . '.link{background:none;border:0;color:var(--g);font:inherit;text-decoration:underline;cursor:pointer;padding:.5rem 0}'
            . 'a{color:var(--g)}label{display:block;margin:.8rem 0 .2rem;font-weight:600}'
            . 'input:not([type=radio]):not([type=checkbox]),select{display:block;width:100%;margin-top:.3rem;padding:.7rem;font:inherit;border:1px solid var(--line);border-radius:.6rem;background:var(--card);color:var(--fg);min-height:44px}'
            . 'input:focus,select:focus,button:focus{outline:3px solid rgba(31,138,91,.4);outline-offset:1px}'
            . 'fieldset{border:1px solid var(--line);border-radius:.8rem;margin:1rem 0;padding:.5rem 1rem 1rem}legend{font-weight:700;padding:0 .3rem}'
            . 'label.radio,label.check-label{font-weight:400;display:flex;gap:.6rem;align-items:center}'
            . '.field-error{display:block;color:var(--red);font-size:.9rem}'
            . '.checks{list-style:none;margin:0;padding:0}.check{display:flex;gap:.8rem;padding:.7rem 0;border-top:1px solid var(--line)}.check:first-child{border-top:0}'
            . '.dot{flex:0 0 1rem;height:1rem;border-radius:50%;margin-top:.3rem;background:var(--g)}.check.warn .dot{background:var(--yel)}.check.fail .dot{background:var(--red)}'
            . '.help{margin:.3rem 0 0;color:var(--mu);font-size:.9rem}.sr{position:absolute;left:-9999px}'
            . '.errbox{border-color:var(--red);border-left:6px solid var(--red)}.okbox{border-left:6px solid var(--g);padding:.5rem .8rem}.warnbox{border-left:6px solid var(--yel);padding:.5rem .8rem;margin:.8rem 0}'
            . '.choices{display:grid;gap:.8rem}.choice{text-align:left;background:var(--card);color:var(--fg);border:2px solid var(--line);border-radius:.9rem;padding:1rem;font:inherit;cursor:pointer}'
            . '.choice:hover{border-color:var(--g)}.choice strong{display:block;color:var(--g);font-size:1.1rem}'
            . '.code{font:700 clamp(1.4rem,6vw,2.2rem)/1.3 ui-monospace,"SFMono-Regular",Menlo,Consolas,monospace;letter-spacing:.08em;text-align:center;padding:1.2rem .5rem;margin:1rem 0;border:3px dashed var(--g);border-radius:.8rem;word-break:break-all}'
            . '.steps{display:flex;flex-wrap:wrap;gap:.3rem;list-style:none;padding:0;margin:0 0 1rem;font-size:.8rem;color:var(--mu)}.steps li{padding:.2rem .6rem;border-radius:1rem;background:var(--card);border:1px solid var(--line)}'
            . '.steps li.now{background:var(--g);color:#fff;border-color:var(--g)}.steps li.done{color:var(--g)}'
            . '.js .js-hide{display:none}'
            . '@media print{header,footer,.steps,.noprint,.link{display:none}body{background:#fff;color:#000}.card{border:0}.code{color:#000;border-color:#000}}';
    }

    private static function js()
    {
        return 'document.documentElement.classList.add("js");'
            . 'var p=document.getElementById("print");if(p){p.addEventListener("click",function(){window.print();});}'
            . 'document.querySelectorAll("form").forEach(function(f){f.addEventListener("submit",function(e){var b=e.submitter;if(b&&b.dataset.busy){setTimeout(function(){b.disabled=true;b.textContent=b.dataset.busy;},0);}});});'
            . 'document.querySelectorAll("form").forEach(function(f){f.addEventListener("submit",function(e){var b=e.submitter;if(!b||b.value!=="test_ai"||!window.fetch){return;}e.preventDefault();'
            . 'var out=document.getElementById("ai-test-result"),q=new URLSearchParams(new FormData(f));q.set("action","test_ai_json");var old=b.textContent;b.disabled=true;b.textContent="Wird getestet …";'
            . 'function show(ok,t){out.className=ok?"okbox":"errbox";out.textContent=t;b.disabled=false;b.textContent=old;}'
            . 'fetch(location.href,{method:"POST",body:q,credentials:"same-origin",headers:{"Accept":"application/json"}}).then(function(r){return r.json();})'
            . '.then(function(d){show(!!d.ok,d.message);}).catch(function(){show(false,"Der Test konnte nicht ausgeführt werden. Bitte die Seite neu laden.");});});});'
            . 'var ai=document.getElementById("ai-fields");if(ai){var sel=document.getElementById("ai-provider"),m=document.getElementById("ai-model");'
            . 'function upd(){var mode=document.querySelector("input[name=recognition_mode]:checked");ai.classList.toggle("js-hide",!mode||mode.value!=="ai");'
            . 'var o=sel.options[sel.selectedIndex];document.getElementById("ai-key-row").style.display=o&&o.dataset.key==="0"?"none":"";'
            . 'document.getElementById("ai-url-row").style.display=o&&o.dataset.url==="1"?"":"none";m.placeholder=o&&o.dataset.model?o.dataset.model:"";}'
            . 'document.querySelectorAll("input[name=recognition_mode]").forEach(function(r){r.addEventListener("change",upd);});sel.addEventListener("change",upd);upd();}';
    }
}
