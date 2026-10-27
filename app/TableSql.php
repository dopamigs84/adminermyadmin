<?php
declare(strict_types=1);

namespace Adminer;

/** Build reviewable SQL plans using the active database dialect. */
final class TableSql
{
    public static function identifier(string $value): string
    {
        $limit = DRIVER === 'mssql' ? 128 : (DRIVER === 'pgsql' ? 63 : 64);
        if ($value === '' || str_contains($value, "\0") || mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException("Il nome deve contenere da 1 a $limit caratteri e non può contenere NUL.");
        }
        return $value;
    }

    public static function qualified(string $name, ?string $schema = null): string
    {
        if (in_array(DRIVER, ['mssql', 'pgsql'], true)) {
            return idf_escape($schema ?? get_schema()) . '.' . idf_escape($name);
        }
        return idf_escape($name);
    }

    public static function exists(string $name, ?string $schema = null): bool
    {
        if (DRIVER === 'mssql') {
            return (bool) get_val('SELECT COUNT(*) FROM sys.objects WHERE schema_id = SCHEMA_ID(' . q($schema ?? get_schema()) . ') AND name = ' . q($name) . " AND type IN ('U','V')");
        }
        if (DRIVER === 'pgsql') {
            return (bool) get_val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . q($schema ?? get_schema()) . ' AND table_name = ' . q($name));
        }
        if (DRIVER === 'sqlite') {
            return (bool) get_val("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name = " . q($name));
        }
        return (bool) get_val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . q(DB) . ' AND table_name = ' . q($name));
    }

    public static function plan(string $operation, array $names, array $input): array
    {
        if (!$names || count($names) > 100) {
            throw new \InvalidArgumentException('Seleziona da 1 a 100 tabelle.');
        }
        foreach (['new_name', 'target_schema', 'empty_mode', 'copy_data', 'identity_value'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new \InvalidArgumentException('Parametro non valido: ' . $key);
            }
        }
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('Selezione tabelle non valida.');
            }
        }
        $available = tables_list();
        $names = array_values(array_unique($names));
        foreach ($names as $name) {
            if (!is_string($name) || !array_key_exists($name, $available) || $name === 'sqlite_sequence') {
                throw new \InvalidArgumentException('Tabella o vista non disponibile.');
            }
        }
        $sql = [];
        $cleanup = [];
        $notes = [];
        $destination = null;
        foreach ($names as $name) {
            $status = table_status1($name);
            $view = is_view($status);
            $source = self::qualified($name);
            if ($operation === 'empty') {
                if ($view) {
                    throw new \InvalidArgumentException('Le viste non possono essere svuotate.');
                }
                $delete = ($input['empty_mode'] ?? 'truncate') === 'delete' || DRIVER === 'sqlite';
                $sql[] = ($delete ? 'DELETE FROM ' : 'TRUNCATE TABLE ') . $source;
                if (DRIVER === 'sqlite' && ($input['empty_mode'] ?? 'truncate') === 'truncate' && get_val("SELECT COUNT(*) FROM sqlite_master WHERE name = 'sqlite_sequence'")) {
                    $sql[] = 'DELETE FROM sqlite_sequence WHERE name = ' . q($name);
                }
                $notes[] = $delete ? 'DELETE esegue i trigger DELETE. Il contatore viene conservato, salvo reset esplicito SQLite.' : 'TRUNCATE azzera il contatore identity/auto-increment dove previsto dal database. I vincoli restano attivi e possono impedire l’operazione.';
            } elseif ($operation === 'drop') {
                $sql[] = 'DROP ' . ($view ? 'VIEW ' : 'TABLE ') . $source;
                $notes[] = 'Eliminazione della struttura e dei dati. Nessun CASCADE e nessuna disattivazione delle chiavi esterne.';
            } elseif (in_array($operation, ['rename', 'copy', 'move'], true)) {
                if (count($names) !== 1) {
                    throw new \InvalidArgumentException('Questa operazione richiede una sola tabella.');
                }
                $target = self::identifier(trim((string) ($input['new_name'] ?? $name)));
                $schema = in_array(DRIVER, ['mssql', 'pgsql'], true) ? get_schema() : null;
                if ($operation === 'move') {
                    if (!in_array(DRIVER, ['mssql', 'pgsql'], true)) {
                        throw new \InvalidArgumentException('Spostamento di schema disponibile su SQL Server e PostgreSQL.');
                    }
                    $target = $name;
                    $schema = (string) ($input['target_schema'] ?? '');
                    if (!in_array($schema, schemas(), true) || $schema === get_schema()) {
                        throw new \InvalidArgumentException('Scegli uno schema esistente diverso da quello attuale.');
                    }
                }
                if (self::exists($target, $schema)) {
                    throw new \InvalidArgumentException('Esiste già una tabella o vista con il nome di destinazione.');
                }
                $destination = ['table' => $target, 'ns' => $schema];
                if ($operation === 'rename') {
                    $sql[] = DRIVER === 'mssql'
                        ? 'EXEC sp_rename @objname = ' . q($source) . ', @newname = ' . q($target) . ", @objtype = N'OBJECT'"
                        : (DRIVER === 'server' ? 'RENAME TABLE ' . $source . ' TO ' . self::qualified($target) : 'ALTER ' . ($view ? 'VIEW ' : 'TABLE ') . $source . ' RENAME TO ' . idf_escape($target));
                    $notes[] = 'Le query, le procedure e il codice applicativo che usano il vecchio nome devono essere aggiornati.';
                } elseif ($operation === 'move') {
                    $sql[] = DRIVER === 'mssql' ? 'ALTER SCHEMA ' . idf_escape($schema) . ' TRANSFER ' . $source : 'ALTER ' . ($view ? 'VIEW ' : 'TABLE ') . $source . ' SET SCHEMA ' . idf_escape($schema);
                    $notes[] = 'Verifica i riferimenti applicativi e i permessi sullo schema di destinazione.';
                } else {
                    if ($view) {
                        throw new \InvalidArgumentException('La copia guidata è disponibile per le tabelle. Per le viste usa Mostra CREATE e l’editor SQL.');
                    }
                    [$copySql, $cleanup] = self::copy($name, $target, ($input['copy_data'] ?? '') === '1');
                    array_push($sql, ...$copySql);
                    $notes[] = 'Copia di colonne, valori predefiniti, vincoli e indici ordinari. Trigger e permessi non vengono duplicati. Impostazioni fisiche avanzate richiedono revisione separata.';
                }
            } elseif ($operation === 'reseed') {
                $value = (string) ($input['identity_value'] ?? '');
                if (DRIVER !== 'mssql' || !preg_match('/^-?\d{1,19}$/D', $value) || !array_filter(fields($name), static fn($field) => !empty($field['auto_increment']))) {
                    throw new \InvalidArgumentException('Specificare un intero e una tabella SQL Server con colonna IDENTITY.');
                }
                $sql[] = 'DBCC CHECKIDENT (' . q($source) . ', RESEED, ' . $value . ')';
                $notes[] = 'RESEED imposta il valore corrente. Il prossimo valore dipende dallo stato della tabella e dall’incremento della colonna IDENTITY.';
            } else {
                throw new \InvalidArgumentException('Operazione non riconosciuta.');
            }
        }
        return ['operation' => $operation, 'tables' => $names, 'sql' => $sql, 'cleanup' => $cleanup, 'notes' => array_values(array_unique($notes)), 'destination' => $destination];
    }

    private static function copy(string $name, string $target, bool $data): array
    {
        if (DRIVER === 'mssql') {
            return SqlServerCopy::build($name, $target, $data);
        }
        $source = self::qualified($name);
        $dest = self::qualified($target);
        $sql = [];
        $after = [];
        if (DRIVER === 'sqlite') {
            $ddl = (string) get_val("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . q($name));
            $identifier = '(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|[^\s(]+)';
            $ddl = preg_replace_callback('~^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $identifier . '~i', static fn() => 'CREATE TABLE ' . $dest, $ddl, 1, $count);
            if ($count !== 1) {
                throw new \InvalidArgumentException('Definizione SQLite non riconosciuta; copia interrotta.');
            }
            // Self-referencing foreign keys should follow the copied table.
            $skip = "'(?:[^']|'')*'|\"(?:[^\"]|\"\")*\"|`(?:[^`]|``)*`|\\[[^\\]]*\\]|--[^\\r\\n]*|/\\*[\\s\\S]*?\\*/";
            $ddl = preg_replace_callback('~(' . $skip . ')|\bREFERENCES\s+(' . $identifier . ')~i', static function ($match) use ($name, $dest) {
                if (!empty($match[1])) {
                    return $match[0];
                }
                return strcasecmp(idf_unescape($match[2]), $name) === 0 ? 'REFERENCES ' . $dest : $match[0];
            }, $ddl);
            $sql[] = $ddl;
            foreach (get_rows("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=" . q($name) . ' AND sql IS NOT NULL') as $row) {
                $indexName = idf_escape('am_' . bin2hex(random_bytes(8)));
                $definition = preg_replace_callback('~^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?' . $identifier . '\s+ON\s+' . $identifier . '~i', static fn($match) => $match[1] . $indexName . ' ON ' . $dest, $row['sql'], 1, $count);
                if ($count !== 1) {
                    throw new \InvalidArgumentException('Indice SQLite non riconosciuto; copia interrotta.');
                }
                $after[] = $definition;
            }
        } elseif (DRIVER === 'pgsql') {
            $sql[] = 'CREATE TABLE ' . $dest . ' (LIKE ' . $source . ' INCLUDING ALL)';
            foreach (fields($name) as $field) {
                if (preg_match('~^nextval\(~i', (string) ($field['default'] ?? ''))) {
                    $sequence = self::qualified('am_' . bin2hex(random_bytes(8)) . '_seq');
                    $sql[] = 'CREATE SEQUENCE ' . $sequence;
                    $sql[] = 'ALTER SEQUENCE ' . $sequence . ' OWNED BY ' . $dest . '.' . idf_escape($field['field']);
                    $sql[] = 'ALTER TABLE ' . $dest . ' ALTER COLUMN ' . idf_escape($field['field']) . ' SET DEFAULT nextval(' . q($sequence) . '::regclass)';
                    $after[] = 'SELECT setval(' . q($sequence) . ', COALESCE(MAX(' . idf_escape($field['field']) . '), 1), MAX(' . idf_escape($field['field']) . ') IS NOT NULL) FROM ' . $dest;
                } elseif (!empty($field['auto_increment'])) {
                    $after[] = 'SELECT setval(pg_get_serial_sequence(' . q($dest) . ', ' . q($field['field']) . '), COALESCE(MAX(' . idf_escape($field['field']) . '), 1), MAX(' . idf_escape($field['field']) . ') IS NOT NULL) FROM ' . $dest;
                }
            }
        } else {
            $sql[] = 'CREATE TABLE ' . $dest . ' LIKE ' . $source;
        }
        if ($data) {
            $columns = [];
            foreach (fields($name) as $field) {
                if (empty($field['generated'])) {
                    $columns[] = idf_escape($field['field']);
                }
            }
            $list = implode(', ', $columns);
            $sql[] = 'INSERT INTO ' . $dest . ' (' . $list . ')' . (DRIVER === 'pgsql' ? ' OVERRIDING SYSTEM VALUE' : '') . ' SELECT ' . $list . ' FROM ' . $source;
        }
        array_push($sql, ...$after);
        if (DRIVER !== 'sqlite') {
            foreach (foreign_keys($name) as $key) {
                $ref = $key['table'] === $name && (($key['ns'] ?? get_schema()) === get_schema()) ? $target : $key['table'];
                $refTable = in_array(DRIVER, ['pgsql'], true) ? self::qualified($ref, $key['ns'] ?: get_schema()) : (($key['db'] ?? DB) !== DB ? idf_escape($key['db']) . '.' : '') . idf_escape($ref);
                $sql[] = self::foreignKey($dest, $refTable, $key['source'], $key['target'], $key['on_delete'] ?? 'NO ACTION', $key['on_update'] ?? 'NO ACTION');
            }
        }
        return [$sql, []];
    }

    public static function foreignKey(string $dest, string $ref, array $source, array $target, string $delete, string $update): string
    {
        $sql = 'ALTER TABLE ' . $dest . ' ADD CONSTRAINT ' . idf_escape('am_' . bin2hex(random_bytes(8))) . ' FOREIGN KEY (' . implode(', ', array_map('Adminer\\idf_escape', $source)) . ') REFERENCES ' . $ref . ' (' . implode(', ', array_map('Adminer\\idf_escape', $target)) . ')';
        foreach (['DELETE' => $delete, 'UPDATE' => $update] as $event => $action) {
            $action = str_replace('_', ' ', $action);
            if (!in_array($action, ['NO ACTION', 'CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT'], true)) {
                throw new \InvalidArgumentException('Azione della chiave esterna non supportata.');
            }
            $sql .= ' ON ' . $event . ' ' . $action;
        }
        return $sql;
    }
}
