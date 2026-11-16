<?php

/**
 * Prefixed account IDs: A1 (admins), L1 (landlords), T1 (tenants).
 *
 * Replaces AUTO_INCREMENT + lastInsertId() for these three tables.
 * Require this file wherever an account row is inserted.
 */

if (!function_exists('account_pk')) {
    /** Primary key column for a role ('admin' | 'landlord' | 'tenant'). */
    function account_pk(string $role): string
    {
        return match ($role) {
            'admin' => 'admin_id',
            'landlord' => 'landlord_id',
            default => 'tenant_id',
        };
    }
}

if (!function_exists('account_table')) {
    /** Table name for a role. */
    function account_table(string $role): string
    {
        return match ($role) {
            'admin' => 'admins',
            'landlord' => 'landlords',
            default => 'tenants',
        };
    }
}

if (!function_exists('next_account_id')) {
    /**
     * Returns the next sequential ID for an account table, ensuring no numbers are skipped.
     * When records are deleted, the next record added reuses any missing sequential slot
     * or continues immediately after the highest active record.
     *
     * Example: next_account_id($pdo, 'tenants') => 'T1' (if empty) or 'T2' (if T1 exists).
     */
    function next_account_id(PDO $pdo, string $table): string
    {
        $prefixes = ['admins' => 'A', 'landlords' => 'L', 'tenants' => 'T'];
        $pks = ['admins' => 'admin_id', 'landlords' => 'landlord_id', 'tenants' => 'tenant_id'];

        if (!isset($prefixes[$table], $pks[$table])) {
            throw new InvalidArgumentException('Unknown account table: ' . $table);
        }

        $prefix = $prefixes[$table];
        $pk = $pks[$table];
        $prefixLen = strlen($prefix);

        // Lock counter row to coordinate concurrent inserts
        try {
            $pdo->prepare('SELECT last_value FROM id_counters WHERE name = ? FOR UPDATE')->execute([$table]);
        } catch (\Throwable $e) {}

        // Query all existing IDs for this table
        $stmt = $pdo->prepare("SELECT CAST(SUBSTRING(`{$pk}`, :pLen) AS UNSIGNED) AS num FROM `{$table}` WHERE `{$pk}` LIKE :pattern ORDER BY num ASC");
        $stmt->execute([
            ':pLen' => $prefixLen + 1,
            ':pattern' => $prefix . '%'
        ]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Pick the smallest positive integer (>= 1) not currently taken
        $nextNum = 1;
        if (!empty($existing)) {
            $lookup = array_flip(array_map('intval', $existing));
            while (isset($lookup[$nextNum])) {
                $nextNum++;
            }
        }

        // Keep id_counters table synchronized with the highest assigned ID
        $maxNum = !empty($existing) ? max(max(array_map('intval', $existing)), $nextNum) : $nextNum;
        try {
            $pdo->prepare('INSERT INTO id_counters (name, last_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_value = ?')
                ->execute([$table, $maxNum, $maxNum]);
        } catch (\Throwable $e) {}

        return $prefix . $nextNum;
    }
}

if (!function_exists('sync_account_id_counter')) {
    /**
     * Re-synchronizes id_counters for a specific account table or all account tables.
     */
    function sync_account_id_counter(PDO $pdo, ?string $table = null): void
    {
        $map = [
            'admins' => ['pk' => 'admin_id', 'prefix' => 'A'],
            'landlords' => ['pk' => 'landlord_id', 'prefix' => 'L'],
            'tenants' => ['pk' => 'tenant_id', 'prefix' => 'T']
        ];

        $targets = $table ? [$table => $map[$table] ?? null] : $map;
        foreach ($targets as $tbl => $cfg) {
            if (!$cfg) continue;
            $pk = $cfg['pk'];
            $pLen = strlen($cfg['prefix']) + 1;
            try {
                $stmt = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(`{$pk}`, {$pLen}) AS UNSIGNED)), 0) FROM `{$tbl}`");
                $maxNum = (int)$stmt->fetchColumn();
                $pdo->prepare('INSERT INTO id_counters (name, last_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_value = ?')
                    ->execute([$tbl, $maxNum, $maxNum]);
            } catch (\Throwable $e) {
                error_log("Failed to sync id_counter for {$tbl}: " . $e->getMessage());
            }
        }
    }
}
