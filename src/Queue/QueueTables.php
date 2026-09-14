<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager\Queue;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Resolves the real (prefixed) names of the queue tables and introspects the
 * columns that actually exist on them.
 *
 * Flarum applies the database prefix from `config.php` to every table created
 * by its migration system, so the same queue tables look like
 * `toreador34_queue_failed_jobs` on one installation and `myforum_queue_failed_jobs`
 * on another. We therefore never hard-code a prefix: we read it from the live
 * database connection (or honour an explicit override from the extension
 * settings) and build the table names ourselves.
 *
 * Column introspection is defensive: the `uuid` column was only added with
 * newer Laravel versions, so older Flarum 1.x queues may not have it. Every
 * query in this extension only touches columns that actually exist.
 */
class QueueTables
{
    public const DEFAULT_JOBS_TABLE = 'queue_jobs';
    public const DEFAULT_FAILED_TABLE = 'queue_failed_jobs';

    /**
     * @var ConnectionInterface
     */
    protected $db;

    /**
     * @var string
     */
    protected $prefix;

    public function __construct(ConnectionInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = preg_replace('/[^A-Za-z0-9_]+/', '', $prefix) ?? '';
    }

    public static function fromSettings(ConnectionInterface $db, string $override): self
    {
        if ($override !== '') {
            return new static($db, $override);
        }

        if ($db instanceof Connection) {
            return new static($db, (string) $db->getTablePrefix());
        }

        return new static($db, '');
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function jobsTable(): string
    {
        return $this->prefix.self::DEFAULT_JOBS_TABLE;
    }

    public function failedTable(): string
    {
        return $this->prefix.self::DEFAULT_FAILED_TABLE;
    }

    /**
     * List the columns that actually exist on the given (prefixed) table.
     *
     * @return string[]
     */
    public function columnsOf(string $table): array
    {
        try {
            $driver = $this->db->getDriverName();

            if ($driver === 'sqlite') {
                $rows = $this->db->select('SELECT name FROM pragma_table_info(?)', [$table]);
            } elseif ($driver === 'pgsql') {
                $rows = $this->db->select(
                    'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
                    [$table]
                );
            } else {
                $rows = $this->db->select(
                    'SELECT COLUMN_NAME AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
                    [$table]
                );
            }
        } catch (Throwable $e) {
            return [];
        }

        return array_map(function ($row) {
            return is_object($row) ? $row->name : $row['name'];
        }, $rows ?: []);
    }
}