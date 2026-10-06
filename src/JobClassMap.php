<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager;

use Flarum\Locale\Translator;
use Throwable;

/**
 * Tries to explain what a failed job actually does.
 *
 * The raw serialized payload is opaque, so we only ever work with the JSON
 * shell that Laravel stores in the `payload` column and with the class name
 * embedded in the serialized command string. We never unserialize untrusted
 * data (object injection risk).
 */
class JobClassMap
{
    /**
     * @var Translator
     */
    protected $translator;

    /**
     * Known job classes -> translation key fragments.
     *
     * @var array<string, string>
     */
    protected $known = [
        'Illuminate\\Mail\\SendQueuedMailable' => 'mail',
        'Illuminate\\Queue\\CallQueuedHandler' => 'call_queued',
        'Closure' => 'closure',
    ];

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Describe a failed job payload.
     *
     * @param string $payload Raw JSON payload stored in the queue table.
     * @return array{display_name: string, command_class: string, description: string}
     */
    public function describe(string $payload): array
    {
        $decoded = $this->decodePayload($payload);

        $displayName = '';
        $commandClass = '';
        $maxTries = null;
        $timeout = null;
        $backoff = null;

        if (is_array($decoded)) {
            $displayName = isset($decoded['displayName']) ? (string) $decoded['displayName'] : '';

            $commandName = isset($decoded['data']['commandName']) ? (string) $decoded['data']['commandName'] : '';
            $serializedCommand = isset($decoded['data']['command']) && is_string($decoded['data']['command'])
                ? $decoded['data']['command']
                : '';

            $commandClass = $this->extractCommandClass($commandName, $serializedCommand, $displayName);

            $maxTries = isset($decoded['maxTries']) && $decoded['maxTries'] !== null
                ? (int) $decoded['maxTries']
                : null;
            $timeout = isset($decoded['timeout']) && $decoded['timeout'] !== null
                ? (int) $decoded['timeout']
                : null;
            $backoff = isset($decoded['backoff']) && $decoded['backoff'] !== null
                ? (int) $decoded['backoff']
                : null;
        }

        $description = $this->describeClass($commandClass ?: $displayName);

        return [
            'display_name' => $displayName,
            'command_class' => $commandClass,
            'description' => $description,
            'max_tries' => $maxTries,
            'timeout' => $timeout,
            'backoff' => $backoff,
        ];
    }

    protected function decodePayload(string $payload)
    {
        try {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function extractCommandClass(string $commandName, string $serializedCommand, string $displayName): string
    {
        if ($commandName !== '') {
            return $commandName;
        }

        if (preg_match('/O(?::\d+)?:"([^"]+)"/', $serializedCommand, $matches)) {
            return $matches[1];
        }

        return $displayName;
    }

    protected function describeClass(string $class): string
    {
        $class = ltrim($class, '\\');

        if ($class === '') {
            return $this->translator->trans('toreador-flarum-job-queue.job.unknown');
        }

        if (isset($this->known[$class])) {
            return $this->translator->trans('toreador-flarum-job-queue.job.'.$this->known[$class]);
        }

        $short = $this->shortName($class);
        $namespace = $this->namespace($class);

        // Namespace-segment hints first, so "App\Notifications\OrderShipped"
        // is recognised even though we can't know every application class.
        $namespaceSegments = $namespace === '' ? [] : explode('\\', $namespace);
        $namespaceMap = [
            'Notifications' => 'notification',
            'Mail' => 'mail',
            'Sitemap' => 'sitemap',
        ];
        foreach ($namespaceMap as $segment => $key) {
            if (in_array($segment, $namespaceSegments, true)) {
                return $this->translator->trans('toreador-flarum-job-queue.job.'.$key, ['name' => $short]);
            }
        }

        // Then class-name hints.
        $map = [
            'Email' => 'email',
            'Mail' => 'mail',
            'Telegram' => 'telegram',
            'Notification' => 'notification',
            'Webhook' => 'webhook',
            'Sitemap' => 'sitemap',
            'Reindex' => 'reindex',
            'Backup' => 'backup',
            'Import' => 'import',
            'Export' => 'export',
            'Sync' => 'sync',
        ];

        foreach ($map as $needle => $key) {
            if (stripos($short, $needle) !== false) {
                return $this->translator->trans('toreador-flarum-job-queue.job.'.$key, ['name' => $short]);
            }
        }

        return $this->translator->trans('toreador-flarum-job-queue.job.generic', ['name' => $short]);
    }

    protected function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    protected function namespace(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? '' : substr($class, 0, $pos);
    }
}