<?php

/**
 * Prefixed account IDs: A1 (admins), L1 (landlords), T1 (tenants).
 *
 * Replaces AUTO_INCREMENT + lastInsertId() for these three tables.
 * Require this file wherever an account row is inserted.
 */

/** Primary key column for a role ('admin' | 'landlord' | 'tenant'). */
function account_pk(string $role): string
{
    return match ($role) {
        'admin' => 'admin_id',
        'landlord' => 'landlord_id',
        default => 'tenant_id',
    };
}

/** Table name for a role. */
function account_table(string $role): string
{
    return match ($role) {
        'admin' => 'admins',
        'landlord' => 'landlords',
        default => 'tenants',
    };
}

/**
 * Returns the next free ID for a table, e.g. next_account_id($pdo, 'tenants') => 'T12'.
 *
 * The counter update is atomic, so two sign-ups at the same moment can never
 * receive the same ID. Call it right before the INSERT; when it runs inside a
 * transaction that is rolled back, the number is released again.
 */
function next_account_id(PDO $pdo, string $table): string
{
    $prefixes = ['admins' => 'A', 'landlords' => 'L', 'tenants' => 'T'];
    if (!isset($prefixes[$table])) {
        throw new InvalidArgumentException('Unknown account table: ' . $table);
    }

    $pdo->prepare('UPDATE id_counters SET last_value = LAST_INSERT_ID(last_value + 1) WHERE name = ?')
        ->execute([$table]);

    $next = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($next <= 0) {
        throw new RuntimeException('ID counter for ' . $table . ' is missing. Run database/migrate_v3_prefixed_ids.php.');
    }

    return $prefixes[$table] . $next;
}