# Queue Manager

Flarum 1.x admin extension for the `flarum`/`blomstra` database queue driver.
It lists failed queue jobs, inspects their payload/exception and explains what
each job does, allows requeueing them (individually or all at once) and can
auto-requeue stale failed jobs via the scheduled task runner — all from the
admin panel.

```
sjn4f/queue-manager
```

## Installation

```
composer require sjn4f/queue-manager
```

> Local path install: `"repositories": [{"type": "path", "url": "path/to/flarum-job-queue"}]`
> then `composer require sjn4f/queue-manager:*`.

Enable the extension in the admin, then run `php flarum cache:clear`, and rebuild
the admin JS if you installed from source (`cd js && npm run build`).

## What it manages

Flarum's `blomstra/database-queue` driver stores jobs in two tables that are
auto-prefixed with the value from `config.php` `database.prefix`
(e.g. `sjn4F_`):

| Table | Purpose |
|---|---|
| `{prefix}queue_jobs` | Pending (queued) jobs, processed by the worker |
| `{prefix}queue_failed_jobs` | Jobs that exhausted their retry count, moved here by the failed-job controller |

This extension reads the **live** prefix from the active database connection, so
it works out of the box with any prefix. If you export the queue tables to a
different setup you can override the prefix with the setting
`toreador-flarum-job-queue.table_prefix`.

## Admin page

Open **Administration → Queue Manager**:

- Stats cards: pending jobs, failed jobs, table names actually in use.
- Toolbar: search (class/description), filter by queue, requeue all failed,
  clear all failed, auto-refresh toggle.
- Table: id, uuid, queue, display name, what the job does, attempts/max tries,
  failed at, status and per-row actions (requeue, delete, view).
- The **view** modal shows the raw `payload` JSON and the `exception` text so
  you can see exactly why the job failed.
- Requeueing moves the row back into `queue_jobs` with `attempts=0`,
  `reserved_at=NULL`, `available_at`/`created_at` = now. The original `id` is
  preserved when free; the `uuid` is preserved when the jobs table has that
  column.

## Auto-requeue (scheduled)

The extension registers a console command and schedules it every minute:

```bash
php flarum queue:failed-jobs:requeue                 # process settings-driven auto-requeue
php flarum queue:failed-jobs:requeue 12 42           # requeue specific failed-job ids
```

Enabled only when both settings are on:

- `toreador-flarum-job-queue.auto_requeue.enabled` — requeue old failed jobs on schedule
- `toreador-flarum-job-queue.auto_requeue_after` (minutes) — only jobs older than this
  are requeued (default `60`)

This means you do not need `flarum/scheduler`'s cron entry if your worker runs on
`schedule:run` through a cron job (see Flarum scheduler docs).

## What the requeue does

Same operation as the admin "requeue" but run from the CLI. Equivalent SQL
(assuming prefix `sjn4F_`, replacing `:now` with `UNIX_TIMESTAMP()` on MySQL):

```sql
INSERT INTO sjn4F_queue_jobs
    (uuid, queue, payload, attempts, reserved_at, available_at, created_at)
SELECT uuid, queue, payload, 0, NULL,
       :now,                                  -- available_at = now()
       :now                                   -- created_at = now()
FROM sjn4F_queue_failed_jobs
WHERE id = :failedJobId;                      -- or WHERE 1=1 for "requeue all"

DELETE FROM sjn4F_queue_failed_jobs WHERE id = :failedJobId;
```

Notes (all defensive, this is what the extension actually does):

- Columns are only touched when they exist on the live table. On installs where
  the jobs table has no `uuid` column the `uuid` is omitted (a fresh `Str::uuid()`
  is emitted through the abstract layer instead).
- Preserving the original `id` means the job keeps its position: the extension
  inserts with the original id and lets the database generate a new one if that
  id is already taken.
- All statements run inside a transaction; if the insert fails the failed row is
  left untouched.

## API endpoints

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/queue-manager` | Stats + start page of failed jobs |
| GET | `/api/queue-manager/:id` | Single job detail (payload + exception) |
| POST | `/api/queue-manager/requeue/:id` | Requeue one failed job |
| POST | `/api/queue-manager/requeue-all` | Requeue all (optionally `?queue=`) |
| DELETE | `/api/queue-manager/:id` | Delete one failed job record |
| POST | `/api/queue-manager/clear` | Delete all (optionally `?queue=`) |

## Requirements

- PHP 8.1+
- Flarum `^1.2` (1.x compatibility first; 2.x support planned)
- `flarum/core` with database queue driver (`blomstra/database-queue`)
- Flarum scheduler or cron for the auto-requeue command

## License

MIT. See [LICENSE](LICENSE).