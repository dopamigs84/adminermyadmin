<?php
declare(strict_types=1);

// Offline metadata fixtures test the SQL generator, not connectivity or SQL execution.
namespace Adminer;

const DRIVER = 'mssql';
const DB = 'Workspace';

function q($value) { return "N'" . str_replace("'", "''", (string) $value) . "'"; }
function idf_escape($value) { return '[' . str_replace(']', ']]', $value) . ']'; }
function get_schema() { return 'sales'; }
function min_version($version) { return version_compare($GLOBALS['server_version'] ?? '16.0', $version, '>='); }
function connection() { return (object) ['error' => '']; }
function get_val($sql) { $GLOBALS['metadata_queries'][] = $sql; return $GLOBALS['advanced_table'] ?? 0; }
function get_rows($sql, $connection = null, $message = '') {
    $GLOBALS['metadata_queries'][] = $sql;
    if (str_contains($sql, 'FROM sys.columns c')) { return $GLOBALS['columns']; }
    if (str_contains($sql, 'FROM sys.indexes i')) { return $GLOBALS['indexes']; }
    if (str_contains($sql, 'FROM sys.check_constraints')) { return [['definition' => '([amount]>=(0))']]; }
    throw new \RuntimeException('Query fixture inattesa: ' . $sql);
}
function foreign_keys($name) {
    return [['table' => 'orders', 'db' => DB, 'ns' => 'sales', 'source' => ['parent_id'], 'target' => ['id'], 'on_delete' => 'NO ACTION', 'on_update' => 'NO ACTION']];
}

require dirname(__DIR__) . '/app/TableSql.php';
require dirname(__DIR__) . '/app/SqlServerCopy.php';

function check($condition, $message): void {
    if (!$condition) { throw new \RuntimeException($message); }
    echo 'PASS ', $message, PHP_EOL;
}

$base = ['type_schema' => 'sys', 'is_user_defined' => 0, 'max_length' => 4, 'precision' => 10, 'scale' => 0, 'is_nullable' => 0, 'is_identity' => 0, 'is_computed' => 0, 'collation_name' => null, 'is_sparse' => 0, 'is_rowguidcol' => 0, 'is_filestream' => 0, 'xml_collection_id' => 0, 'generated_always_type' => 0, 'encryption_type' => null, 'default_definition' => null, 'computed_definition' => null, 'is_persisted' => 0, 'seed_value' => null, 'increment_value' => null];
$GLOBALS['columns'] = [
    ['name' => 'id', 'type_name' => 'int', 'is_identity' => 1, 'seed_value' => '10', 'increment_value' => '5'] + $base,
    ['name' => 'title', 'type_name' => 'nvarchar', 'max_length' => -1, 'collation_name' => 'Latin1_General_CI_AS', 'default_definition' => "(N'draft')"] + $base,
    ['name' => 'amount', 'type_name' => 'decimal', 'precision' => 19, 'scale' => 4] + $base,
    ['name' => 'parent_id', 'type_name' => 'int', 'is_nullable' => 1] + $base,
    ['name' => 'modified', 'type_name' => 'datetime2', 'scale' => 0] + $base,
    ['name' => 'calculated', 'type_name' => 'int', 'is_computed' => 1, 'computed_definition' => '([id]+(1))', 'is_persisted' => 1] + $base,
    ['name' => 'revision', 'type_name' => 'timestamp'] + $base,
];
$indexBase = ['name' => 'index', 'is_unique' => 0, 'is_primary_key' => 0, 'is_unique_constraint' => 0, 'filter_definition' => null, 'key_ordinal' => 1, 'is_included_column' => 0, 'is_descending_key' => 0];
$GLOBALS['indexes'] = [
    ['index_id' => 1, 'type' => 1, 'is_unique' => 1, 'is_primary_key' => 1, 'column_name' => 'id'] + $indexBase,
    ['index_id' => 2, 'type' => 2, 'column_name' => 'amount', 'filter_definition' => '[amount] > 0', 'is_descending_key' => 1] + $indexBase,
    ['index_id' => 2, 'type' => 2, 'column_name' => 'modified', 'key_ordinal' => 0, 'is_included_column' => 1] + $indexBase,
];

[$statements, $cleanup] = SqlServerCopy::build('orders', 'orders_copy', true);
$sql = implode(";\n", $statements);
check(str_contains($sql, '[id] [int] IDENTITY(10,5) NOT NULL'), 'MSSQL: preserva seed e incremento IDENTITY');
check(str_contains($sql, '[nvarchar](max)') && str_contains($sql, "DEFAULT (N'draft')"), 'MSSQL: tipi MAX e valori predefiniti Unicode');
check(str_contains($sql, '[decimal](19,4)') && str_contains($sql, '[datetime2](0)'), 'MSSQL: precisione numerica e temporale');
check(str_contains($sql, '[calculated] AS ([id]+(1)) PERSISTED'), 'MSSQL: colonne calcolate persistenti');
check(str_contains($sql, 'PRIMARY KEY CLUSTERED ([id] ASC)'), 'MSSQL: chiave primaria clustered');
check(str_contains($sql, '([amount] DESC) INCLUDE ([modified]) WHERE [amount] > 0'), 'MSSQL: indici filtrati con colonne incluse e ordinamento');
check(str_contains($sql, 'REFERENCES [sales].[orders_copy] ([id])'), 'MSSQL: chiave esterna autoreferenziale sulla copia');
check(str_contains($sql, 'SET IDENTITY_INSERT [sales].[orders_copy] ON') && count($cleanup) === 1, 'MSSQL: copia valori identity e piano di pulizia');
$insert = current(array_filter($statements, static fn($statement) => str_starts_with($statement, 'INSERT INTO')));
check(!str_contains($insert, '[revision]') && !str_contains($insert, '[calculated]'), 'MSSQL: INSERT esclude rowversion e colonne calcolate');
check(count(array_filter($GLOBALS['metadata_queries'], static fn($query) => str_contains($query, "OBJECT_ID(N'[sales].[orders]')"))) >= 3, 'MSSQL: lettura metadati limitata a tabella e schema');
[$emptyCopy] = SqlServerCopy::build('orders', 'empty_copy', false);
check(!str_contains(implode("\n", $emptyCopy), 'INSERT INTO'), 'MSSQL: copia sola struttura');
[$quoted] = SqlServerCopy::build('orders', 'copy]; DROP TABLE x;--', false);
check(str_starts_with($quoted[0], 'CREATE TABLE [sales].[copy]]; DROP TABLE x;--] ('), 'MSSQL: quoting identificatori di destinazione');
$GLOBALS['server_version'] = '12.0';
$start = count($GLOBALS['metadata_queries']);
SqlServerCopy::build('orders', 'old_copy', false);
$legacy = implode("\n", array_slice($GLOBALS['metadata_queries'], $start));
check(!str_contains($legacy, 'temporal_type') && str_contains($legacy, '0 AS generated_always_type'), 'MSSQL: compatibilità metadati precedenti a SQL Server 2016');
$GLOBALS['advanced_table'] = 1;
try {
    SqlServerCopy::build('orders', 'unsupported', false);
    throw new \RuntimeException('La tabella avanzata non è stata rifiutata.');
} catch (\InvalidArgumentException $error) {
    check(true, 'MSSQL: tabelle avanzate rifiutate prima di generare una copia incompleta');
}
echo 'Test del generatore SQL superati; nessuna connessione SQL Server reale eseguita.', PHP_EOL;
