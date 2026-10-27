<?php
declare(strict_types=1);

namespace Adminer;

/** Field-by-field search. Identifiers and operators always come from metadata. */
final class Search
{
    public static function operators(array $field): array
    {
        $ops = ['=' => '=', '!=' => '≠', '>' => '>', '>=' => '≥', '<' => '<', '<=' => '≤', 'BETWEEN' => 'BETWEEN', 'NOT BETWEEN' => 'NOT BETWEEN', 'IN' => 'IN', 'NOT IN' => 'NOT IN'];
        if (preg_match('~char|text|clob|uuid|xml|json|enum|set~i', $field['type'])) {
            $ops = ['CONTAINS' => 'Contiene', 'NOT CONTAINS' => 'Non contiene', 'STARTS' => 'Inizia con', 'ENDS' => 'Finisce con', 'LIKE' => 'LIKE', 'NOT LIKE' => 'NOT LIKE'] + $ops + ['EMPTY' => 'Stringa vuota', 'NOT EMPTY' => 'Stringa non vuota'];
        }
        return $ops + ['IS NULL' => 'IS NULL', 'IS NOT NULL' => 'IS NOT NULL'];
    }

    public static function render(array $fields, array $criteria = [], bool $open = true): void
    {
        $byColumn = [];
        foreach ($criteria as $criterion) {
            if (is_array($criterion) && isset($criterion['col']) && is_string($criterion['col'])) {
                $byColumn[$criterion['col']] = $criterion;
            }
        }
        echo '<fieldset class="am-search-fields"><legend><a href="#fieldset-search">Cerca nei campi</a></legend><div id="fieldset-search"', ($open ? '' : ' class="hidden"'), '>';
        echo input_hidden('am_filter', '1');
        echo '<p class="am-search-intro">Compila i campi da cercare. I valori lasciati vuoti vengono ignorati; per cercare una stringa vuota o NULL scegli l’operatore corrispondente.</p>';
        echo '<div class="scrollable am-search-grid"><table><thead><tr><th>Campo</th><th>Tipo</th><th>Operatore</th><th>Valore</th><th>Secondo valore <span class="am-muted">(BETWEEN)</span></th></tr></thead><tbody>';
        $index = 0;
        foreach ($fields as $name => $field) {
            if (isset($field['privileges']) && !isset($field['privileges']['where'])) {
                continue;
            }
            $criterion = $byColumn[$name] ?? [];
            foreach (['op', 'val', 'val2'] as $key) {
                if (isset($criterion[$key]) && !is_scalar($criterion[$key])) {
                    $criterion[$key] = '';
                }
            }
            $ops = self::operators($field);
            $default = isset($ops['CONTAINS']) ? 'CONTAINS' : '=';
            $operator = (string) ($criterion['op'] ?? $default);
            if ($operator === 'LIKE %%') {
                $operator = 'CONTAINS';
            }
            if (!isset($ops[$operator])) {
                $operator = $default;
            }
            $id = 'am-search-' . $index;
            echo '<tr class="am-search-row"><th><label for="', $id, '">', h((string) $name), '</label>', input_hidden("where[$index][col]", (string) $name), '</th><td><code>', h($field['full_type']), '</code>', (!empty($field['null']) ? '<small class="am-nullable">NULL</small>' : ''), '</td><td><select name="where[', $index, '][op]" class="am-search-op" aria-label="Operatore per ', h((string) $name), '">', optionlist($ops, $operator), '</select></td>';
            echo '<td><input id="', $id, '" name="where[', $index, '][val]" value="', h((string) ($criterion['val'] ?? '')), '" aria-label="Valore per ', h((string) $name), '" placeholder="', (in_array($operator, ['IN', 'NOT IN'], true) ? 'Valori separati da virgola' : 'Ignora campo'), '"></td>';
            echo '<td><input class="am-search-value2" name="where[', $index, '][val2]" value="', h((string) ($criterion['val2'] ?? '')), '" aria-label="Secondo valore per ', h((string) $name), '" placeholder="Limite superiore"></td></tr>';
            $index++;
        }
        echo '</tbody></table></div><div class="am-search-combine"><label>Combina le condizioni <select name="am_match">', optionlist(['all' => 'Tutte (AND)', 'any' => 'Almeno una (OR)'], ($_GET['am_match'] ?? $_POST['am_match'] ?? 'all')), '</select></label><button type="button" data-am-clear-search>Azzera i valori</button></div></div></fieldset>';
    }

    public static function compile(array $fields, array $criteria, string $match = 'all'): array
    {
        if (!in_array($match, ['all', 'any'], true) || count($criteria) > 2000) {
            throw new \InvalidArgumentException('Criteri di ricerca non validi.');
        }
        $conditions = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                throw new \InvalidArgumentException('Criterio di ricerca non valido.');
            }
            foreach (['col', 'op', 'val', 'val2'] as $key) {
                if (isset($criterion[$key]) && !is_scalar($criterion[$key])) {
                    throw new \InvalidArgumentException('Valore di ricerca non valido.');
                }
            }
            $name = (string) ($criterion['col'] ?? '');
            $op = (string) ($criterion['op'] ?? '=');
            $value = (string) ($criterion['val'] ?? '');
            $second = (string) ($criterion['val2'] ?? '');
            if ($value === '' && $second === '' && !in_array($op, ['IS NULL', 'IS NOT NULL', 'EMPTY', 'NOT EMPTY'], true)) {
                continue;
            }
            if (!isset($fields[$name]) || (isset($fields[$name]['privileges']) && !isset($fields[$name]['privileges']['where']))) {
                throw new \InvalidArgumentException('Campo di ricerca sconosciuto: ' . $name);
            }
            $field = $fields[$name];
            if (!array_key_exists($op, self::operators($field))) {
                throw new \InvalidArgumentException('Operatore non valido per ' . $name);
            }
            $column = idf_escape($name);
            if (DRIVER === 'mssql' && preg_match('~^(n?text|xml)$~i', $field['type'])) {
                $column = 'CAST(' . $column . ' AS nvarchar(max))';
            } elseif (DRIVER === 'pgsql' && preg_match('~^(jsonb?|xml|uuid)$~i', $field['type'])) {
                $column = 'CAST(' . $column . ' AS text)';
            }
            if (in_array($op, ['IS NULL', 'IS NOT NULL'], true)) {
                $conditions[] = "$column $op";
            } elseif ($op === 'EMPTY' || $op === 'NOT EMPTY') {
                $conditions[] = $column . ($op === 'EMPTY' ? ' = ' : ' <> ') . q('');
            } elseif ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
                if ($value === '' || $second === '') {
                    throw new \InvalidArgumentException('BETWEEN richiede entrambi i valori per ' . $name);
                }
                $conditions[] = "$column $op " . q($value) . ' AND ' . q($second);
            } elseif ($op === 'IN' || $op === 'NOT IN') {
                $values = str_getcsv($value, ',', '"', '');
                if (count($values) > 1000) {
                    throw new \InvalidArgumentException('IN accetta al massimo 1000 valori.');
                }
                $conditions[] = "$column $op (" . implode(', ', array_map(static fn($v) => q(trim((string) $v)), $values)) . ')';
            } else {
                if (in_array($op, ['CONTAINS', 'NOT CONTAINS', 'STARTS', 'ENDS'], true)) {
                    $value = ($op === 'STARTS' ? '' : '%') . $value . ($op === 'ENDS' ? '' : '%');
                    $op = $op === 'NOT CONTAINS' ? 'NOT LIKE' : 'LIKE';
                }
                $conditions[] = "$column $op " . q($value);
            }
        }
        return $conditions ? ['(' . implode($match === 'any' ? ' OR ' : ' AND ', array_map(static fn($sql) => "($sql)", $conditions)) . ')'] : [];
    }
}
