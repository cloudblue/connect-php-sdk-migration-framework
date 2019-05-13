<?php

namespace Test\Unit;

use Closure;
use Connect\Config;
use Connect\Middleware\Migration\Exceptions\MigrationParameterFailException;
use Connect\Middleware\Migration\Exceptions\MigrationParameterPassException;
use Connect\Middleware\Migration\Handler;
use Connect\Request;
use Connect\Skip;
use Mockery;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Class MigrationTest
 * @package Test
 */
class MigrationTest extends TestCase
{
    /**
     * @return Handler
     */
    public function testDefaultInstantiation()
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $logger->shouldReceive('error');
        $logger->shouldReceive('debug');

        $config = Mockery::mock(Config::class);

        $m = new Handler([
            'logger' => $logger,
            'config' => $config,
            'validation' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                // do some validation operation
            },
            'onSuccess' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                // do some success operation
            }
        ]);

        $this->assertInstanceOf(Handler::class, $m);
        $this->assertInstanceOf(LoggerInterface::class, $m->getLogger());
        $this->assertInstanceOf(Config::class, $m->getConfig());
        $this->assertInternalType('string', $m->getMigrationFlag());
        $this->assertEquals('migration_info', $m->getMigrationFlag());
        $this->assertInternalType('array', $m->getTransformations());
        $this->assertCount(0, $m->getTransformations());
        $this->assertNull($m->getTransformation('fake-param'));

        $this->assertInstanceOf(Closure::class, $m->getValidation());
        $this->assertInstanceOf(Closure::class, $m->getOnSuccess());

        return $m;
    }

    /**
     * @return Handler
     */
    public function testCustomInstantiation()
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $logger->shouldReceive('error');
        $logger->shouldReceive('debug');

        $m = new Handler([
            'logger' => $logger,
            'migrationFlag' => 'some_migration_param',
            'transformations' => [
                'email' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                    return strtolower($migrationData->teamAdminEmail);
                }
            ]
        ]);

        $m->setTransformation('name',
            function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                return ucfirst($migrationData->name);
            });

        $this->assertInstanceOf(Handler::class, $m);
        $this->assertInstanceOf(LoggerInterface::class, $m->getLogger());
        $this->assertInternalType('string', $m->getMigrationFlag());
        $this->assertEquals('some_migration_param', $m->getMigrationFlag());
        $this->assertInternalType('array', $m->getTransformations());
        $this->assertCount(2, $m->getTransformations());
        $this->assertInstanceOf(Closure::class, $m->getTransformation('email'));
        $this->assertInstanceOf(Closure::class, $m->getTransformation('name'));

        return $m;
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     */
    public function testIsMigration(Handler $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.valid.json'));
        $this->assertTrue($m->isMigration($request));

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.empty.json'));
        $this->assertFalse($m->isMigration($request));

        $request = new Request($this->getJSON(__DIR__ . '/request.valid.json'));
        $this->assertFalse($m->isMigration($request));
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateOnNonMigrableRequest(Handler $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.valid.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Migration failed with Custom onFail function.
     *
     * @throws \Connect\Skip
     */
    public function testMigrateWithInvalidMigrationData()
    {

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        $logger->shouldReceive('error');
        $logger->shouldReceive('debug');

        $config = Mockery::mock(Config::class);

        $m = new Handler([
            'logger' => $logger,
            'config' => $config,
            'validation' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                // do some validation operation
            },
            'onSuccess' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                // do some success operation
            },
            'onFail' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                throw new Skip('Migration failed with Custom onFail function.');
            }
        ]);

        $this->assertInstanceOf(Closure::class, $m->getOnFail());

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.invalid.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Migration failed.
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateDirectMapWithParameterNotSerializedFail(Handler $m)
    {
        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.notserialized.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertEquals(spl_object_hash($request), spl_object_hash($migrated));
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateDirectMapWithParameterNotSerializedSuccess(Handler $m)
    {
        $this->assertFalse($m->getSerialize());
        $m->setSerialize(true);

        $this->assertTrue($m->getSerialize());

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.notserialized.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            '["Some name"]',
            $migrated->asset->getParameterByID('team_name')->value
        );

        $migratedTeamName = json_decode($migrated->asset->getParameterByID('team_name')->value);

        $this->assertInternalType('array', $migratedTeamName);
        $this->assertCount(1, $migratedTeamName);
        $this->assertEquals('Some name', $migratedTeamName[0]);


        $m->setSerialize(false);
        $this->assertFalse($m->getSerialize());
    }

    /**
     * @depends testDefaultInstantiation
     * @expectedException \Connect\Skip
     * @expectedExceptionMessage Migration failed.
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateTransformationMapManualFail(Handler $m)
    {
        $m->setTransformation('team_id',
            function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                throw new MigrationParameterFailException('Manual fail');
            });

        $this->assertInstanceOf(Closure::class, $m->getTransformation('team_id'));

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.transformation.manualfail.json'));
        $m->migrate($request);
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateDirectMapSuccess(Handler $m)
    {
        $this->assertTrue($m->unsetTransformation('team_id'));
        $this->assertNull($m->getTransformation('team_id'));

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.direct.success.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            'example.migration@mailinator.com',
            $migrated->asset->getParameterByID('email')->value
        );

        $this->assertEquals(
            'dbtid:AADaQq_w53nMDQbIPM_X123456PuzpcM2BI',
            $migrated->asset->getParameterByID('team_id')->value
        );

        $this->assertEquals(
            'Migration Team',
            $migrated->asset->getParameterByID('team_name')->value
        );

        $this->assertEquals(
            '10',
            $migrated->asset->getParameterByID('num_licensed_users')->value
        );
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateTransformationMapSuccess(Handler $m)
    {
        $m->setTransformations([
            'email' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                return strtoupper($migrationData->teamAdminEmail);
            },
            'team_id' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                return strtoupper($migrationData->teamId);
            },
            'team_name' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                return strtoupper($migrationData->teamName);
            },
            'num_licensed_users' => function (
                $migrationData,
                Request $request,
                Config $config,
                LoggerInterface $logger
            ) {
                return (int)$migrationData->licNumber * 10;
            }
        ]);

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.transformation.success.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            strtoupper('example.migration@mailinator.com'),
            $migrated->asset->getParameterByID('email')->value
        );

        $this->assertEquals(
            strtoupper('dbtid:AADaQq_w53nMDQbIPM_X123456PuzpcM2BI'),
            $migrated->asset->getParameterByID('team_id')->value
        );

        $this->assertEquals(
            strtoupper('Migration Team'),
            $migrated->asset->getParameterByID('team_name')->value
        );

        $this->assertEquals(
            10 * 10,
            $migrated->asset->getParameterByID('num_licensed_users')->value
        );
    }

    /**
     * @depends testDefaultInstantiation
     *
     * @param Handler $m
     * @throws Skip
     */
    public function testMigrateTransformationMapByPass(Handler $m)
    {
        $m->setTransformations([
            'email' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                throw new MigrationParameterPassException('Not necessary');
            },
            'team_id' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                return strtoupper($migrationData->teamId);
            },
        ]);

        $request = new Request($this->getJSON(__DIR__ . '/request.migrate.transformation.success.json'));
        $migrated = $m->migrate($request);
        $this->assertInstanceOf(Request::class, $migrated);
        $this->assertNotEquals(spl_object_hash($request), spl_object_hash($migrated));

        $this->assertEquals(
            strtoupper('dbtid:AADaQq_w53nMDQbIPM_X123456PuzpcM2BI'),
            $migrated->asset->getParameterByID('team_id')->value
        );
    }
}