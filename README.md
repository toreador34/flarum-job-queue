# Queue Manager

[GitHub](https://github.com/toreador34/flarum-job-queue) · [Packagist](https://packagist.org/packages/toreador/flarum-job-queue)

Flarum 2.x admin extension for the built-in database queue driver
(`'queue' => ['driver' => 'database']` in `config.php`).
It lists failed queue jobs, inspects their payload/exception and explains what
each job does, allows requeueing them (individually or all at once) and can
auto-requeue stale failed jobs via the scheduled task runner — all from the
admin panel.

> **Flarum 1.x** is still supported by the v1 releases of this extension.
> Version 2.x targets Flarum `^2.0` and PHP `^8.3`.

```
toreador/flarum-job-queue
```

## Installation

```
composer require toreador/flarum-job-queue
```

Then enable the extension:

```
php flarum extension:enable toreador-job-queue
php flarum cache:clear
```

> Local path install (development): add a path repository and require it:
> `"repositories": [{"type": "path", "url": "path/to/flarum-job-queue"}]`
> then `composer require toreador/flarum-job-queue:*`.

If you installed from source, rebuild the admin JS bundle with
`cd js && npm run build` (the committed `js/dist` is used by releases).

## What it manages

Flarum's built-in database queue driver (since Flarum 2.x, no extra extension
is needed) stores jobs in two tables that are auto-prefixed with the value from
`config.php` `database.prefix` (e.g. `toreador34_`):

| Table | Purpose |
|---|---|
| `{prefix}queue_jobs` | Pending (queued) jobs, processed by the worker |
| `{prefix}queue_failed_jobs` | Jobs that exhausted their retry count, moved here by the failed-job controller |

This extension reads the **live** prefix from the active database connection,
so it works out of the box with any prefix. If you ever need to point it at a
different prefix you can override it with the setting
`toreador-flarum-job-queue.table_prefix`.

## Admin page

Open **Administration → Queue Manager**:

- Stats cards: pending jobs, failed jobs, table names actually in use.
- Toolbar: search, filter by queue, requeue all failed, clear all failed,
  auto-refresh toggle.
- Table: id, uuid, queue, display name, what the job does, human-readable
  details (recipients, notification type, discussion, e-mail subject), attempts/
  max tries, failed at and per-row actions (requeue, delete, view).
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

Auto-requeue on schedule is enabled with the admin settings:

- `toreador-flarum-job-queue.auto_requeue` — requeue old failed jobs on schedule
- `toreador-flarum-job-queue.auto_requeue_after` (minutes) — only jobs older than
  this are requeued (default `5`; `0` disables the age filter)
- `toreador-flarum-job-queue.auto_requeue_max_attempts` — how many times the
  scheduler may requeue the same job (default `1`; `0` = no limit). Once a job
  reaches the limit it stays in the failed list until you requeue it manually,
  so a permanently failing job cannot loop forever and spam notifications or
  emails.

Loop protection works because every requeue increments a `requeue_attempts`
counter inside the job payload. Laravel stores the raw payload when the job
fails again, so the counter survives the requeue → fail → failed-jobs cycle.
The counter is ignored by the queue worker and only read by the extension.

The scheduled command requeues nothing when `auto_requeue` is off. Explicit
`queue:failed-jobs:requeue <ids...>` (and the manual buttons in the admin
panel) always work, regardless of the limit.

## What the requeue does

Equivalent SQL (assuming prefix `toreador34_`, replacing `:now` with
`UNIX_TIMESTAMP()` on MySQL):

```sql
INSERT INTO toreador34_queue_jobs
    (uuid, queue, payload, attempts, reserved_at, available_at, created_at)
SELECT uuid, queue, payload, 0, NULL,
       :now,                                  -- available_at = now()
       :now                                   -- created_at = now()
FROM toreador34_queue_failed_jobs
WHERE id = :failedJobId;                      -- or WHERE 1=1 for "requeue all"

DELETE FROM toreador34_queue_failed_jobs WHERE id = :failedJobId;
```

Notes (all defensive, this is what the extension actually does):

- Columns are only touched when they exist on the live table. On installs where
  the jobs table has no `uuid` column that write is omitted and a fresh
  `Str::uuid()` is used instead.
- Preserving the original `id` keeps the job's position: the extension inserts
  with the original id and lets the database pick a new one if it is taken.
- All statements run inside a transaction; if the insert fails the failed row
  is left untouched.

## API endpoints

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/queue-manager/jobs` | Stats + start page of failed jobs |
| GET | `/api/queue-manager/jobs/:id` | Single job detail (payload + exception) |
| POST | `/api/queue-manager/jobs/:id/requeue` | Requeue one failed job |
| POST | `/api/queue-manager/jobs/requeue` | Requeue all (optionally `?queue=`) |
| DELETE | `/api/queue-manager/jobs/:id` | Delete one failed job record |
| POST | `/api/queue-manager/jobs/clear` | Delete all (optionally `?queue=`) |

## Requirements

- PHP `^8.3`
- Flarum `^2.0`
- Database queue driver enabled (`'queue' => ['driver' => 'database']` in
  `config.php`)
- Flarum scheduler (or cron running `php flarum schedule:run`) for the
  auto-requeue command

## About this fork

- Composer package: `toreador/flarum-job-queue`
- GitHub: https://github.com/toreador34/flarum-job-queue
- Packagist: https://packagist.org/packages/toreador/flarum-job-queue

## License

MIT. See [LICENSE](LICENSE).