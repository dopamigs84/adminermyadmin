<?php
declare(strict_types=1);

namespace Adminer;

/** Authenticated HTML workflows beyond Adminer's default UI. */
final class Workspace
{
    public function __construct(private MyAdmin $ui) {}

    public function handle(string $action): never
    {
        try {
            if (DB === '') {
                throw new \InvalidArgumentException('Seleziona prima un database.');
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->checkToken();
                if (isset($_POST['am_plan'])) {
                    $this->executePlan($this->string($_POST, 'am_plan'));
                }
            }
            if ($action === 'database-search') {
                $this->databaseSearch();
            }
            if ($action === 'bulk') {
                $operation = $this->string($_POST, 'am_operation');
                $names = $_POST['am_tables'] ?? [];
                foreach (['empty', 'drop'] as $single) {
                    if (isset($_POST['am_single_' . $single])) {
                        $operation = $single;
                        $names = [$this->string($_POST, 'am_single_' . $single)];
                    }
                }
                if (!is_array($names)) {
                    throw new \InvalidArgumentException('Selezione tabelle non valida.');
                }
                if ($operation === 'export') {
                    $this->exportSelected($names);
                }
                $this->confirm(TableSql::plan($operation, $names, $_POST));
            }
            $name = $this->string($_GET, 'am_table');
            if (!array_key_exists($name, tables_list()) || $name === 'sqlite_sequence') {
                throw new \InvalidArgumentException('Tabella o vista non disponibile.');
            }
            if ($action === 'search') {
                $this->search($name);
            }
            if ($action === 'ddl') {
                $this->page('Definizione: ' . $name, function () use ($name) {
                    echo '<p>Definizione SQL generata dai metadati del database.</p><textarea class="am-ddl" readonly rows="22" aria-label="Definizione SQL">', h(create_sql($name, true, 'CREATE') . ';'), '</textarea><p><button type="button" data-am-copy-ddl>Copia SQL</button> <a href="', h($this->ui->url(['sql' => create_sql($name, true, 'CREATE') . ';'])), '">Apri nell’editor SQL</a></p>';
                });
            }
            if ($action === 'delete-row') {
                $this->deleteRow($name);
            }
            if ($action === 'replace') {
                $this->replace($name);
            }
            if ($action === 'columns') {
                $this->columns($name);
            }
            if ($action === 'operations') {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->confirm(TableSql::plan($this->string($_POST, 'am_operation'), [$name], $_POST));
                }
                $this->operations($name);
            }
            throw new \InvalidArgumentException('Pagina non disponibile.');
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            $this->page('Operazione non completata', function () {
                echo '<p><a href="', h($this->ui->url()), '">Torna all’elenco delle tabelle</a></p>';
            }, $error->getMessage());
        }
    }

    private function string(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? $default;
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Parametro non valido: ' . $key);
        }
        return (string) $value;
    }

    private function checkToken(): void
    {
        $token = $this->string($_POST, 'token');
        if (!preg_match('/^\d+:\d+$/D', $token) || !verify_token()) {
            throw new \InvalidArgumentException('Sessione o token di sicurezza non valido. Ricarica la pagina e riprova.');
        }
    }

    private function page(string $title, callable $body, string $error = ''): never
    {
        page_header(h($title), h($error));
        $name = isset($_GET['am_table']) && is_string($_GET['am_table']) ? $_GET['am_table'] : '';
        if ($name !== '' && TableSql::exists($name)) {
            $this->ui->selectLinks(table_status1($name));
        }
        $body();
        page_footer();
        exit;
    }

    private function search(string $name): never
    {
        $this->page('Cerca: ' . $name, function () use ($name) {
            echo '<form action="" method="get" class="am-full-search">';
            hidden_fields_get();
            echo input_hidden('db', DB), input_hidden('select', $name);
            if (isset($_GET['ns'])) {
                echo input_hidden('ns', (string) $_GET['ns']);
            }
            Search::render(fields($name), (array) ($_GET['where'] ?? []));
            echo '<div class="am-search-options"><label>Ordina per <select name="order[0]"><option value="">Nessun ordinamento</option>';
            foreach (fields($name) as $field) {
                echo '<option value="', h($field['field']), '">', h($field['field']), '</option>';
            }
            echo '</select></label><label><input type="checkbox" name="desc[0]" value="1"> Decrescente</label><label>Righe per pagina <input type="number" name="limit" value="50" min="1" max="1000"></label></div><p><button type="submit" class="am-primary">Esegui ricerca</button> <a href="', h($this->ui->url(['select' => $name])), '">Mostra tutte le righe</a></p></form>';
        });
    }

    public function overview(): void
    {
        $tables = table_status('', true);
        echo '<div class="am-overview-heading"><div><strong>', count($tables), ' oggetti</strong><span class="am-muted"> · ', h(DB), (support('scheme') ? ' / ' . h(get_schema()) : ''), '</span></div><div><a class="am-button" href="', h($this->ui->url(['create' => ''])), '">＋ Nuova tabella</a> <a class="am-button" href="', h($this->ui->url(['am_action' => 'database-search'])), '">Cerca nel database</a></div></div>';
        echo '<label class="am-overview-filter">Filtra elenco <input type="search" id="am-overview-filter" placeholder="Nome tabella o vista…"></label>';
        echo '<form method="post" action="', h($this->ui->url(['am_action' => 'bulk'])), '" class="am-overview-form">', input_token();
        echo '<div class="scrollable"><table class="am-overview-table"><thead><tr><th><input type="checkbox" data-am-check-all aria-label="Seleziona tutte le tabelle visibili"></th><th>Tabella</th><th>Azioni</th><th>Righe</th><th>Tipo</th></tr></thead><tbody>';
        foreach ($tables as $name => $status) {
            if ($name === 'sqlite_sequence' || !empty($status['partition'])) {
                continue;
            }
            $view = is_view($status);
            echo '<tr data-am-overview-row="', h((string) $name), '"><td><input type="checkbox" name="am_tables[]" value="', h((string) $name), '" aria-label="Seleziona ', h((string) $name), '"></td><th><a href="', h($this->ui->url(['select' => $name])), '">', h((string) $name), '</a></th><td class="am-quick-actions">';
            foreach ([['Sfoglia', ['select' => $name]], ['Struttura', ['table' => $name]], ['Cerca', ['am_action' => 'search', 'am_table' => $name]], ['Inserisci', ['edit' => $name]], ['Operazioni', ['am_action' => 'operations', 'am_table' => $name]], ['Esporta', ['dump' => $name]]] as [$label, $params]) {
                echo '<a href="', h($this->ui->url($params)), '">', $label, '</a> ';
            }
            if (!$view) {
                echo '<button type="submit" name="am_single_empty" value="', h((string) $name), '" class="am-link-button am-danger">Svuota</button> ';
            }
            echo '<button type="submit" name="am_single_drop" value="', h((string) $name), '" class="am-link-button am-danger">Elimina</button></td><td>', (isset($status['Rows']) ? h((string) $status['Rows']) : '—'), '</td><td>', ($view ? 'Vista' : 'Tabella'), '</td></tr>';
        }
        echo '</tbody></table></div><div class="am-bulk-bar"><span data-am-selected-count>0 selezionate</span><label>Con la selezione <select name="am_operation"><option value="export">Esporta SQL (struttura e dati)</option><option value="empty">Svuota tabelle</option><option value="drop">Elimina tabelle / viste</option></select></label><button type="submit">Continua</button></div><p class="am-muted">I conteggi delle righe, quando disponibili, possono essere stimati. Le azioni di modifica richiedono conferma.</p></form>';
    }

    private function operationForm(string $operation, string $title, string $body, string $button, bool $danger = false): void
    {
        echo '<section class="am-operation-card"><h3>', h($title), '</h3><form method="post" action="">', input_token(), input_hidden('am_operation', $operation), $body, '<p><button type="submit"', ($danger ? ' class="am-danger"' : ''), '>', h($button), '</button></p></form></section>';
    }

    private function operations(string $name): never
    {
        $this->page('Operazioni: ' . $name, function () use ($name) {
            $view = is_view(table_status1($name));
            echo '<div class="am-operation-grid">';
            $this->operationForm('rename', 'Rinomina', '<label>Nuovo nome <input name="new_name" value="' . h($name) . '" required></label>', 'Anteprima rinomina');
            if (!$view) {
                $this->operationForm('copy', 'Copia tabella', '<label>Nome della nuova tabella <input name="new_name" value="' . h(mb_substr($name, 0, 50) . '_copia') . '" required></label><label><input type="checkbox" name="copy_data" value="1" checked> Copia anche i dati</label><p class="am-muted">Colonne, default, vincoli e indici ordinari. Trigger e permessi non vengono copiati.</p>', 'Anteprima copia');
                $this->operationForm('empty', 'Svuota tabella', '<p>Rimuove tutte le righe conservando la struttura.</p><label>Metodo <select name="empty_mode"><option value="truncate">TRUNCATE / azzera contatore</option><option value="delete">DELETE / conserva contatore</option></select></label><p class="am-muted">Le chiavi esterne restano attive. Nessuna disattivazione automatica dei vincoli.</p>', 'Anteprima svuotamento', true);
            }
            if (in_array(DRIVER, ['mssql', 'pgsql'], true)) {
                $options = '';
                foreach (schemas() as $schema) {
                    if ($schema !== get_schema()) {
                        $options .= '<option value="' . h($schema) . '">' . h($schema) . '</option>';
                    }
                }
                if ($options !== '') {
                    $this->operationForm('move', 'Sposta in un altro schema', '<label>Schema di destinazione <select name="target_schema">' . $options . '</select></label>', 'Anteprima spostamento');
                }
            }
            if (DRIVER === 'mssql' && array_filter(fields($name), static fn($field) => !empty($field['auto_increment']))) {
                $this->operationForm('reseed', 'Imposta contatore IDENTITY', '<label>Valore corrente del contatore <input type="number" name="identity_value" value="0" required></label><p class="am-muted">Controlla i valori già presenti per evitare conflitti sui prossimi inserimenti.</p>', 'Anteprima RESEED');
            }
            $this->operationForm('drop', 'Elimina ' . ($view ? 'vista' : 'tabella'), '<p>Elimina definitivamente ' . h($name) . ($view ? '.' : ', la struttura e tutti i dati.') . '</p>', 'Anteprima eliminazione', true);
            echo '<section class="am-operation-card"><h3>Altri strumenti</h3><p><a href="', h($this->ui->url(['am_action' => 'ddl', 'am_table' => $name])), '">Mostra CREATE</a></p>';
            if (!$view) {
                echo '<p><a href="', h($this->ui->url(['am_action' => 'replace', 'am_table' => $name])), '">Trova e sostituisci nei dati</a></p><p><a href="', h($this->ui->url(['indexes' => $name])), '">Gestisci indici e chiavi univoche</a></p><p><a href="', h($this->ui->url(['create' => $name])), '">Modifica colonne</a></p>';
            }
            echo '</section></div>';
        });
    }

    private function context(): array
    {
        return [DRIVER, SERVER, (string) ($_GET['username'] ?? ''), DB, (string) ($_GET['ns'] ?? '')];
    }

    private function confirm(array $plan): never
    {
        $id = bin2hex(random_bytes(24));
        restart_session();
        $plans = array_filter((array) ($_SESSION['am_plans'] ?? []), static fn($saved) => $saved['expires'] > time());
        if (count($plans) >= 20) {
            array_shift($plans);
        }
        $plans[$id] = ['plan' => $plan, 'context' => $this->context(), 'expires' => time() + 600];
        $_SESSION['am_plans'] = $plans;
        stop_session(true);
        $this->page('Conferma operazione', function () use ($plan, $id) {
            echo '<div class="am-confirmation"><p><strong>Oggetti interessati:</strong> ', h(implode(', ', $plan['tables'])), '</p>';
            if (isset($plan['affected'])) {
                echo '<p><strong>Righe interessate al momento dell’anteprima:</strong> ', (int) $plan['affected'], '</p>';
            }
            foreach ($plan['notes'] as $note) {
                echo '<p>', h($note), '</p>';
            }
            if (DRIVER === 'server') {
                echo '<p>MySQL può eseguire commit impliciti per le modifiche strutturali. Un’operazione composta può completarsi solo in parte in caso di errore.</p>';
            }
            echo '<h3>SQL da eseguire</h3><pre class="am-plan-sql">', h(implode(";\n\n", $plan['sql']) . ';'), '</pre><form action="" method="post">', input_token(), input_hidden('am_plan', $id), '<button class="am-primary" type="submit">Conferma ed esegui</button> <a class="am-button" href="', h($this->ui->url()), '">Annulla</a></form><p class="am-muted">Anteprima valida per 10 minuti e utilizzabile una sola volta. I dati possono cambiare tra anteprima e conferma.</p></div>';
        });
    }

    private function executePlan(string $id): never
    {
        restart_session();
        $saved = $_SESSION['am_plans'][$id] ?? null;
        unset($_SESSION['am_plans'][$id]);
        stop_session(true);
        if (!$saved || $saved['expires'] < time() || $saved['context'] !== $this->context()) {
            throw new \InvalidArgumentException('Anteprima scaduta, già utilizzata o riferita a un’altra connessione. Ripeti l’operazione.');
        }
        $plan = $saved['plan'];
        $atomic = DRIVER !== 'server';
        try {
            if ($atomic && !driver()->begin()) {
                throw new \RuntimeException('Impossibile iniziare la transazione.');
            }
            foreach ($plan['sql'] as $sql) {
                if (!connection()->query($sql)) {
                    throw new \RuntimeException((string) connection()->error);
                }
            }
            if ($atomic && !driver()->commit()) {
                throw new \RuntimeException('Commit non riuscito: ' . connection()->error);
            }
        } catch (\Throwable $error) {
            if ($atomic) {
                driver()->rollback();
            }
            foreach ($plan['cleanup'] as $sql) {
                connection()->query($sql);
            }
            throw new \RuntimeException('Il database ha rifiutato l’operazione: ' . $error->getMessage());
        }
        $destination = $plan['destination'] ?? null;
        $url = $destination ? $this->ui->url(array_filter(['table' => $destination['table'], 'ns' => $destination['ns']], static fn($value) => $value !== null)) : $this->ui->url();
        $history = $this->ui->messageQuery(implode(";\n", $plan['sql']), '');
        redirect($url, 'Operazione completata.' . $history);
        exit;
    }

    private function deleteRow(string $name): never
    {
        $fields = fields($name);
        $input = $_GET['where'] ?? [];
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Identificativo record non valido.');
        }
        $key = unique_array($input, indexes($name));
        if (!$key || in_array(null, $key, true)) {
            throw new \InvalidArgumentException('L’eliminazione rapida richiede una chiave primaria o univoca completa e non NULL.');
        }
        $conditions = [];
        foreach ($key as $column => $value) {
            if (!isset($fields[$column]) || !is_scalar($value)) {
                throw new \InvalidArgumentException('Identificativo record non valido.');
            }
            $conditions[] = idf_escape($column) . ' = ' . q((string) $value);
        }
        $sql = 'DELETE FROM ' . TableSql::qualified($name) . ' WHERE ' . implode(' AND ', $conditions);
        $this->confirm(['operation' => 'delete-row', 'tables' => [$name], 'sql' => [$sql], 'cleanup' => [], 'notes' => ['Elimina il record identificato dalla chiave univoca selezionata. I vincoli e i trigger restano attivi.'], 'affected' => 1]);
    }

    private function replace(string $name): never
    {
        $fields = fields($name);
        $textFields = array_filter($fields, static fn($field) => empty($field['generated']) && preg_match('~char|text|clob~i', $field['type']) && isset($field['privileges']['update']));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $column = $this->string($_POST, 'replace_column');
            $from = $this->string($_POST, 'replace_from');
            $to = $this->string($_POST, 'replace_to');
            if (!isset($textFields[$column]) || $from === '') {
                throw new \InvalidArgumentException('Scegli un campo testuale e un testo da trovare non vuoto.');
            }
            $where = Search::compile($fields, (array) ($_POST['where'] ?? []), $this->string($_POST, 'am_match', 'all'));
            $identifier = idf_escape($column);
            $value = DRIVER === 'mssql' ? 'CAST(' . $identifier . ' AS nvarchar(max))' : $identifier;
            $contains = match (DRIVER) {
                'mssql' => 'CHARINDEX(' . q($from) . ', ' . $value . ') > 0',
                'pgsql' => 'strpos(' . $value . ', ' . q($from) . ') > 0',
                'sqlite' => 'instr(' . $value . ', ' . q($from) . ') > 0',
                default => 'LOCATE(' . q($from) . ', ' . $value . ') > 0',
            };
            $where[] = $contains;
            $suffix = ' WHERE ' . implode(' AND ', $where);
            $count = get_val('SELECT COUNT(*) FROM ' . TableSql::qualified($name) . $suffix);
            if ($count === false) {
                throw new \RuntimeException((string) connection()->error);
            }
            $sql = 'UPDATE ' . TableSql::qualified($name) . ' SET ' . $identifier . ' = REPLACE(' . $value . ', ' . q($from) . ', ' . q($to) . ')' . $suffix;
            $this->confirm(['operation' => 'replace', 'tables' => [$name], 'sql' => [$sql], 'cleanup' => [], 'notes' => ['La sostituzione si applica solo ai record che rispettano i filtri e contengono il testo cercato. Maiuscole e minuscole seguono le regole del database.'], 'affected' => (int) $count]);
        }
        $this->page('Trova e sostituisci: ' . $name, function () use ($fields, $textFields) {
            if (!$textFields) {
                echo '<p>Nessun campo testuale modificabile.</p>';
                return;
            }
            echo '<form method="post" action="">', input_token(), '<div class="am-replace-controls"><label>Campo <select name="replace_column">';
            foreach ($textFields as $field) {
                echo '<option value="', h($field['field']), '">', h($field['field']), '</option>';
            }
            echo '</select></label><label>Trova <input name="replace_from" required></label><label>Sostituisci con <input name="replace_to"></label></div>';
            Search::render($fields);
            echo '<p><button type="submit">Anteprima sostituzione</button></p></form>';
        });
    }

    private function columns(string $name): never
    {
        if (is_view(table_status1($name))) {
            throw new \InvalidArgumentException('Le colonne di una vista si modificano cambiandone la definizione SQL.');
        }
        $fields = fields($name);
        $selected = $_POST['am_columns'] ?? (isset($_GET['am_column']) ? [$_GET['am_column']] : []);
        $mode = $_SERVER['REQUEST_METHOD'] === 'POST' ? $this->string($_POST, 'am_column_operation') : $this->string($_GET, 'am_mode', 'INDEX');
        if (!is_array($selected) || !$selected || count($selected) > count($fields) || !in_array($mode, ['INDEX', 'UNIQUE', 'PRIMARY', 'drop'], true)) {
            throw new \InvalidArgumentException('Seleziona le colonne e un’operazione valida.');
        }
        foreach ($selected as $column) {
            if (!is_string($column) || !isset($fields[$column])) {
                throw new \InvalidArgumentException('Colonna non presente nei metadati.');
            }
        }
        $columns = array_values(array_unique($selected));
        $target = TableSql::qualified($name);
        $sql = [];
        if ($mode === 'drop') {
            if (count($columns) >= count($fields)) {
                throw new \InvalidArgumentException('Non è possibile eliminare tutte le colonne della tabella.');
            }
            foreach ($columns as $column) {
                $sql[] = 'ALTER TABLE ' . $target . ' DROP COLUMN ' . idf_escape($column);
            }
        } else {
            $index = idf_escape('am_' . bin2hex(random_bytes(8)));
            $list = '(' . implode(', ', array_map('Adminer\\idf_escape', $columns)) . ')';
            if ($mode === 'PRIMARY') {
                if (DRIVER === 'sqlite' || array_filter(indexes($name), static fn($index) => $index['type'] === 'PRIMARY')) {
                    throw new \InvalidArgumentException('La tabella ha già una chiave primaria oppure il database richiede la ricostruzione della tabella.');
                }
                $sql[] = 'ALTER TABLE ' . $target . ' ADD CONSTRAINT ' . $index . ' PRIMARY KEY ' . $list;
            } else {
                $sql[] = 'CREATE ' . ($mode === 'UNIQUE' ? 'UNIQUE ' : '') . 'INDEX ' . $index . ' ON ' . $target . ' ' . $list;
            }
        }
        $this->confirm(['operation' => 'columns', 'tables' => [$name], 'sql' => $sql, 'cleanup' => [], 'notes' => [$mode === 'drop' ? 'Le colonne selezionate e i loro dati vengono eliminati. I vincoli che dipendono dalle colonne possono impedire l’operazione.' : 'Le colonne seguono l’ordine selezionato. Il database verifica tipi, duplicati e limiti degli indici.'], 'destination' => ['table' => $name, 'ns' => in_array(DRIVER, ['mssql', 'pgsql'], true) ? get_schema() : null]]);
    }

    private function exportSelected(array $names): never
    {
        $available = tables_list();
        if (!$names || count($names) > 100) {
            throw new \InvalidArgumentException('Seleziona da 1 a 100 tabelle o viste.');
        }
        foreach ($names as $name) {
            if (!is_string($name) || !array_key_exists($name, $available) || $name === 'sqlite_sequence') {
                throw new \InvalidArgumentException('Selezione non valida.');
            }
        }
        $_POST['format'] = 'sql';
        $_POST['auto_increment'] = '1';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="tabelle-' . date('Ymd-His') . '.sql"');
        header('Cache-Control: no-store');
        echo '-- AdminerMyAdmin / ', str_replace(["\n", "\r"], ' ', DB), "\n\n";
        $views = [];
        foreach (array_unique($names) as $name) {
            if (is_view(table_status1($name))) {
                $views[] = $name;
                continue;
            }
            $this->ui->dumpTable($name, 'CREATE');
            $this->ui->dumpData($name, 'INSERT', 'SELECT * FROM ' . TableSql::qualified($name));
        }
        foreach ($views as $name) {
            echo create_sql($name, true, 'CREATE'), ";\n\n";
        }
        exit;
    }

    private function databaseSearch(): never
    {
        $available = tables_list();
        $results = [];
        $needle = '';
        $selected = [];
        $mode = 'CONTAINS';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $needle = $this->string($_POST, 'needle');
            $mode = $this->string($_POST, 'search_mode', 'CONTAINS');
            $selected = $_POST['am_tables'] ?? [];
            if ($needle === '' || !is_array($selected) || !$selected || count($selected) > 100 || !in_array($mode, ['CONTAINS', '='], true)) {
                throw new \InvalidArgumentException('Inserisci un testo e seleziona da 1 a 100 tabelle o viste.');
            }
            foreach (array_unique($selected) as $name) {
                if (!is_string($name) || !array_key_exists($name, $available)) {
                    throw new \InvalidArgumentException('Selezione non valida.');
                }
                $criteria = [];
                $fields = fields($name);
                foreach ($fields as $field) {
                    if (preg_match('~char|text|clob|uuid|xml|json~i', $field['type']) && isset($field['privileges']['where'])) {
                        $criteria[] = ['col' => $field['field'], 'op' => $mode, 'val' => $needle];
                    }
                }
                if (!$criteria) {
                    $results[] = [$name, null, null];
                    continue;
                }
                $where = Search::compile($fields, $criteria, 'any');
                $count = get_val('SELECT COUNT(*) FROM ' . TableSql::qualified($name) . ' WHERE ' . implode(' AND ', $where));
                $results[] = [$name, $count, $this->ui->url(['select' => $name, 'where' => $criteria, 'am_filter' => '1', 'am_match' => 'any'])];
            }
        }
        $this->page('Cerca nel database', function () use ($available, $results, $needle, $selected, $mode) {
            echo '<p>Cerca lo stesso valore nei campi testuali delle tabelle e viste selezionate dello schema corrente.</p><form method="post" action="">', input_token(), '<div class="am-replace-controls"><label>Testo da cercare <input name="needle" value="', h($needle), '" required></label><label>Confronto <select name="search_mode">', optionlist(['CONTAINS' => 'Contiene', '=' => 'Corrispondenza esatta'], $mode), '</select></label></div><fieldset><legend>Tabelle e viste</legend><label><input type="checkbox" data-am-check-all> Seleziona tutte</label><div class="am-search-tables">';
            foreach ($available as $name => $type) {
                if ($name !== 'sqlite_sequence') {
                    echo '<label><input type="checkbox" name="am_tables[]" value="', h((string) $name), '"', (in_array($name, $selected, true) ? ' checked' : ''), '> ', h((string) $name), '</label>';
                }
            }
            echo '</div></fieldset><button type="submit" class="am-primary">Cerca nelle tabelle selezionate</button></form>';
            if ($results) {
                echo '<h3>Risultati</h3><table><thead><tr><th>Tabella</th><th>Corrispondenze</th><th>Azione</th></tr></thead><tbody>';
                foreach ($results as [$name, $count, $url]) {
                    echo '<tr><th>', h($name), '</th><td>', ($count === null ? 'Nessun campo testuale ricercabile' : ($count === false ? 'Ricerca non riuscita' : (int) $count)), '</td><td>', ($url && $count !== false ? '<a href="' . h($url) . '">Sfoglia risultati</a>' : ''), '</td></tr>';
                }
                echo '</tbody></table>';
            }
        });
    }
}
