<?php

namespace App\Services\DataSource;

/**
 * Filters a cached database schema down to only the tables + columns
 * the admin has explicitly allowed the AI agent to read.
 *
 * Schema input shape (matches what introspectDbSchema() stores under
 * `data_sources.config.schema`):
 *
 *   [
 *     "orders"    => ["id bigint PK", "customer_id int NOT NULL", ...],
 *     "products"  => ["id int PK", "name varchar(255)", "purchase_price decimal(10,2)"],
 *     "ledger"    => ["id int PK", ...],
 *     ...
 *   ]
 *
 * ACL config (lives in `data_sources.config`):
 *
 *   "allowed_tables"  => ["orders", "customers"]      // null/absent = all allowed
 *   "allowed_columns" => [
 *     "orders"    => ["id", "customer_id", "total"],
 *     "customers" => ["id", "name", "email"]
 *   ]
 *   // For any table NOT keyed in allowed_columns → all of its columns
 *   // pass through (legacy behaviour, back-compat with sources that
 *   // never visited the access-control UI).
 *
 * Design choice: allowlist, not blocklist. If a new column gets added
 * to the source DB after a re-sync, it does NOT auto-leak — admins
 * must re-visit the ACL page to expose it. Fail-closed by default for
 * data they've opted-in to control.
 */
class SchemaAclFilter
{
    /**
     * Apply table + column allowlists to a schema array.
     *
     * @param array $schema  full schema as stored in config.schema
     * @param array $config  full data_source.config (includes allowed_tables, allowed_columns)
     * @return array         filtered schema in the same shape
     */
    public function filter(array $schema, array $config): array
    {
        $allowedTables  = $config['allowed_tables']  ?? null;   // null = all tables allowed
        $allowedColumns = $config['allowed_columns'] ?? [];

        $filtered = [];
        foreach ($schema as $tableName => $columns) {
            // Skip tables the admin hasn't allow-listed. If the rule is
            // absent (legacy or never-configured source) we pass every
            // table through — adding the ACL is opt-in.
            if (is_array($allowedTables) && !in_array($tableName, $allowedTables, true)) {
                continue;
            }

            // If there's a per-table column allowlist, drop the columns
            // not in it. Each $col is a string like "purchase_price decimal(10,2)".
            $colsAllowed = $allowedColumns[$tableName] ?? null;
            if (!is_array($colsAllowed)) {
                $filtered[$tableName] = $columns;
                continue;
            }

            $allowedSet = array_flip($colsAllowed);   // O(1) membership
            $filtered[$tableName] = array_values(array_filter(
                $columns,
                function ($colDef) use ($allowedSet) {
                    // Extract the bare column name — the first whitespace-
                    // separated token of "name type [PK|NOT NULL]".
                    $name = strtok((string) $colDef, " \t");
                    return $name !== false && isset($allowedSet[$name]);
                }
            ));
        }

        return $filtered;
    }

    /**
     * Compute "what would be filtered out" for the admin UI — handy for
     * a "preview" view that shows which tables/cols are hidden under
     * the current rules without actually running a query.
     */
    public function summarise(array $schema, array $config): array
    {
        $filtered = $this->filter($schema, $config);
        $hiddenTables = array_values(array_diff(array_keys($schema), array_keys($filtered)));

        $hiddenColumns = [];
        foreach ($schema as $tableName => $columns) {
            if (!isset($filtered[$tableName])) continue;
            $before = count($columns);
            $after  = count($filtered[$tableName] ?? []);
            if ($after < $before) {
                $hiddenColumns[$tableName] = $before - $after;
            }
        }

        return [
            'visible_tables' => count($filtered),
            'total_tables'   => count($schema),
            'hidden_tables'  => $hiddenTables,
            'hidden_columns' => $hiddenColumns,
        ];
    }

    /**
     * Extract bare column names from the stored "name type [modifiers]"
     * strings. Used by the admin UI when rendering the column checklist.
     *
     * @param  string[] $columnDefs
     * @return string[] bare column names
     */
    public static function columnNames(array $columnDefs): array
    {
        $out = [];
        foreach ($columnDefs as $def) {
            $name = strtok((string) $def, " \t");
            if ($name !== false && $name !== '') $out[] = $name;
        }
        return $out;
    }
}
