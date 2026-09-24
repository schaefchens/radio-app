<?php
declare(strict_types=1);

namespace Arche;

use PDO;
use PDOStatement;

/**
 * The SQLite database: PDO plus the handful of helpers every repository uses.
 *
 * Transactions are short by rule — never held across a network call. The
 * cron tick and API requests share this file, and a writer holding the lock
 * while it waits on Claude would stall every pulse behind it.
 */
final class Store
{
    public readonly PDO $db;
    private int $depth = 0;

    public function __construct(public readonly string $file, bool $wal = true)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the private data directory.');
        }
        $this->db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        @chmod($file, 0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        if ($wal) {
            // WAL lets pulses read while the tick writes. Verified on the host
            // by the probe; SQLITE_WAL=0 falls back to the rollback journal.
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=NORMAL');
        }
    }

    /**
     * Integers are bound as integers, on purpose. PDO binds everything as
     * text by default, and SQLite only converts a text operand when it is
     * compared with a *column*: `start_ms + dur_ms > ?` or `COALESCE(a, b) < ?`
     * would compare an integer with a string — and in SQLite every integer is
     * less than every string, so such a condition is silently always true or
     * always false.
     *
     * @param array<int|string,mixed> $args
     */
    public function query(string $sql, array $args = []): PDOStatement
    {
        $q = $this->db->prepare($sql);
        $i = 0;
        foreach ($args as $key => $value) {
            $param = is_int($key) ? ++$i : $key;
            match (true) {
                is_int($value) => $q->bindValue($param, $value, PDO::PARAM_INT),
                is_bool($value) => $q->bindValue($param, (int) $value, PDO::PARAM_INT),
                $value === null => $q->bindValue($param, null, PDO::PARAM_NULL),
                default => $q->bindValue($param, (string) $value, PDO::PARAM_STR),
            };
        }
        $q->execute();
        return $q;
    }

    /** @param array<int|string,mixed> $args @return array<string,mixed>|null */
    public function one(string $sql, array $args = []): ?array
    {
        $row = $this->query($sql, $args)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $args @return list<array<string,mixed>> */
    public function all(string $sql, array $args = []): array
    {
        return $this->query($sql, $args)->fetchAll();
    }

    /** @param array<int|string,mixed> $args */
    public function value(string $sql, array $args = []): mixed
    {
        $v = $this->query($sql, $args)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_fill(0, count($cols), '?')),
        );
        $this->query($sql, array_values($row));
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string,mixed> $set
     * @param array<int|string,mixed> $args
     */
    public function update(string $table, array $set, string $where, array $args = []): int
    {
        $assign = implode(',', array_map(fn($c) => "$c = ?", array_keys($set)));
        return $this->query("UPDATE $table SET $assign WHERE $where", [...array_values($set), ...$args])->rowCount();
    }

    /**
     * Run $fn in one immediate transaction. Nested calls join the outer one,
     * so a repository method can be transactional on its own and still compose.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }
        $this->db->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $v = $this->value('SELECT value FROM kv WHERE key = ?', [$key]);
        return $v === null ? $default : json_decode((string) $v, true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(string $key, mixed $value): void
    {
        $this->query(
            'INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
    }

    public function audit(string $actor, string $event, string $detail = ''): void
    {
        $this->query(
            'INSERT INTO audit(time, actor, event, detail) VALUES(?, ?, ?, ?)',
            [time(), $actor, $event, mb_substr($detail, 0, 2000)],
        );
    }
}
