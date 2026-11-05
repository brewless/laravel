<?php

declare(strict_types=1);

namespace Brewless\Laravel\Database;

use PDO;

/**
 * Connections that stay open between requests (`brewless.database.persistent`).
 *
 * PHP hands a thread the connection the request before it used. Two things
 * are made sure of here: the connection is asked for as persistent on the
 * PostgreSQL and MySQL connections the application did not decide on itself,
 * and a connection handed over in the middle of a transaction (a request that
 * died before it could roll back) is rolled back before anything runs on it.
 * A connection the database dropped meanwhile, after a restart for a boost or
 * a failover, fails its first query as a lost connection; reconnecting
 * (RetriesLostConnections) then gets a new one, because PHP does not hand out
 * a persistent connection it found broken.
 */
final class PersistentConnections
{
    public const array DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    /**
     * The connections of config/database.php with PDO::ATTR_PERSISTENT on,
     * unless a connection sets it itself.
     *
     * @param  array<array-key, mixed>  $connections
     * @return array<array-key, mixed>
     */
    public static function configure(array $connections): array
    {
        foreach ($connections as $name => $connection) {
            if (! is_array($connection) || ! in_array($connection['driver'] ?? null, self::DRIVERS, true)) {
                continue;
            }

            $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];

            if (! array_key_exists(PDO::ATTR_PERSISTENT, $options)) {
                $connections[$name]['options'] = $options + [PDO::ATTR_PERSISTENT => true];
            }
        }

        return $connections;
    }

    /**
     * The connection as it is handed over, with a transaction left open by an
     * earlier request rolled back. Asking costs no query: PostgreSQL and
     * MySQL say it with every answer.
     */
    public static function fresh(PDO $pdo): PDO
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return $pdo;
    }
}
