<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager\Api\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ListFailedJobsController extends AbstractQueueManagerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);

        $query = $request->getQueryParams();

        $limit = isset($query['limit']) ? (int) $query['limit'] : 20;
        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }

        $offset = isset($query['offset']) ? max(0, (int) $query['offset']) : 0;

        $queue = isset($query['filter']['queue']) ? (string) $query['filter']['queue'] : null;
        $search = isset($query['filter']['qtext']) ? (string) $query['filter']['qtext'] : null;

        $list = $this->failedJobs->list($limit, $offset, $queue, $search);

        $data = [];
        foreach ($list['rows'] as $row) {
            $presented = $this->failedJobs->present($row);
            if ($presented) {
                $data[] = $presented;
            }
        }

        $tablesExist = $this->failedJobs->tablesExist();
        $tables = $this->failedJobs->getTables();

        return $this->json([
            'data' => $data,
            'total' => $list['total'],
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $offset + count($data) < $list['total'],
            'stats' => $this->failedJobs->stats(),
            'prefix' => $tables->getPrefix(),
            'tablesExist' => $tablesExist,
            'tables' => [
                'failed' => $tables->failedTable(),
                'jobs' => $tables->jobsTable(),
            ],
        ]);
    }
}