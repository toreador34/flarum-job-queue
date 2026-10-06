<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager;

use Flarum\Locale\Translator;
use Illuminate\Database\ConnectionInterface;
use Toreador\QueueManager\Queue\QueueTables;

/**
 * Turns the opaque serialized command inside a queue payload into a small
 * list of human-readable facts (recipients, notification type, discussion,
 * e-mail subject, ...).
 *
 * The serialized data is never passed to unserialize(): it is only scanned
 * with a length-aware parser that collects scalar properties and class
 * names. This avoids object injection while still being exact about PHP's
 * serialization format.
 */
class JobPayloadInspector
{
    protected const MAX_DEPTH = 8;
    protected const MAX_PROPS = 300;
    protected const MAX_CLASSES = 100;
    protected const MAX_LIST = 25;
    protected const VALUE_PREVIEW = 160;

    /**
     * Keys that only repeat what the table already shows.
     *
     * @var array<int, string>
     */
    protected const IGNORED_KEYS = [
        'uuid', 'commandname', 'displayname', 'job', 'maxTries', 'maxExceptions',
        'failOnTimeout', 'backoff', 'timeout', 'retryUntil', 'id', 'queue',
        'payload', 'exception', 'connection',
    ];

    /**
     * @var ConnectionInterface
     */
    protected $db;

    /**
     * @var QueueTables
     */
    protected $tables;

    /**
     * @var Translator
     */
    protected $translator;

    /**
     * @var array<int, string>
     */
    protected $userCache = [];

    /**
     * @var array<int, string>
     */
    protected $discussionCache = [];

    public function __construct(ConnectionInterface $db, QueueTables $tables, Translator $translator)
    {
        $this->db = $db;
        $this->tables = $tables;
        $this->translator = $translator;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function inspect(string $payload): array
    {
        $decoded = json_decode($payload, true);

        $command = is_array($decoded) && isset($decoded['data']['command']) && is_string($decoded['data']['command'])
            ? $decoded['data']['command']
            : '';

        if ($command === '') {
            return [];
        }

        $classes = [];
        $props = [];

        $this->parseValue($command, 0, 0, $classes, $props, null);

        return $this->buildDetails($classes, $props);
    }

    /**
     * Length-aware scan of one serialized value.
     *
     * @param array<int, string>                                  $classes
     * @param array<int, array{key: string, value: int|float|string|bool}> $props
     * @return array{0: mixed, 1: int} [value, next offset]
     */
    protected function parseValue(string $s, int $i, int $depth, array &$classes, array &$props, ?string $key)
    {
        $length = strlen($s);

        if ($i >= $length || $i < 0 || $depth > self::MAX_DEPTH || count($props) > self::MAX_PROPS) {
            return [null, $length];
        }

        switch ($s[$i]) {
            case 'N':
                return [null, $i + 2];

            case 'b':
                $value = ($s[$i + 2] ?? '0') === '1';
                $this->record($props, $key, $value);

                return [$value, $i + 4];

            case 'i':
                $end = strpos($s, ';', $i);
                if ($end === false) {
                    return [null, $length];
                }
                $value = (int) substr($s, $i + 2, $end - $i - 2);
                $this->record($props, $key, $value);

                return [$value, $end + 1];

            case 'd':
                $end = strpos($s, ';', $i);
                if ($end === false) {
                    return [null, $length];
                }
                $value = (float) substr($s, $i + 2, $end - $i - 2);
                $this->record($props, $key, $value);

                return [$value, $end + 1];

            case 's':
                $colon = strpos($s, ':', $i + 2);
                if ($colon === false) {
                    return [null, $length];
                }
                $stringLength = (int) substr($s, $i + 2, $colon - $i - 2);
                $start = $colon + 2;
                if ($stringLength < 0 || $start + $stringLength > $length) {
                    return [null, $length];
                }
                $value = substr($s, $start, $stringLength);
                $this->record($props, $key, $value);

                return [$value, $start + $stringLength + 2];

            case 'a':
                $open = strpos($s, '{', $i);
                if ($open === false) {
                    return [null, $length];
                }
                $count = (int) substr($s, $i + 2, $open - $i - 3);
                $i = $open + 1;

                for ($n = 0; $n < $count && $n < self::MAX_PROPS; $n++) {
                    [$itemKey, $i] = $this->parseValue($s, $i, $depth + 1, $classes, $props, null);

                    $childKey = is_string($itemKey) && ! is_numeric($itemKey) ? $itemKey : $key;

                    [, $i] = $this->parseValue($s, $i, $depth + 1, $classes, $props, $childKey);
                }

                if (($s[$i] ?? '') === '}') {
                    $i++;
                }

                return [null, $i];

            case 'O':
            case 'C':
                $isCustom = $s[$i] === 'C';
                $class = $this->readClassName($s, $i, $open);
                if ($class === null) {
                    return [null, $length];
                }

                if (count($classes) < self::MAX_CLASSES) {
                    $classes[] = $class;
                }

                // Between the closing quote of the class name and `{` sits
                // either `:<count>:` (objects) or `:<dataLen>:` (custom).
                $closeQuote = strrpos(substr($s, 0, $open), '"');
                $middle = $closeQuote === false ? '' : substr($s, $closeQuote + 1, $open - $closeQuote - 1);
                $count = (int) trim($middle, ':');

                if ($isCustom) {
                    // The payload is opaque; skip dataLen bytes and the brace.
                    $i = $open + 1 + $count + 1;

                    return [null, $i];
                }

                $i = $open + 1;
                for ($n = 0; $n < $count && $n < self::MAX_PROPS; $n++) {
                    [$propKey, $i] = $this->parseValue($s, $i, $depth + 1, $classes, $props, null);

                    if (! is_string($propKey)) {
                        // Malformed; stop rather than misread the rest.
                        break;
                    }

                    [, $i] = $this->parseValue($s, $i, $depth + 1, $classes, $props, $propKey);
                }

                if (($s[$i] ?? '') === '}') {
                    $i++;
                }

                return [null, $i];
        }

        // Unknown token: abort.
        return [null, $length];
    }

    /**
     * Reads an object/custom class name and the offset of its `{`.
     */
    protected function readClassName(string $s, int $i, ?int &$open): ?string
    {
        $colon = strpos($s, ':', $i + 2);
        if ($colon === false) {
            return null;
        }

        $length = (int) substr($s, $i + 2, $colon - $i - 2);
        if ($length < 0 || ($s[$colon + 1] ?? '') !== '"') {
            return null;
        }

        $start = $colon + 2;
        if ($start + $length > strlen($s)) {
            return null;
        }

        $class = substr($s, $start, $length);
        $open = strpos($s, '{', $start + $length);
        if ($open === false) {
            return null;
        }

        return $class;
    }

    protected function record(array &$props, ?string $key, $value): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $key = preg_replace('/^\x00[^\x00]*\x00/', '', $key);
        if ($key === null || $key === '' || count($props) >= self::MAX_PROPS) {
            return;
        }

        if (is_string($value) && strlen($value) > 500) {
            $value = substr($value, 0, 500);
        }

        $props[] = ['key' => $key, 'value' => $value];
    }

    /**
     * @param array<int, string> $classes
     * @param array<int, array{key: string, value: mixed}> $props
     * @return array<int, array{label: string, value: string}>
     */
    protected function buildDetails(array $classes, array $props): array
    {
        $details = [];
        $consumed = [];
        $values = [];

        foreach ($props as $prop) {
            $values[strtolower($prop['key'])][] = $prop['value'];
        }

        $rootClass = $classes[0] ?? '';

        // Notification / mailable type from nested class names.
        $notification = '';
        $mailable = '';
        foreach ($classes as $index => $class) {
            if ($index === 0 && $class === $rootClass) {
                continue;
            }
            if ($notification === '' && preg_match('/Notification|Blueprint/', $class)) {
                $notification = $class;
            }
            if ($mailable === '' && preg_match('/Mail|Mailable/', $class)) {
                $mailable = $class;
            }
        }
        if ($mailable === '' && $notification === '' && preg_match('/Mail|Mailable/', $rootClass)) {
            $mailable = $rootClass;
        }

        if ($mailable !== '') {
            $details[] = $this->detail('mailable', $this->shortClass($mailable));
            $consumed[] = 'subject';
        }

        if ($notification !== '') {
            $details[] = $this->detail('notification', $this->shortClass($notification));
        }

        // Recipients: usernames/emails found in the payload plus resolvable ids.
        $recipients = [];
        foreach (['username', 'displayname'] as $key) {
            foreach ($values[$key] ?? [] as $value) {
                if (is_string($value) && $value !== '' && ! in_array($value, $recipients, true)) {
                    $recipients[] = $value;
                }
            }
            $consumed[] = $key;
        }
        foreach (['email', 'to', 'recipientemail'] as $key) {
            foreach ($values[$key] ?? [] as $value) {
                if (is_string($value) && strpos($value, '@') !== false && ! in_array($value, $recipients, true)) {
                    $recipients[] = $value;
                }
            }
            $consumed[] = $key;
        }

        $userIds = $this->collectIds($values, ['userid', 'user_id', 'recipientid', 'recipient_id', 'actorid', 'fromid', 'user']);
        foreach ($userIds as $id) {
            $name = $this->resolveUser($id);
            if ($name !== '' && ! in_array($name, $recipients, true)) {
                $recipients[] = $name;
            }
        }
        foreach (['userid', 'user_id', 'recipientid', 'recipient_id', 'actorid', 'fromid', 'user'] as $key) {
            $consumed[] = $key;
        }

        if ($recipients) {
            $details[] = $this->detail('recipients', $this->joinList($recipients));
        }

        // Discussion / topic.
        $discussionTitle = '';
        foreach (['discussiontitle', 'title'] as $key) {
            foreach ($values[$key] ?? [] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $discussionTitle = $this->preview($value);
                    break 2;
                }
            }
        }
        $discussionIds = $this->collectIds($values, ['discussionid', 'discussion_id', 'discussion']);
        foreach ($discussionIds as $id) {
            if ($discussionTitle === '') {
                $discussionTitle = $this->resolveDiscussion($id);
            }
        }
        if ($discussionTitle !== '') {
            $suffix = $discussionIds ? ' (#'.$discussionIds[0].')' : '';
            $details[] = $this->detail('discussion', $discussionTitle.$suffix);
        }
        foreach (['discussiontitle', 'title', 'discussionid', 'discussion_id', 'discussion'] as $key) {
            $consumed[] = $key;
        }

        // Post.
        $postIds = $this->collectIds($values, ['postid', 'post_id', 'post']);
        if ($postIds) {
            $details[] = $this->detail('post', '#'.$postIds[0]);
        }
        foreach (['postid', 'post_id', 'post'] as $key) {
            $consumed[] = $key;
        }

        // Delivery channels.
        $channels = [];
        foreach ($values['channels'] ?? [] as $value) {
            if (is_string($value) && $value !== '' && ! in_array($value, $channels, true)) {
                $channels[] = $value;
            }
        }
        if ($channels) {
            $details[] = $this->detail('channels', $this->joinList($channels));
            $consumed[] = 'channels';
        }

        // Mail subject.
        if ($title = $this->firstString($values, ['subject', 'mailsubject'])) {
            $details[] = $this->detail('subject', $this->preview($title));
            $consumed[] = 'subject';
            $consumed[] = 'mailsubject';
        }

        // Message preview.
        if ($message = $this->firstString($values, ['message', 'text', 'body', 'content', 'html'])) {
            $details[] = $this->detail('message', $this->preview(strip_tags($message)));
            $consumed[] = 'message';
            $consumed[] = 'text';
            $consumed[] = 'body';
            $consumed[] = 'content';
            $consumed[] = 'html';
        }

        // Anything else worth showing.
        $extra = 0;
        foreach ($props as $prop) {
            if ($extra >= 6) {
                break;
            }
            $key = strtolower($prop['key']);
            if (in_array($key, $consumed, true) || in_array($prop['key'], self::IGNORED_KEYS, true)) {
                continue;
            }
            if (! is_scalar($prop['value']) || $prop['value'] === '' || $prop['value'] === null) {
                continue;
            }
            $value = is_bool($prop['value']) ? ($prop['value'] ? 'yes' : 'no') : $this->preview((string) $prop['value']);
            $details[] = ['label' => $this->humanize($prop['key']), 'value' => $value];
            $extra++;
        }

        return $details;
    }

    /**
     * @param array<string, array<int, mixed>> $values
     * @param array<int, string>               $keys
     * @return array<int, string>
     */
    protected function firstString(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ($values[$key] ?? [] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, array<int, mixed>> $values
     * @param array<int, string>               $keys
     * @return array<int, int>
     */
    protected function collectIds(array $values, array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            foreach ($values[$key] ?? [] as $value) {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $ids[] = (int) $value;
                } elseif (is_array($value)) {
                    foreach ($value as $nested) {
                        if (is_int($nested) || (is_string($nested) && ctype_digit($nested))) {
                            $ids[] = (int) $nested;
                        }
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_LIST);
    }

    protected function resolveUser(int $id): string
    {
        if (! array_key_exists($id, $this->userCache)) {
            $this->userCache[$id] = '';
            try {
                $row = $this->db->selectOne(
                    'SELECT username FROM '.$this->tables->getPrefix().'users WHERE id = ?',
                    [$id]
                );
                if ($row && ! empty($row->username)) {
                    $this->userCache[$id] = (string) $row->username.' (#'.$id.')';
                }
            } catch (\Throwable $e) {
                // ignore; the id alone is still shown by the job column
            }
        }

        return $this->userCache[$id];
    }

    protected function resolveDiscussion(int $id): string
    {
        if (! array_key_exists($id, $this->discussionCache)) {
            $this->discussionCache[$id] = '';
            try {
                $row = $this->db->selectOne(
                    'SELECT title FROM '.$this->tables->getPrefix().'discussions WHERE id = ?',
                    [$id]
                );
                if ($row && ! empty($row->title)) {
                    $this->discussionCache[$id] = (string) $row->title;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $this->discussionCache[$id];
    }

    /**
     * @param array<int, string> $values
     */
    protected function joinList(array $values): string
    {
        $values = array_slice($values, 0, self::MAX_LIST);

        return implode(', ', $values).(count($values) >= self::MAX_LIST ? '…' : '');
    }

    protected function preview(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') <= self::VALUE_PREVIEW) {
            return $value;
        }

        return mb_substr($value, 0, self::VALUE_PREVIEW, 'UTF-8').'…';
    }

    protected function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    protected function humanize(string $key): string
    {
        $key = str_replace(['_', '-'], ' ', $key);
        $key = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $key);

        return ucfirst(trim($key ?? ''));
    }

    /**
     * @return array{label: string, value: string}
     */
    protected function detail(string $key, string $value): array
    {
        return [
            'label' => (string) $this->translator->trans('toreador-flarum-job-queue.job.detail.'.$key),
            'value' => $value,
        ];
    }
}
