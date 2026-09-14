<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Toreador\QueueManager\Queue\QueueTables;
use Throwable;

/**
 * All read/write access to the database queue tables.
 *
 * A "requeue" performs the exact operation the user asked for: it moves the
 * row from `<prefix>queue_failed_jobs` back into `<prefix>queue_jobs`,
 * setting `reserved_at` to NULL and `available_at`/`created_at` to the
 * current unixtime, and resets `attempts` to 0 so the job is processed from
 * scratch again. The `id` is preserved when it does not collide with an
 * existing job row, and the `uuid` is only written when the target table
 * actually has that column.
 */
class FailedJobsRepository
{
    /**
     * @var ConnectionInterface
     */
    protected $db;

    /**
     * @var QueueTables
     */
    protected $tables;

    /**
     * @var JobClassMap
     */
    protected $jobClassMap;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    public function __construct(ConnectionInterface $db, QueueTables $tables, JobClassMap $jobClassMap, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->tables = $tables;
        $this->jobClassMap = $jobClassMap;
        $this->logger = $logger;
    }

    public function getTables(): QueueTables
    {
        return $this->tables;
    }

    /**
     * Cheap sanity check so the admin page can tell us the queue tables are
     * missing (e.g. the database queue driver is not installed/used).
     */
    public function tablesExist(): bool
    {
        try {
            return count($this->tables->columnsOf($this->tables->failedTable())) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{pending: int, failed: int, queues: array<int, array{queue: string, count: int}>}
     */
    public function stats(): array
    {
        try {
            $jobs = $this->db->selectOne('SELECT COUNT(*) AS count FROM '.$this->tables->jobsTable());
            $failed = $this->db->selectOne('SELECT COUNT(*) AS count FROM '.$this->tables->failedTable());
        } catch (Throwable $e) {
            return ['pending' => 0, 'failed' => 0, 'queues' => []];
        }

        $pending = $jobs ? (int) $jobs->count : 0;
        $failedCount = $failed ? (int) $failed->count : 0;

        $queues = [];
        try {
            $rows = $this->db->select(
                'SELECT queue AS queue, COUNT(*) AS count FROM '.$this->tables->failedTable().' GROUP BY queue ORDER BY count DESC, queue ASC'
            );
            foreach ($rows ?: [] as $row) {
                $queues[] = ['queue' => (string) $row->queue, 'count' => (int) $row->count];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return ['pending' => $pending, 'failed' => $failedCount, 'queues' => $queues];
    }

    /**
     * @param int           $limit
     * @param int           $offset
     * @param string|null   $queue Exact queue name filter.
     * @param string|null   $search Free-text filter (queue or payload).
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    public function list(int $limit, int $offset, ?string $queue, ?string $search): array
    {
        $where = [];
        $params = [];

        if (is_string($queue) && $queue !== '') {
            $where[] = 'queue = ?';
            $params[] = $queue;
        }

        if (is_string($search) && $search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $where[] = '(queue LIKE ? ESCAPE \'\\\' OR payload LIKE ? ESCAPE \'\\\')';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';

        $totalRow = $this->db->selectOne(
            'SELECT COUNT(*) AS count FROM '.$this->tables->failedTable().$whereSql,
            $params
        );
        $total = $totalRow ? (int) $totalRow->count : 0;

        $selectParams = array_merge($params);
        $rows = $this->db->select(
            'SELECT * FROM '.$this->tables->failedTable().$whereSql
            .' ORDER BY failed_at DESC, id DESC LIMIT '.(int) $limit.' OFFSET '.(int) $offset,
            $selectParams
        );

        return [
            'total' => $total,
            'rows' => array_map(function ($row) {
                return (array) $row;
            }, $rows ?: []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM '.$this->tables->failedTable().' WHERE id = ?', [(int) $id]);

        return $row ? (array) $row : null;
    }

    /**
     * Serialize a single failed job into the shape the admin API returns.
     *
     * @return array<string, mixed>
     */
    public function present(?array $row): ?array
    {
        if (! $row) {
            return null;
        }

        $payload = (string) ($row['payload'] ?? '');
        $described = $this->jobClassMap->describe($payload);

        return [
            'id' => (int) $row['id'],
            'uuid' => isset($row['uuid']) ? (string) $row['uuid'] : null,
            'connection' => isset($row['connection']) && $row['connection'] !== null ? (string) $row['connection'] : null,
            'queue' => (string) $row['queue'],
            'display_name' => $described['display_name'],
            'command_class' => $described['command_class'],
            'description' => $described['description'],
            'max_tries' => $described['max_tries'],
            'timeout' => $described['timeout'],
            'backoff' => $described['backoff'],
            'payload_summary' => $this->summarize($payload, 400),
            'exception_head' => $this->summarize((string) ($row['exception'] ?? ''), 300),
            'failed_at' => $this->formatDate(isset($row['failed_at']) ? (string) $row['failed_at'] : ''),
        ];
    }

    /**
     * Full detail for the detail modal.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $row = $this->find($id);

        if (! $row) {
            return null;
        }

        $presented = $this->present($row);
        if (! $presented) {
            return null;
        }

        $presented['payload'] = (string) ($row['payload'] ?? '');
        $presented['exception'] = (string) ($row['exception'] ?? '');

        return $presented;
    }

    /**
     * Requeue a single failed job.
     *
     * @return array{ok: bool, reason?: string, requeued?: bool, id?: int}
     */
    public function requeue(int $id): array
    {
        $row = $this->find($id);

        if (! $row) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        try {
            $this->db->transaction(function () use ($row) {
                $this->insertRequeuedJob($row);
                $this->db->delete(
                    'DELETE FROM '.$this->tables->failedTable().' WHERE id = ?',
                    [(int) $row['id']]
                );
            });
        } catch (Throwable $e) {
            $this->logError('Could not requeue failed job', (int) $row['id'], $e);

            return ['ok' => false, 'reason' => 'error'];
        }

        return ['ok' => true, 'requeued' => true, 'id' => (int) $row['id']];
    }

    /**
     * Requeue many failed jobs (explicit ids).
     *
     * @param int[] $ids
     * @return array{requeued: int, failed_ids: int[], errors: array<int, string>}
     */
    public function requeueMany(array $ids): array
    {
        $requeued = 0;
        $failedIds = [];
        $errors = [];

        foreach ($ids as $id) {
            $result = $this->requeue((int) $id);

            if (! $result['ok']) {
                $failedIds[] = (int) $id;
                $errors[(int) $id] = $result['reason'] ?? 'error';
            } else {
                $requeued++;
            }
        }

        return ['requeued' => $requeued, 'failed_ids' => $failedIds, 'errors' => $errors];
    }

    /**
     * Requeue every eligible failed job. Used by the "requeue all" button and
     * by the scheduled auto-requeue command.
     *
     * @param int         $olderThanMinutes Only requeue jobs that failed more
     *                                      than this many minutes ago (0 = all).
     * @param string|null $queue            Optional queue name filter.
     * @return array{requeued: int, failed_ids: int[], errors: array<int, string>}
     */
    public function requeueAll(int $olderThanMinutes, ?string $queue): array
    {
        $rows = $this->eligibleRows($olderThanMinutes, $queue);

        $requeued = 0;
        $failedIds = [];
        $errors = [];

        try {
            $this->db->transaction(function () use ($rows, &$requeued, &$failedIds, &$errors) {
                foreach ($rows as $row) {
                    try {
                        $this->insertRequeuedJob($row);
                        $this->db->delete(
                            'DELETE FROM '.$this->tables->failedTable().' WHERE id = ?',
                            [(int) $row['id']]
                        );
                        $requeued++;
                    } catch (Throwable $e) {
                        $this->logError('Could not requeue failed job', (int) $row['id'], $e);
                        $failedIds[] = (int) $row['id'];
                        $errors[(int) $row['id']] = 'error';
                    }
                }
            });
        } catch (Throwable $e) {
            $this->logError('Requeue-all transaction failed', null, $e);

            return ['requeued' => $requeued, 'failed_ids' => $failedIds, 'errors' => [(int) 0 => 'error']];
        }

        return ['requeued' => $requeued, 'failed_ids' => $failedIds, 'errors' => $errors];
    }

    /**
     * Delete a single failed job record without requeueing it.
     */
    public function delete(int $id): bool
    {
        try {
            return (bool) $this->db->delete(
                'DELETE FROM '.$this->tables->failedTable().' WHERE id = ?',
                [(int) $id]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Remove all failed job records (optionally only for one queue).
     */
    public function clear(?string $queue): int
    {
        try {
            if (is_string($queue) && $queue !== '') {
                return (int) $this->db->delete(
                    'DELETE FROM '.$this->tables->failedTable().' WHERE queue = ?',
                    [$queue]
                );
            }

            return (int) $this->db->delete('DELETE FROM '.$this->tables->failedTable());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function eligibleRows(int $olderThanMinutes, ?string $queue): array
    {
        $where = [];
        $params = [];

        if ($olderThanMinutes > 0) {
            $cutoff = Carbon::now()->subMinutes((int) $olderThanMinutes)->format('Y-m-d H:i:s');
            $where[] = '(failed_at IS NULL OR failed_at <= ?)';
            $params[] = $cutoff;
        }

        if (is_string($queue) && $queue !== '') {
            $where[] = 'queue = ?';
            $params[] = $queue;
        }

        $whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';

        try {
            $rows = $this->db->select(
                'SELECT * FROM '.$this->tables->failedTable().$whereSql.' ORDER BY failed_at DESC, id DESC',
                $params
            );
        } catch (Throwable $e) {
            return [];
        }

        return array_map(function ($row) {
            return (array) $row;
        }, $rows ?: []);
    }

    /**
     * INSERT the failed job back into the jobs table and return nothing.
     *
     * @param array<string, mixed> $row
     */
    protected function insertRequeuedJob(array $row): void
    {
        $jobsColumns = $this->tables->columnsOf($this->tables->jobsTable());
        $failedColumns = $this->tables->columnsOf($this->tables->failedTable());

        $now = time();

        $cols = [];
        $vals = [];

        // Preserve the original job id when it does not collide with a row
        // that is already waiting in the queue again.
        if (in_array('id', $jobsColumns, true) && in_array('id', $failedColumns, true)) {
            $occupied = $this->db->selectOne(
                'SELECT id FROM '.$this->tables->jobsTable().' WHERE id = ?',
                [(int) $row['id']]
            );

            if (! $occupied) {
                $cols[] = 'id';
                $vals[] = (int) $row['id'];
            }
        }

        $cols[] = 'queue';
        $vals[] = (string) $row['queue'];

        $cols[] = 'payload';
        $vals[] = (string) $row['payload'];

        if (in_array('attempts', $jobsColumns, true)) {
            $cols[] = 'attempts';
            $vals[] = 0;
        }

        if (in_array('reserved_at', $jobsColumns, true)) {
            $cols[] = 'reserved_at';
            $vals[] = null;
        }

        if (in_array('available_at', $jobsColumns, true)) {
            $cols[] = 'available_at';
            $vals[] = $now;
        }

        if (in_array('created_at', $jobsColumns, true)) {
            $cols[] = 'created_at';
            $vals[] = $now;
        }

        // Only write a uuid when the target table has such a column.
        if (in_array('uuid', $jobsColumns, true)) {
            $uuid = (in_array('uuid', $failedColumns, true) && ! empty($row['uuid']))
                ? (string) $row['uuid']
                : (string) Str::uuid();
            $cols[] = 'uuid';
            $vals[] = $uuid;
        }

        if (! $cols) {
            throw new \RuntimeException('The jobs table has no known columns to write to.');
        }

        $placeholders = implode(', ', array_fill(0, count($vals), '?'));
        $columnList = implode(', ', $cols);

        $this->db->insert(
            'INSERT INTO '.$this->tables->jobsTable()." ($columnList) VALUES ($placeholders)",
            $vals
        );
    }

    protected function logError(string $context, ?int $jobId, Throwable $e): void
    {
        if ($this->logger) {
            $extra = $jobId !== null ? ['job_id' => $jobId] : [];
            $this->logger->error("$context [job #$jobId]: {$e->getMessage()}", array_merge($extra, ['exception' => $e]));
        }
    }

    protected function summarize(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length, 'UTF-8').'…';
    }

    protected function formatDate(string $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable $e) {
            return $value;
        }
    }
}