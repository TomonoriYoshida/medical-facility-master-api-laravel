<?php

namespace App\Logging;

use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Builds the "alert" log channel: errors are posted to a Slack-compatible
 * webhook (Slack, or Discord's webhook URL with /slack appended), at most
 * once per error per window so a failing dependency doesn't flood the chat.
 */
class CreateAlertLogger
{
    /**
     * @param  array{url?: string|null, username: string, level: string, dedup_seconds: int|string}  $config
     */
    public function __invoke(array $config): Logger
    {
        $url = $config['url'] ?? null;

        if ($url === null || $url === '') {
            return new Logger('alert', [new NullHandler]);
        }

        $levelName = strtoupper($config['level']);

        if (! in_array($levelName, Level::NAMES, true)) {
            throw new InvalidArgumentException("Invalid log level: {$config['level']}");
        }

        $level = Level::fromName($levelName);

        $webhook = new SlackWebhookHandler(
            webhookUrl: $url,
            username: $config['username'],
            iconEmoji: 'rotating_light',
            includeContextAndExtra: true,
            level: $level,
        );
        $webhook->pushProcessor(new SummarizeForAlert);

        return new Logger('alert', [
            new ImmediateDeduplicationHandler(
                // A webhook that is down or rate limited must not turn the
                // original error into a second one.
                new WhatFailureGroupHandler([$webhook]),
                storage_path('logs/alert-deduplication.log'),
                $level,
                (int) $config['dedup_seconds'],
            ),
        ]);
    }
}
