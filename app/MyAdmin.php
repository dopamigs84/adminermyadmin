<?php
declare(strict_types=1);

namespace Adminer;

require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/TableSql.php';
require_once __DIR__ . '/SqlServerCopy.php';
require_once __DIR__ . '/Workspace.php';

/** Database workspace with native Adminer data editing and additional workflows. */
final class MyAdmin extends Adminer
{
    private array $settings;
    private bool $authenticated = false;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function name()
    {
        $label = $this->settings['name'] === 'AdminerMyAdmin' ? 'Adminer<span class="am-brand-accent">MyAdmin</span>' : h($this->settings['name']);
        return '<a class="am-brand" href="' . h($this->url()) . '"><span class="am-mark" aria-hidden="true">▤</span><span>' . $label . '</span></a>';
    }

    public function credentials()
    {
        $credentials = parent::credentials();
        // SQLite has no server password; protect access at the application layer.
        if (DRIVER === 'sqlite') {
            $credentials[2] = '';
        }
        return $credentials;
    }

    public function login($login, $password)
    {
        if (DRIVER !== 'sqlite') {
            $result = parent::login($login, $password);
            $this->authenticated = $result === true;
            return $result;
        }
        $hash = (string) $this->settings['sqlite_password_hash'];
        if ($hash === '') {
            return 'Accesso SQLite disabilitato. Configura sqlite_password_hash sul server per abilitarlo.';
        }
        $this->authenticated = password_verify((string) $password, $hash);
        return $this->authenticated;
    }

    public function connectSsl()
    {
        // Do not pass SQL Server options into MySQL's SSL configuration.
        if (DRIVER !== 'mssql') {
            return null;
        }
        return [
            'Encrypt' => (bool) $this->settings['sqlserver_encrypt'],
            'TrustServerCertificate' => (bool) $this->settings['sqlserver_trust_certificate'],
        ];
    }

    public function css()
    {
        return ['assets/app.css?v=2' => 'light'];
    }

    public function head($dark = null)
    {
        echo '<meta name="application-name" content="', h($this->settings['name']), '">';
        echo '<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">';
        echo script_src('assets/app.js?v=2', true);
        return false;
    }

    public function headers()
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: same-origin');
    }

    public function bodyClass()
    {
        $connected = $this->authenticated;
        echo ' adminer am-app ', ($connected ? 'am-connected' : 'am-login');
        if (isset($_GET['sql']) && !isset($_GET['import'])) {
            echo ' am-sql';
        }
        echo ' am-driver-', h(DRIVER);
    }

    /** Only connection context survives tab changes, never destructive actions. */
    public function url(array $params = []): string
    {
        $context = [];
        foreach (array_merge(array_keys(SqlDriver::$drivers), ['username', 'db', 'ns', 'ext']) as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $context[$key] = (string) $_GET[$key];
            }
        }
        return '?' . http_build_query(array_replace($context, $params), '', '&', PHP_QUERY_RFC3986);
    }

    private function tableNameFromRequest(): string
    {
        foreach (['am_table', 'select', 'table', 'edit', 'create', 'indexes', 'foreign', 'trigger', 'view', 'check'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
                return $_GET[$key];
            }
        }
        return '';
    }

    public function afterConnect()
    {
        if (isset($_GET['am_action'])) {
            (new Workspace($this))->handle(is_string($_GET['am_action']) ? $_GET['am_action'] : '');
        }
    }

    public function homepage()
    {
        if (DB === '' || (support('scheme') && ($_GET['ns'] ?? '') === '')) {
            return parent::homepage();
        }
        (new Workspace($this))->overview();
        return false;
    }

    public function selectSearchPrint(array $where, array $columns, array $indexes)
    {
        $criteria = (array) ($_GET['where'] ?? []);
        $active = (bool) $where || isset($_GET['am_search']);
        Search::render(fields((string) $_GET['select']), $criteria, $active);
    }

    public function selectSearchProcess(array $fields, array $indexes)
    {
        if (($_GET['am_filter'] ?? '') !== '1') {
            return parent::selectSearchProcess($fields, $indexes);
        }
        try {
            if (!is_array($_GET['where'] ?? []) || !is_string($_GET['am_match'] ?? 'all')) {
                throw new \InvalidArgumentException('Criteri di ricerca non validi.');
            }
            return Search::compile($fields, $_GET['where'] ?? [], $_GET['am_match'] ?? 'all');
        } catch (\InvalidArgumentException $error) {
            $this->error .= h($error->getMessage());
            return ['1 = 0'];
        }
    }

    public function selectActionPrint(array $indexes)
    {
        echo '<fieldset><legend>Risultati</legend><button type="submit" class="am-primary">Esegui ricerca</button> <a href="', h($this->url(['select' => (string) $_GET['select']])), '">Rimuovi filtri</a></fieldset>';
    }

    public function selectLinks(array $status, $set = '')
    {
        parent::selectLinks($status, $set);
        $name = (string) $status['Name'];
        echo '<div class="am-table-shortcuts">';
        foreach ([['Cerca nei campi', 'search'], ['Operazioni', 'operations'], ['Trova e sostituisci', 'replace'], ['Mostra CREATE', 'ddl']] as [$label, $action]) {
            if ($action === 'replace' && is_view($status)) {
                continue;
            }
            echo '<a href="', h($this->url(['am_action' => $action, 'am_table' => $name])), '">', $label, '</a>';
        }
        echo '</div>';
    }

    public function tableStructurePrint(array $fields, $status = null)
    {
        $name = (string) ($status['Name'] ?? $_GET['table'] ?? '');
        $view = $status && is_view($status);
        if (!$view) {
            echo '<form method="post" action="', h($this->url(['am_action' => 'columns', 'am_table' => $name])), '">', input_token();
        }
        echo '<div class="scrollable"><table class="am-columns-table"><thead><tr>', ($view ? '' : '<th><input type="checkbox" data-am-check-columns aria-label="Seleziona tutte le colonne"></th>'), '<th>Campo</th><th>Tipo</th><th>NULL</th><th>Predefinito</th><th>Extra</th><th>Azioni</th></tr></thead><tbody>';
        foreach ($fields as $field) {
            $column = $field['field'];
            echo '<tr>', ($view ? '' : '<td><input type="checkbox" name="am_columns[]" value="' . h($column) . '" aria-label="Seleziona ' . h($column) . '"></td>'), '<th>', h($column), (!empty($field['primary']) ? ' <span title="Chiave primaria">⚿</span>' : ''), '</th><td><code>', h($field['full_type']), '</code></td><td>', (!empty($field['null']) ? 'Sì' : 'No'), '</td><td>', (isset($field['default']) ? '<code>' . h((string) $field['default']) . '</code>' : '<i>—</i>'), '</td><td>', (!empty($field['auto_increment']) ? (DRIVER === 'mssql' ? 'IDENTITY' : 'Auto-increment') : ''), (!empty($field['generated']) ? ' Calcolato' : ''), '</td><td class="am-quick-actions">';
            if (!$view) {
                echo '<a href="', h($this->url(['create' => $name, 'am_column' => $column])), '">Modifica</a> ';
                foreach (['INDEX' => 'Indice', 'UNIQUE' => 'Univoco'] as $mode => $label) {
                    echo '<a href="', h($this->url(['am_action' => 'columns', 'am_table' => $name, 'am_column' => $column, 'am_mode' => $mode])), '">', $label, '</a> ';
                }
                if (DRIVER !== 'sqlite') {
                    echo '<a href="', h($this->url(['am_action' => 'columns', 'am_table' => $name, 'am_column' => $column, 'am_mode' => 'PRIMARY'])), '">Primaria</a> ';
                }
                echo '<a class="am-danger" href="', h($this->url(['am_action' => 'columns', 'am_table' => $name, 'am_column' => $column, 'am_mode' => 'drop'])), '">Elimina</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$view) {
            echo '<div class="am-bulk-bar"><label>Con le colonne selezionate <select name="am_column_operation"><option value="INDEX">Crea indice composto</option><option value="UNIQUE">Crea indice univoco composto</option>', (DRIVER !== 'sqlite' ? '<option value="PRIMARY">Crea chiave primaria composta</option>' : ''), '<option value="drop">Elimina colonne</option></select></label><button type="submit">Anteprima</button> <a href="', h($this->url(['create' => $name, 'am_add_column' => '1'])), '">＋ Aggiungi colonna</a></div></form>';
        }
    }

    public function editRowPrint($table, array $fields, $row, $editing)
    {
        $this->selectLinks(table_status1($table));
        if (!empty($_GET['am_clone'])) {
            echo '<div class="message">Copia del record: il salvataggio inserisce una nuova riga. Il valore IDENTITY/auto-increment viene generato dal database; controlla eventuali altri campi univoci.</div>';
        }
    }

    public function rowActions(string $table, array $row, array $indexes): string
    {
        $key = unique_array($row, $indexes);
        if (!$key || in_array(null, $key, true)) {
            return '';
        }
        $nativeKey = [];
        foreach ($key as $column => $value) {
            $nativeKey[bracket_escape((string) $column)] = $value;
        }
        return ' <a class="am-row-copy" href="' . h($this->url(['edit' => $table, 'where' => $nativeKey, 'am_clone' => '1'])) . '">Copia</a> <a class="am-row-delete am-danger" href="' . h($this->url(['am_action' => 'delete-row', 'am_table' => $table, 'where' => $key])) . '">Elimina</a>';
    }

    public function loginForm()
    {
        echo '<div class="am-login-heading"><span class="am-eyebrow">DATABASE WORKSPACE</span><h1>I tuoi database.<br>Un posto solo.</h1><p>Accedi al server e inizia a lavorare.</p></div>';
        $drivers = ['mssql' => 'Microsoft SQL Server', 'server' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];
        $selected = isset($_GET['username']) || isset($_GET['mssql']) ? DRIVER : 'mssql';
        echo '<div class="am-login-fields"><label for="am-driver">Sistema</label><select id="am-driver" name="auth[driver]">', optionlist($drivers, $selected), '</select>';
        $values = ['server' => SERVER, 'username' => $_GET['username'] ?? '', 'password' => '', 'db' => $_GET['db'] ?? ''];
        foreach (['server' => 'Server', 'username' => 'Nome utente', 'password' => 'Password', 'db' => 'Database'] as $key => $label) {
            echo '<label for="am-', $key, '">', $label, ($key === 'db' ? ' <span class="am-optional">opzionale</span>' : ''), '</label>';
            echo '<input id="am-', $key, '" name="auth[', $key, ']" type="', ($key === 'password' ? 'password' : 'text'), '" value="', h((string) $values[$key]), '"';
            if ($key === 'server') {
                echo ' placeholder="sql.example.it:1433" autofocus spellcheck="false" autocapitalize="off"';
            } elseif ($key === 'username') {
                echo ' autocomplete="username" autocapitalize="off" spellcheck="false"';
            } elseif ($key === 'password') {
                echo ' autocomplete="current-password" required';
            } else {
                echo ' placeholder="Selezionabile anche dopo l’accesso" spellcheck="false"';
            }
            echo '>';
        }
        echo '<div class="am-connection-note" id="am-tls-note"><span aria-hidden="true">◇</span><span>',
            ($this->settings['sqlserver_encrypt'] ? 'Connessione SQL Server cifrata.' : 'Cifratura SQL Server disattivata.'),
            ($this->settings['sqlserver_trust_certificate'] ? ' Certificati autofirmati accettati.' : ' Certificato del server verificato.'), '</span></div>';
        echo '<button type="submit" class="am-primary am-connect">Accedi al server <span aria-hidden="true">→</span></button></div>';
        $native = extension_loaded('sqlsrv') || extension_loaded('pdo_sqlsrv');
        echo '<p class="am-driver-status" id="am-driver-status" data-native="', ($native ? '1' : '0'), '">',
            ($native ? '✓ Driver Microsoft SQL Server disponibile' : (extension_loaded('pdo_dblib') ? 'Driver FreeTDS disponibile. Per gestire TLS installa sqlsrv o pdo_sqlsrv.' : 'Driver SQL Server assente: installa sqlsrv o pdo_sqlsrv sul server PHP.')), '</p>';
        echo '<p class="am-login-caption">Le credenziali restano nella sessione del server.</p>';
    }

    public function navigation($missing)
    {
        echo '<div class="am-sidebar-head">', $this->name(), '<span class="am-edition">DATABASE MANAGER</span></div>';
        if ($missing === 'auth') {
            echo '<div class="am-login-aside"><span class="am-aside-tag">IL TUO NUOVO SPAZIO DI LAVORO</span><h2>La familiarità di phpMyAdmin.<br>La flessibilità di Adminer.</h2><p>Un’interfaccia ordinata per esplorare, interrogare e gestire i tuoi dati.</p><div class="am-login-features"><div><span>01</span><section><h3>SQL Server, in primo piano</h3><p>Database, schemi, tabelle e viste. Con supporto alle connessioni cifrate.</p></section></div><div><span>02</span><section><h3>Tutti gli strumenti, a portata di mano</h3><p>Dati, struttura, query SQL, importazione ed esportazione.</p></section></div><div><span>03</span><section><h3>Leggero. Pronto per Ubuntu.</h3><p>PHP e il tuo database. Nessun servizio applicativo aggiuntivo.</p></section></div></div><div class="am-supported"><span>MICROSOFT SQL SERVER</span><span>MYSQL</span><span>POSTGRESQL</span><span>SQLITE</span></div></div>';
            return;
        }

        echo '<div class="am-server"><span class="am-status-dot"></span><div><strong>', h(SERVER ?: 'localhost'), '</strong><small>', h(get_driver(DRIVER)), '</small></div><span class="am-server-icon" aria-hidden="true">▱</span></div>';
        $tables = [];
        if (DB !== '' && !$missing && ($_GET['ns'] ?? '') !== '') {
            connection()->select_db(DB);
            $tables = table_status('', true);
        } elseif (DB !== '' && !$missing && !support('scheme')) {
            $tables = table_status('', true);
        }
        $this->syntaxHighlighting($tables);
        echo '<div class="am-db-picker">';
        $this->databasesPrint($missing);
        echo '</div>';
        echo '<div class="am-sidebar-tools"><a href="', h($this->url(['sql' => ''])), '"><span aria-hidden="true">›_</span> Console SQL</a><a href="', h($this->url(['import' => ''])), '"><span aria-hidden="true">↥</span> Importa</a></div>';

        echo '<div class="am-tree-heading"><span>ESPLORA', ($tables ? ' <span class="am-count">' . count($tables) . '</span>' : ''), '</span><a href="', h($this->url()), '" title="Aggiorna elenco" aria-label="Aggiorna elenco">↻</a></div>';
        echo '<label class="am-tree-search"><span aria-hidden="true">⌕</span><input type="search" id="am-table-filter" placeholder="Filtra tabelle e viste…" aria-label="Filtra tabelle e viste"><kbd>/</kbd></label>';
        if ($tables) {
            $this->tablesPrint($tables);
        } else {
            echo '<p class="am-tree-empty">', (DB === '' ? 'Scegli un database per esplorare le tabelle.' : (support('scheme') && ($_GET['ns'] ?? '') === '' ? 'Scegli uno schema per esplorare le tabelle.' : 'Nessuna tabella disponibile.')), '</p>';
        }
        echo '<p id="am-filter-empty" hidden>Nessuna corrispondenza.</p>';
        echo '<div class="am-sidebar-bottom"><span>Motore Adminer ', h(VERSION), '</span><a href="', h($this->url(['db' => '', 'ns' => ''])), '">Database ↗</a></div>';
        $this->workspaceBar();
    }

    public function tablesPrint(array $tables)
    {
        $current = $this->tableNameFromRequest();
        echo '<ul id="tables" class="am-table-tree">';
        foreach ($tables as $name => $status) {
            if (!empty($status['partition'])) {
                continue;
            }
            $name = (string) $name;
            $view = is_view($status);
            echo '<li class="am-tree-row', ($current === $name ? ' am-current' : ''), '" data-table="', h($name), '">';
            echo '<a class="am-tree-link" href="', h($this->url(['select' => $name])), '"', ($current === $name ? ' aria-current="page"' : ''), '><span class="am-table-icon" aria-hidden="true">', ($view ? '◫' : '▦'), '</span><span>', h($name), '</span></a>';
            echo '<a class="am-structure-link" href="', h($this->url(['table' => $name])), '" title="Struttura di ', h($name), '" aria-label="Struttura di ', h($name), '">⚙</a>';
            echo '<button class="am-favorite" type="button" aria-pressed="false" aria-label="Preferito: ', h($name), '" title="Aggiungi ai preferiti">☆</button></li>';
        }
        echo '</ul>';
    }

    private function workspaceBar(): void
    {
        $table = $this->tableNameFromRequest();
        echo '<div id="am-workspace-bar"><header class="am-topbar"><button type="button" class="am-menu-toggle" aria-label="Apri navigazione" aria-expanded="false">☰</button><div class="am-context"><span aria-hidden="true">▱</span><a href="', h($this->url(['db' => '', 'ns' => ''])), '">', h(SERVER ?: 'localhost'), '</a>';
        if (DB !== '') {
            echo '<span class="am-context-separator">/</span><a href="', h($this->url()), '">', h(DB), '</a>';
        }
        if (($_GET['ns'] ?? '') !== '') {
            echo '<span class="am-context-separator">/</span><span>', h($_GET['ns']), '</span>';
        }
        if ($table !== '') {
            echo '<span class="am-context-separator">/</span><strong>', h($table), '</strong>';
        }
        echo '</div><div class="am-account"><span class="am-avatar" aria-hidden="true">', h(mb_strtoupper(mb_substr((string) ($_GET['username'] ?? ''), 0, 1))), '</span><span>', h($_GET['username'] ?? ''), '</span></div></header>';
        $tabs = [];
        if ($table !== '') {
            $tabs[] = ['select', 'Sfoglia', '▦', ['select' => $table]];
            $tabs[] = ['table', 'Struttura', '☷', ['table' => $table]];
        } else {
            $tabs[] = ['', DB === '' ? 'Database' : 'Struttura', '▦', []];
        }
        if (support('sql')) {
            $tabs[] = ['sql', 'SQL', '›_', ['sql' => '']];
        }
        if (DB !== '') {
            $tabs[] = ['search', 'Cerca', '⌕', ($table !== '' ? ['am_action' => 'search', 'am_table' => $table] : ['am_action' => 'database-search'])];
        }
        if ($table !== '') {
            $tabs[] = ['edit', 'Inserisci', '＋', ['edit' => $table]];
            $tabs[] = ['operations', 'Operazioni', '⚙', ['am_action' => 'operations', 'am_table' => $table]];
        }
        $tabs[] = ['dump', 'Esporta', '↧', ['dump' => $table]];
        if (support('sql')) {
            $tabs[] = ['import', 'Importa', '↥', ['import' => '']];
        }
        if (DB !== '' && function_exists('Adminer\alter_table') && $table === '') {
            $tabs[] = ['create', 'Nuova tabella', '＋', ['create' => '']];
        }
        if (DB !== '' && $table === '' && support('schema')) {
            $tabs[] = ['schema', 'Relazioni', '◇', ['schema' => '']];
        }
        if (support('processlist') && DB === '') {
            $tabs[] = ['processlist', 'Processi', '☷', ['processlist' => '']];
        }
        $action = '';
        foreach (['sql', 'import', 'dump', 'edit', 'create', 'indexes', 'foreign', 'trigger', 'view', 'table', 'select', 'search', 'schema', 'processlist', 'database', 'privileges'] as $key) {
            if (isset($_GET[$key])) {
                $action = $key;
                break;
            }
        }
        if (isset($_GET['am_search'])) {
            $action = 'search';
        }
        if (isset($_GET['am_action']) && is_string($_GET['am_action'])) {
            $action = match ($_GET['am_action']) {
                'search', 'database-search' => 'search',
                'operations', 'replace', 'ddl', 'delete-row', 'bulk' => 'operations',
                'columns' => 'table',
                default => $action,
            };
        }
        echo '<nav class="am-tabs" aria-label="Strumenti database">';
        foreach ($tabs as [$key, $label, $icon, $params]) {
            $active = $key === $action || ($key === 'table' && in_array($action, ['indexes', 'foreign', 'trigger', 'view'], true));
            echo '<a href="', h($this->url($params)), '"', ($active ? ' class="am-tab-active" aria-current="page"' : ''), '><span aria-hidden="true">', $icon, '</span>', $label, '</a>';
        }
        echo '</nav></div>';
        echo '<footer id="am-workspace-footer"><span>AdminerMyAdmin <span class="am-muted">/</span> ', h(get_driver(DRIVER)), '</span><span>PHP ', h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION), ' <span class="am-footer-dot">·</span> Adminer ', h(VERSION), '</span></footer>';
    }

    public function sqlPrintAfter()
    {
        echo '<span class="am-sql-hint">Ctrl / ⌘ + Invio per eseguire</span>';
        // Native SQL form owns execution and session-scoped query history.
    }
}
