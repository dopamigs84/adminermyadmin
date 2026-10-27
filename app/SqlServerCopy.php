<?php
declare(strict_types=1);

namespace Adminer;

/** SQL Server metadata preserves identity, MAX types, computed columns and indexes. */
final class SqlServerCopy
{
    private static function rows(string $sql): array
    {
        $rows = get_rows($sql, null, '');
        if (connection()->error !== '') {
            throw new \RuntimeException('Impossibile leggere i metadati SQL Server: ' . connection()->error);
        }
        return $rows;
    }

    public static function build(string $sourceName, string $targetName, bool $data): array
    {
        $source = TableSql::qualified($sourceName);
        $target = TableSql::qualified($targetName);
        $object = 'OBJECT_ID(' . q($source) . ')';
        $modern = min_version('13.0');
        $advanced = get_val('SELECT COUNT(*) FROM sys.tables WHERE object_id = ' . $object . ' AND (' . ($modern ? 'temporal_type <> 0 OR ' : '') . (min_version('12.0') ? 'is_memory_optimized = 1 OR ' : '') . (min_version('11.0') ? 'is_filetable = 1 OR ' : '') . '1 = 0)');
        if ($advanced === false) {
            throw new \RuntimeException('Impossibile verificare il tipo della tabella SQL Server.');
        }
        if ($advanced) {
            throw new \InvalidArgumentException('La copia guidata non gestisce tabelle temporali, memory-optimized o FileTable. Usa uno script dedicato.');
        }
        $rows = self::rows('SELECT c.name, t.name AS type_name, SCHEMA_NAME(t.schema_id) AS type_schema, t.is_user_defined, c.max_length, c.precision, c.scale, c.is_nullable, c.is_identity, c.is_computed, c.collation_name, c.is_sparse, c.is_rowguidcol, c.is_filestream, c.xml_collection_id, ' . ($modern ? 'c.generated_always_type, c.encryption_type' : '0 AS generated_always_type, NULL AS encryption_type') . ', d.definition AS default_definition, cc.definition AS computed_definition, cc.is_persisted, ic.seed_value, ic.increment_value
FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints d ON d.object_id = c.default_object_id
LEFT JOIN sys.computed_columns cc ON cc.object_id = c.object_id AND cc.column_id = c.column_id
LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
WHERE c.object_id = ' . $object . ' ORDER BY c.column_id');
        if (!$rows) {
            throw new \InvalidArgumentException('Impossibile leggere la struttura SQL Server.');
        }
        $definitions = [];
        $insertColumns = [];
        $identity = false;
        foreach ($rows as $row) {
            if ($row['is_filestream'] || $row['xml_collection_id'] || $row['generated_always_type'] || $row['encryption_type']) {
                throw new \InvalidArgumentException('La tabella contiene colonne avanzate non gestite dalla copia guidata.');
            }
            $column = idf_escape($row['name']);
            if ($row['is_computed']) {
                $definitions[] = $column . ' AS ' . $row['computed_definition'] . ($row['is_persisted'] ? ' PERSISTED' : '');
                continue;
            }
            $type = $row['type_name'];
            $definition = $column . ' ' . ($row['is_user_defined'] ? idf_escape($row['type_schema']) . '.' : '') . idf_escape($type);
            if (!$row['is_user_defined']) {
                if (preg_match('~^(n?varchar|n?char|varbinary|binary)$~i', $type)) {
                    $length = (int) $row['max_length'];
                    $definition .= '(' . ($length === -1 ? 'max' : (string) ($type[0] === 'n' ? $length / 2 : $length)) . ')';
                } elseif (in_array($type, ['decimal', 'numeric'], true)) {
                    $definition .= '(' . (int) $row['precision'] . ',' . (int) $row['scale'] . ')';
                } elseif (in_array($type, ['time', 'datetime2', 'datetimeoffset'], true)) {
                    $definition .= '(' . (int) $row['scale'] . ')';
                } elseif ($type === 'float') {
                    $definition .= '(' . (int) $row['precision'] . ')';
                }
            }
            if ($row['collation_name']) {
                $definition .= ' COLLATE ' . idf_escape($row['collation_name']);
            }
            if ($row['is_identity']) {
                foreach (['seed_value', 'increment_value'] as $property) {
                    if (!preg_match('/^-?\d+$/D', (string) $row[$property])) {
                        throw new \InvalidArgumentException('Definizione IDENTITY non valida.');
                    }
                }
                $definition .= ' IDENTITY(' . $row['seed_value'] . ',' . $row['increment_value'] . ')';
                $identity = true;
            }
            if ($row['is_rowguidcol']) {
                $definition .= ' ROWGUIDCOL';
            }
            if ($row['is_sparse']) {
                $definition .= ' SPARSE';
            }
            $definition .= $row['is_nullable'] ? ' NULL' : ' NOT NULL';
            if ($row['default_definition'] !== null) {
                $definition .= ' DEFAULT ' . $row['default_definition'];
            }
            $definitions[] = $definition;
            if (!in_array($type, ['timestamp', 'rowversion'], true)) {
                $insertColumns[] = $column;
            }
        }
        $indexes = [];
        foreach (self::rows('SELECT i.index_id, i.name, i.type, i.is_unique, i.is_primary_key, i.is_unique_constraint, i.filter_definition, ic.key_ordinal, ic.is_included_column, ic.is_descending_key, c.name AS column_name
FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE i.object_id = ' . $object . ' AND i.index_id > 0 AND i.is_hypothetical = 0 ORDER BY i.index_id, ic.key_ordinal, ic.index_column_id') as $row) {
            if (!in_array((int) $row['type'], [1, 2], true)) {
                throw new \InvalidArgumentException('La copia guidata supporta solo indici clustered e nonclustered ordinari.');
            }
            $id = $row['index_id'];
            if (!isset($indexes[$id])) {
                $indexes[$id] = ['info' => $row, 'keys' => [], 'include' => []];
            }
            if ($row['is_included_column']) {
                $indexes[$id]['include'][] = idf_escape($row['column_name']);
            } elseif ((int) $row['key_ordinal'] > 0) {
                $indexes[$id]['keys'][] = idf_escape($row['column_name']) . ($row['is_descending_key'] ? ' DESC' : ' ASC');
            }
        }
        $after = [];
        foreach ($indexes as $index) {
            $info = $index['info'];
            $indexName = idf_escape('am_' . bin2hex(random_bytes(8)));
            $kind = (int) $info['type'] === 1 ? 'CLUSTERED' : 'NONCLUSTERED';
            $keys = '(' . implode(', ', $index['keys']) . ')';
            if ($info['is_primary_key'] || $info['is_unique_constraint']) {
                $definitions[] = 'CONSTRAINT ' . $indexName . ($info['is_primary_key'] ? ' PRIMARY KEY ' : ' UNIQUE ') . $kind . ' ' . $keys;
            } else {
                $statement = 'CREATE ' . ($info['is_unique'] ? 'UNIQUE ' : '') . $kind . ' INDEX ' . $indexName . ' ON ' . $target . ' ' . $keys;
                if ($index['include']) {
                    $statement .= ' INCLUDE (' . implode(', ', $index['include']) . ')';
                }
                if ($info['filter_definition']) {
                    $statement .= ' WHERE ' . $info['filter_definition'];
                }
                $after[] = $statement;
            }
        }
        foreach (self::rows('SELECT definition FROM sys.check_constraints WHERE parent_object_id = ' . $object) as $row) {
            $definitions[] = 'CONSTRAINT ' . idf_escape('am_' . bin2hex(random_bytes(8))) . ' CHECK ' . $row['definition'];
        }
        $sql = ['CREATE TABLE ' . $target . " (\n    " . implode(",\n    ", $definitions) . "\n)"];
        $cleanup = [];
        if ($data) {
            if ($identity) {
                $sql[] = 'SET IDENTITY_INSERT ' . $target . ' ON';
                $cleanup[] = 'SET IDENTITY_INSERT ' . $target . ' OFF';
            }
            $list = implode(', ', $insertColumns);
            $sql[] = 'INSERT INTO ' . $target . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $source;
            if ($identity) {
                $sql[] = 'SET IDENTITY_INSERT ' . $target . ' OFF';
            }
        }
        array_push($sql, ...$after);
        foreach (foreign_keys($sourceName) as $key) {
            $same = $key['table'] === $sourceName && $key['ns'] === get_schema() && $key['db'] === DB;
            $reference = $same ? $target : (($key['db'] !== DB ? idf_escape($key['db']) . '.' : '') . TableSql::qualified($key['table'], $key['ns']));
            $sql[] = TableSql::foreignKey($target, $reference, $key['source'], $key['target'], $key['on_delete'], $key['on_update']);
        }
        return [$sql, $cleanup];
    }
}
