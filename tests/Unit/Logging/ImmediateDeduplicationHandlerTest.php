<?php

namespace Tests\Unit\Logging;

use App\Logging\ImmediateDeduplicationHandler;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ImmediateDeduplicationHandlerTest extends TestCase
{
    private string $store;

    private TestHandler $sent;

    private ImmediateDeduplicationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = tempnam(sys_get_temp_dir(), 'alert-dedup-');
        unlink($this->store);
        $this->sent = new TestHandler;
        $this->handler = new ImmediateDeduplicationHandler($this->sent, $this->store, Level::Error, 3600);
    }

    protected function tearDown(): void
    {
        @unlink($this->store);

        parent::tearDown();
    }

    public function test_a_record_is_passed_on_without_waiting_for_the_handler_to_close(): void
    {
        $this->handler->handle($this->record('rhb:download: 北海道厚生局: 一覧ページの解析に失敗しました'));

        $this->assertCount(1, $this->sent->getRecords());
    }

    public function test_the_same_message_within_the_window_is_passed_on_once(): void
    {
        $this->handler->handle($this->record('rhb:download: 北海道厚生局: 一覧ページの解析に失敗しました'));
        $this->handler->handle($this->record('rhb:download: 北海道厚生局: 一覧ページの解析に失敗しました'));
        $this->handler->handle($this->record('rhb:download: 東北厚生局: 一覧ページの解析に失敗しました'));

        $this->assertSame(
            ['rhb:download: 北海道厚生局: 一覧ページの解析に失敗しました', 'rhb:download: 東北厚生局: 一覧ページの解析に失敗しました'],
            array_map(fn (LogRecord $record): string => $record->message, $this->sent->getRecords()),
        );
    }

    public function test_exceptions_thrown_at_the_same_place_are_duplicates_even_when_their_messages_differ(): void
    {
        $first = $this->exceptionFromTheSamePlace('select * from medical_facilities where id = 1');
        $second = $this->exceptionFromTheSamePlace('select * from medical_facilities where id = 2');

        $this->handler->handle($this->record($first->getMessage(), ['exception' => $first]));
        $this->handler->handle($this->record($second->getMessage(), ['exception' => $second]));
        $this->handler->handle($this->record('elsewhere', ['exception' => new RuntimeException('elsewhere')]));

        $this->assertCount(2, $this->sent->getRecords());
    }

    public function test_exceptions_thrown_inside_a_dependency_are_told_apart_by_where_the_project_called_it(): void
    {
        // Both are thrown by the same vendor line, as every QueryException is.
        $fromOneCall = $this->catch(fn () => (new Collection)->firstOrFail());
        $fromAnotherCall = $this->catch(fn () => (new Collection)->firstOrFail());
        $this->assertSame($fromOneCall->getFile().':'.$fromOneCall->getLine(), $fromAnotherCall->getFile().':'.$fromAnotherCall->getLine());

        $this->handler->handle($this->record('first', ['exception' => $fromOneCall]));
        $this->handler->handle($this->record('second', ['exception' => $fromAnotherCall]));

        $this->assertCount(2, $this->sent->getRecords());
    }

    public function test_a_duplicate_is_passed_on_again_after_the_window(): void
    {
        $this->handler->handle($this->record('rhb:status: 1件の問題があります。'));
        $this->handler->handle($this->record('rhb:status: 1件の問題があります。', at: new DateTimeImmutable('+3601 seconds')));

        $this->assertCount(2, $this->sent->getRecords());
    }

    public function test_the_window_is_shared_through_the_store_by_other_processes(): void
    {
        $this->handler->handle($this->record('rhb:status: 1件の問題があります。'));

        $otherProcess = new TestHandler;
        (new ImmediateDeduplicationHandler($otherProcess, $this->store, Level::Error, 3600))
            ->handle($this->record('rhb:status: 1件の問題があります。'));

        $this->assertCount(0, $otherProcess->getRecords());
    }

    public function test_records_below_the_level_are_not_handled(): void
    {
        $this->assertFalse($this->handler->isHandling($this->record('rhb:import: dataset finished', level: Level::Info)));
        $this->assertTrue($this->handler->isHandling($this->record('rhb:import: failed', level: Level::Error)));
        $this->assertTrue($this->handler->isHandling($this->record('database down', level: Level::Critical)));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $message, array $context = [], Level $level = Level::Error, ?DateTimeImmutable $at = null): LogRecord
    {
        return new LogRecord($at ?? new DateTimeImmutable, 'alert', $level, $message, $context);
    }

    private function catch(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('The callback did not throw.');
    }

    private function exceptionFromTheSamePlace(string $message): Throwable
    {
        return new RuntimeException($message);
    }
}
