<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Pdo\Mysql;
use Tests\TestCase;

/**
 * The web server caps each SELECT with DB_MAX_EXECUTION_TIME_MS (set only in
 * compose's app service), and a query stopped by it gets a message instead
 * of the bare "Server Error".
 */
class DatabaseQueryTimeLimitTest extends TestCase
{
    private const string VARIABLE = 'DB_MAX_EXECUTION_TIME_MS';

    /** @var array{server: mixed, env: mixed, putenv: string|false} */
    private array $originalVariable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalVariable = [
            'server' => $_SERVER[self::VARIABLE] ?? null,
            'env' => $_ENV[self::VARIABLE] ?? null,
            'putenv' => getenv(self::VARIABLE),
        ];
    }

    protected function tearDown(): void
    {
        $this->setVariable($this->originalVariable['server']);
        putenv($this->originalVariable['putenv'] === false ? self::VARIABLE : self::VARIABLE.'='.$this->originalVariable['putenv']);
        DB::purge('mysql');

        parent::tearDown();
    }

    public function test_the_limit_is_set_on_connect_only_when_configured(): void
    {
        $this->setVariable(null);
        $unset = (require config_path('database.php'))['connections']['mysql']['options'];
        $this->setVariable('10000');
        $set = (require config_path('database.php'))['connections']['mysql']['options'];

        $this->assertArrayNotHasKey(Mysql::ATTR_INIT_COMMAND, $unset);
        $this->assertSame('SET SESSION max_execution_time = 10000', $set[Mysql::ATTR_INIT_COMMAND]);
    }

    public function test_a_select_over_the_limit_is_stopped_with_a_message(): void
    {
        config(['database.connections.mysql.options' => [
            Mysql::ATTR_INIT_COMMAND => 'SET SESSION max_execution_time = 50',
        ] + config('database.connections.mysql.options')]);
        DB::purge('mysql');
        // Billions of rows: far longer than 50ms on any server.
        Route::get('api/v1/test-slow-query', fn () => DB::select(
            'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c',
        ));

        $this->assertSame(50, DB::scalar('SELECT @@SESSION.max_execution_time'));
        $this->getJson('/api/v1/test-slow-query')
            ->assertInternalServerError()
            ->assertExactJson(['message' => '検索に時間がかかりすぎたため、中止しました。条件を絞って、もう一度お試しください。']);
    }

    /**
     * Sets (or with null, removes) the variable everywhere env() looks.
     */
    private function setVariable(?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[self::VARIABLE], $_ENV[self::VARIABLE]);
            putenv(self::VARIABLE);

            return;
        }

        $_SERVER[self::VARIABLE] = $_ENV[self::VARIABLE] = $value;
        putenv(self::VARIABLE.'='.$value);
    }
}
