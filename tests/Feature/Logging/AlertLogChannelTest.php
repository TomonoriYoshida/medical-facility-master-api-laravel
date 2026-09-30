<?php

namespace Tests\Feature\Logging;

use App\Logging\SummarizeForAlert;
use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

class AlertLogChannelTest extends TestCase
{
    public function test_an_exception_is_summarized_as_its_class_and_location_in_the_project(): void
    {
        $exception = new RuntimeException('boom');

        $record = (new SummarizeForAlert)($this->record('boom', ['exception' => $exception]));

        $this->assertSame(
            'RuntimeException (tests/Feature/Logging/AlertLogChannelTest.php:'.$exception->getLine().')',
            $record->context['exception'],
        );
    }

    public function test_long_values_are_cut_to_fit_a_discord_field(): void
    {
        $record = (new SummarizeForAlert)($this->record(str_repeat('あ', 3000), [
            'sql' => str_repeat('x', 3000),
            'problems' => array_fill(0, 200, '近畿厚生局 Pharmacy: 未取得'),
            'count' => 3,
        ]));

        $this->assertLessThanOrEqual(1503, mb_strlen($record->message));
        $this->assertLessThanOrEqual(503, mb_strlen($record->context['sql']));
        $this->assertStringStartsWith('["近畿厚生局 Pharmacy: 未取得",', $record->context['problems']);
        $this->assertLessThanOrEqual(503, mb_strlen($record->context['problems']));
        $this->assertSame(3, $record->context['count']);
    }

    public function test_logging_to_an_unreachable_webhook_does_not_throw(): void
    {
        $storage = sys_get_temp_dir().'/alert-channel-test-'.uniqid();
        mkdir("{$storage}/logs", recursive: true);
        $this->app->useStoragePath($storage);
        config(['logging.channels.alert.url' => 'http://127.0.0.1:9/unreachable']);

        Log::channel('alert')->error('rhb:status: 1件の問題があります。');

        // Reaching the webhook failed, but the error was still recorded as sent.
        $this->assertStringContainsString('ERROR:rhb:status: 1件の問題があります。', (string) file_get_contents("{$storage}/logs/alert-deduplication.log"));

        (new Filesystem)->deleteDirectory($storage);
    }

    public function test_the_channel_discards_records_without_a_webhook_url(): void
    {
        config(['logging.channels.alert.url' => null]);

        $handlers = Log::channel('alert')->getLogger()->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(NullHandler::class, $handlers[0]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(new DateTimeImmutable, 'alert', Level::Error, $message, $context);
    }
}
