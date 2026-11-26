<?php
/**
 * SqlTranslator — converts the legacy MySQL/MariaDB dialect used throughout
 * this codebase into PostgreSQL-compatible SQL.
 *
 * Applied automatically by PgPDO (query/prepare/exec) and by MysqliCompat,
 * so neither the PDO code nor the legacy mysqli code needs rewriting.
 */
final class SqlTranslator
{
    /** Tables where "ON DUPLICATE KEY UPDATE" needs an explicit conflict target. */
    private const CONFLICT_TARGETS = [
        'store_settings'      => '(setting_key)',
        'store_config'        => '(store_id, setting_key)',
        'display_settings'    => '(store_id, setting_key)',
        'password_resets'     => '(email)',
        'payment_qrcodes'     => '(store_id, payment_method)',
        'shift_assignments'   => '(shift_id, user_id)',
        'users'               => '(username)',
        'orders'              => '(order_number)',
    ];

    private const DATE_FORMAT_MAP = [
        'Y' => 'YYYY', 'y' => 'YY', 'm' => 'MM', 'c' => 'FMMM', 'd' => 'DD', 'e' => 'FMDD',
        'b' => 'Mon', 'M' => 'FMMonth', 'a' => 'Dy', 'W' => 'FMDay', 'D' => 'Dy',
        'H' => 'HH24', 'h' => 'HH12', 'i' => 'MI', 's' => 'SS', 'p' => 'AM',
        'T' => 'HH24:MI:SS', 'j' => 'DDD', 'w' => 'D',
    ];

    public static function translate(string $sql): string
    {
        if (trim($sql) === '') {
            return $sql;
        }

        $s = str_replace('`', '', $sql);

        // Session / transaction commands that PostgreSQL does not understand.
        if (preg_match('/^\s*SET\s+(NAMES|CHARACTER\s+SET|time_zone|sql_mode|SQL_MODE|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_NOTES)/i', $s)) {
            return 'SELECT 1';
        }
        if (preg_match('/^\s*(START\s+TRANSACTION|BEGIN)\s*;?\s*$/i', $s)) {
            return 'BEGIN';
        }
        if (preg_match('/^\s*USE\s+\S+\s*;?\s*$/i', $s)) {
            return 'SELECT 1';
        }

        // Metadata statements (SHOW / DESCRIBE) — rewrite against information_schema.
        $meta = self::metadataQuery($s);
        if ($meta !== null) {
            return $meta;
        }

        // INSERT IGNORE → INSERT ... ON CONFLICT DO NOTHING
        $isInsertIgnore = (bool) preg_match('/^\s*INSERT\s+IGNORE\s+/i', $s);
        if ($isInsertIgnore) {
            $s = preg_replace('/^\s*INSERT\s+IGNORE\s+/i', 'INSERT ', $s);
        }

        // LIMIT offset, count  →  LIMIT count OFFSET offset
        $s = preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT $2 OFFSET $1', $s);

        // Resolve zero-argument date helpers first so nested expressions unwrap.
        $s = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $s);
        $s = preg_replace('/\bCURTIME\s*\(\s*\)/i', 'LOCALTIME', $s);

        // Date formatting / arithmetic
        $s = preg_replace_callback(
            "/DATE_FORMAT\s*\(\s*([^,]+?)\s*,\s*'([^']*)'\s*\)/i",
            static fn(array $m): string => 'TO_CHAR(' . $m[1] . ", '" . self::pgDateFormat($m[2]) . "')",
            $s
        );
        $s = preg_replace('/YEARWEEK\s*\(\s*([^,()]+?)\s*(?:,\s*\d+\s*)?\)/i', "TO_CHAR(date_trunc('week', $1), 'IYYYIW')", $s);
        $s = preg_replace('/\bWEEK\s*\(\s*([^,()]+?)\s*(?:,\s*\d+\s*)?\)/i', "TO_CHAR(date_trunc('week', $1), 'IW')", $s);
        $s = preg_replace("/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)\s*\)/i", "($1 - INTERVAL '$2 $3')", $s);
        $s = preg_replace("/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+([A-Za-z]+)\s*\)/i", "($1 + INTERVAL '$2 $3')", $s);
        $s = preg_replace(
            '/TIMESTAMPDIFF\s*\(\s*(MINUTE|HOUR|DAY|SECOND)\s*,\s*([^,]+?)\s*,\s*([^()]+?)\s*\)/i',
            "FLOOR(EXTRACT(EPOCH FROM ($3 - $2)) / CASE UPPER('$1') WHEN 'MINUTE' THEN 60 WHEN 'HOUR' THEN 3600 WHEN 'DAY' THEN 86400 ELSE 1 END)",
            $s
        );

        // Date parts
        $s = preg_replace('/MONTHNAME\s*\(\s*([^()]+?)\s*\)/i', "TRIM(TO_CHAR($1, 'Month'))", $s);
        $s = preg_replace('/DAYNAME\s*\(\s*([^()]+?)\s*\)/i', "TRIM(TO_CHAR($1, 'Day'))", $s);
        $s = preg_replace('/\bMONTH\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(MONTH FROM $1)::int', $s);
        $s = preg_replace('/\bYEAR\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(YEAR FROM $1)::int', $s);
        $s = preg_replace('/\bDAYOFMONTH\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(DAY FROM $1)::int', $s);
        $s = preg_replace('/\bDAY\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(DAY FROM $1)::int', $s);
        $s = preg_replace('/\bHOUR\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(HOUR FROM $1)::int', $s);
        $s = preg_replace('/\bMINUTE\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(MINUTE FROM $1)::int', $s);
        $s = preg_replace('/\bSECOND\s*\(\s*([^()]+?)\s*\)/i', 'EXTRACT(SECOND FROM $1)::int', $s);
        $s = preg_replace('/DATEDIFF\s*\(\s*([^,]+?)\s*,\s*([^()]+?)\s*\)/i', '(($1)::date - ($2)::date)', $s);

        // Date function wrapper (must run after the DATE_* helpers above)
        $s = preg_replace('/(?<![A-Za-z_])DATE\s*\(\s*([^()]+?)\s*\)/i', '($1)::date', $s);

        // Misc functions
        $s = preg_replace('/\bLAST_INSERT_ID\s*\(\s*\)/i', 'lastval()', $s);
        $s = preg_replace('/\bRAND\s*\(\s*\)/i', 'random()', $s);
        $s = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $s);
        $s = preg_replace('/(?<![A-Za-z_])IF\s*\(\s*([^,()]+)\s*,\s*([^,()]+)\s*,\s*([^()]+)\s*\)/i', 'CASE WHEN $1 THEN $2 ELSE $3 END', $s);

        // SUM(boolean_expression) — MySQL treats booleans as 1/0.
        $s = preg_replace(
            '/\bSUM\s*\(([^()]*(?:>=|<=|<>|!=|=|>|<)[^()]*)\)/i',
            'SUM(($1)::int)',
            $s
        );

        // ON DUPLICATE KEY UPDATE → ON CONFLICT ... DO UPDATE SET
        $s = self::onDuplicateKey($s);
        if ($isInsertIgnore && stripos($s, 'ON CONFLICT') === false) {
            $s = rtrim(rtrim($s), ';') . ' ON CONFLICT DO NOTHING';
        }

        // MySQL collations are case-insensitive; ILIKE keeps search behaviour.
        $s = preg_replace('/(?<![A-Za-z])LIKE\b/i', 'ILIKE', $s);

        // DDL helpers used by legacy migration scripts.
        if (preg_match('/^\s*ALTER\s+TABLE/i', $s)) {
            $s = preg_replace('/\s+AFTER\s+`?\w+`?/i', '', $s);
        }

        return $s;
    }

    private static function pgDateFormat(string $fmt): string
    {
        $out = '';
        $len = strlen($fmt);
        for ($i = 0; $i < $len; $i++) {
            $ch = $fmt[$i];
            if ($ch === '%' && $i + 1 < $len) {
                $code = $fmt[++$i];
                $out .= self::DATE_FORMAT_MAP[$code] ?? $code;
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }

    private static function onDuplicateKey(string $s): string
    {
        if (stripos($s, 'ON DUPLICATE KEY UPDATE') === false) {
            return $s;
        }
        if (!preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+(\w+)/i', $s, $m)) {
            return $s;
        }
        $table = strtolower($m[1]);
        if (!isset(self::CONFLICT_TARGETS[$table])) {
            return preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/is', '', $s);
        }
        $s = preg_replace(
            '/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+/is',
            ' ON CONFLICT ' . self::CONFLICT_TARGETS[$table] . ' DO UPDATE SET ',
            $s
        );
        $s = preg_replace('/\b(\w+)\s*=\s*VALUES\s*\(\s*\1\s*\)/i', '$1 = EXCLUDED.$1', $s);
        return $s;
    }

    private static function metadataQuery(string $s): ?string
    {
        if (preg_match('/^\s*SHOW\s+TABLES(?:\s+LIKE\s+[\'"]([^\'"]+)[\'"])?\s*;?\s*$/i', $s, $m)) {
            $sql = 'SELECT tablename AS "Tables_in_public" FROM pg_tables WHERE schemaname = \'public\'';
            if (!empty($m[1])) {
                $sql .= " AND tablename ILIKE '" . $m[1] . "'";
            }
            return $sql . ' ORDER BY tablename';
        }
        if (preg_match('/^\s*SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM\s+(\w+)(?:\s+FROM\s+\w+)?(?:\s+LIKE\s+[\'"]([^\'"]+)[\'"])?/i', $s, $m)) {
            return self::columnInfoSql($m[1], $m[2] ?? null);
        }
        if (preg_match('/^\s*(?:DESCRIBE|DESC)\s+(\w+)\s*;?\s*$/i', $s, $m)) {
            return self::columnInfoSql($m[1], null);
        }
        if (preg_match('/^\s*SHOW\s+CREATE\s+TABLE\s+(\w+)/i', $s)) {
            return "SELECT 'CREATE TABLE' AS \"Table\", '' AS \"Create Table\"";
        }
        return null;
    }

    private static function columnInfoSql(string $table, ?string $like): string
    {
        $cond = $like !== null ? " AND column_name = '" . str_replace("'", "''", $like) . "'" : '';
        return "SELECT column_name AS \"Field\", data_type AS \"Type\", "
            . "CASE WHEN is_nullable = 'YES' THEN 'YES' ELSE 'NO' END AS \"Null\", "
            . "'' AS \"Key\", column_default AS \"Default\", '' AS \"Extra\" "
            . "FROM information_schema.columns "
            . "WHERE table_schema = 'public' AND table_name = '" . str_replace("'", "''", $table) . "'"
            . $cond . ' ORDER BY ordinal_position';
    }
}
