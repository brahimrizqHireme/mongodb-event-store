<?php
/**
 * This file is part of the prooph/mongodb-event-store.
 * (c) 2018 prooph software GmbH <contact@prooph.de>
 * (c) 2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStore\MongoDb\Projection;

use MongoDB\Client;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception\InvalidArgumentException;
use Prooph\EventStore\MongoDb\MongoDbEventStore;
use Prooph\EventStore\MongoDb\MongoDbHelper;
use Prooph\EventStore\MongoDb\PersistenceStrategy;
use Prooph\EventStore\MongoDb\Projection\MongoDbEventStoreProjector;
use Prooph\EventStore\MongoDb\Projection\MongoDbProjectionManager;
use Prooph\EventStore\Projection\Projector;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;
use ProophTest\EventStore\MongoDb\TestUtil;
use ProophTest\EventStore\Projection\AbstractEventStoreProjectorTest;

abstract class AbstractMongoDbEventStoreProjectorTest extends AbstractEventStoreProjectorTest
{
    use MongoDbHelper;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $database;

    abstract protected function getPersistenceStrategy(): PersistenceStrategy;

    protected function setUp(): void
    {
        $this->client = TestUtil::getClient();
        $this->database = TestUtil::getDatabaseName();

        self::createEventStreamsCollection($this->client, TestUtil::getDatabaseName(), 'event_streams');
        self::createProjectionCollection($this->client, TestUtil::getDatabaseName(), 'projections');

        $this->eventStore = new MongoDbEventStore(
            new FQCNMessageFactory(),
            $this->client,
            $this->database,
            $this->getPersistenceStrategy()
        );
        $this->projectionManager = new MongoDbProjectionManager(
            $this->eventStore,
            $this->client,
            $this->getPersistenceStrategy(),
            new FQCNMessageFactory(),
            $this->database
        );
    }

    protected function tearDown(): void
    {
        TestUtil::tearDownDatabase();
    }

    /**
     * @test
     */
    public function it_handles_missing_projection_table(): void
    {
        $this->expectException(\Prooph\EventStore\MongoDb\Exception\RuntimeException::class);

        $this->prepareEventStream('user-123');

        $this->client->selectDatabase($this->database)->dropCollection('projections');

        $projection = $this->projectionManager->createProjection('test_projection');

        $projection
            ->fromStream('user-123')
            ->when([
                UserCreated::class => function (array $state, UserCreated $event): array {
                    $this->stop();

                    return $state;
                },
            ])
            ->run();
    }

    /**
     * @test
     * @small
     */
    public function it_stops_immediately_after_pcntl_signal_was_received(): void
    {
        if (! \extension_loaded('pcntl')) {
            $this->markTestSkipped('The PCNTL extension is not available.');

            return;
        }

        $command = 'exec php ' . \realpath(__DIR__) . '/isolated-long-running-projection.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /**
         * Created process inherits env variables from this process.
         * Script returns with non-standard code SIGUSR1 from the handler and -1 else
         */
        $projectionProcess = \proc_open($command, $descriptorSpec, $pipes);
        $processDetails = \proc_get_status($projectionProcess);
        \usleep(500000);
        \posix_kill($processDetails['pid'], SIGQUIT);
        \usleep(500000);

        $processDetails = \proc_get_status($projectionProcess);
        $this->assertEquals(
            SIGUSR1,
            $processDetails['exitcode']
        );
    }

    /**
     * @test
     */
    public function it_updates_state_using_when_and_persists_with_block_size(): void
    {
        $this->prepareEventStream('user-123');

        $testCase = $this;

        $projection = $this->projectionManager->createProjection('test_projection', [
            Projector::OPTION_PERSIST_BLOCK_SIZE => 10,
        ]);

        $projection
            ->fromAll()
            ->when([
                UserCreated::class => function ($state, Message $event) use ($testCase): array {
                    $testCase->assertEquals('user-123', $this->streamName());
                    $state['name'] = $event->payload()['name'];

                    return $state;
                },
                UsernameChanged::class => function ($state, Message $event) use ($testCase): array {
                    $testCase->assertEquals('user-123', $this->streamName());
                    $state['name'] = $event->payload()['name'];

                    if ($event->payload()['name'] === 'Sascha') {
                        $this->stop();
                    }

                    return $state;
                },
            ])
            ->run();

        $this->assertEquals('Sascha', $projection->getState()['name']);
    }

    /**
     * @test
     */
    public function it_handles_existing_projection_table(): void
    {
        $this->prepareEventStream('user-123');

        $projection = $this->projectionManager->createProjection('test_projection');

        $projection
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromAll()
            ->when([
                UsernameChanged::class => function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                },
            ])
            ->run(false);

        $this->assertEquals(49, $projection->getState()['count']);

        $projection->run(false);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_invalid_wrapped_event_store_instance_passed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown event store instance given');

        $eventStore = $this->prophesize(EventStore::class);
        $wrappedEventStore = $this->prophesize(EventStoreDecorator::class);
        $wrappedEventStore->getInnerEventStore()->willReturn($eventStore->reveal())->shouldBeCalled();

        new MongoDbEventStoreProjector(
            $wrappedEventStore->reveal(),
            $this->client,
            $this->getPersistenceStrategy(),
            new FQCNMessageFactory(),
            $this->database,
            'test_projection',
            'event_streams',
            'projections',
            1,
            1,
            1,
            1
        );
    }

    /**
     * @test
     */
    public function it_throws_exception_when_unknown_event_store_instance_passed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown event store instance given');

        $eventStore = $this->prophesize(EventStore::class);
        $client = $this->prophesize(Client::class);

        new MongoDbEventStoreProjector(
            $eventStore->reveal(),
            $client->reveal(),
            $this->getPersistenceStrategy(),
            new FQCNMessageFactory(),
            $this->database,
            'test_projection',
            'event_streams',
            'projections',
            10,
            10,
            10,
            10000
        );
    }

    /**
     * @test
     */
    public function it_dispatches_pcntl_signals_when_enabled(): void
    {
        if (! \extension_loaded('pcntl')) {
            $this->markTestSkipped('The PCNTL extension is not available.');

            return;
        }

        $command = 'exec php ' . \realpath(__DIR__) . '/isolated-projection.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /**
         * Created process inherits env variables from this process.
         * Script returns with non-standard code SIGUSR1 from the handler and -1 else
         */
        $projectionProcess = \proc_open($command, $descriptorSpec, $pipes);
        $processDetails = \proc_get_status($projectionProcess);
        \sleep(1);
        \posix_kill($processDetails['pid'], SIGQUIT);
        \sleep(1);

        $processDetails = \proc_get_status($projectionProcess);
        $this->assertEquals(
            SIGUSR1,
            $processDetails['exitcode']
        );
    }

    /**
     * @test
     */
    public function it_respects_update_lock_threshold(): void
    {
        if (! \extension_loaded('pcntl')) {
            $this->markTestSkipped('The PCNTL extension is not available.');

            return;
        }

        $this->prepareEventStream('user-123');

        $command = 'exec php ' . \realpath(__DIR__) . '/isolated-projection.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /**
         * Created process inherits env variables from this process.
         * Script returns with non-standard code SIGUSR1 from the handler and -1 else
         */
        $projectionProcess = \proc_open($command, $descriptorSpec, $pipes);

        \sleep(1);

        $lockedUntil = TestUtil::getProjectionLockedUntilFromDefaultProjectionsTable($this->client, 'test_projection');

        $this->assertNotNull($lockedUntil);

        //Update lock threshold is set to 2000 ms
        \usleep(500000);

        $notUpdatedLockedUntil = TestUtil::getProjectionLockedUntilFromDefaultProjectionsTable($this->client, 'test_projection');

        $this->assertEquals($lockedUntil, $notUpdatedLockedUntil);

        //Now we should definitely see an updated lock
        \sleep(2);

        $processDetails = \proc_get_status($projectionProcess);

        $updatedLockedUntil = TestUtil::getProjectionLockedUntilFromDefaultProjectionsTable($this->client, 'test_projection');

        $this->assertGreaterThan($lockedUntil, $updatedLockedUntil);

        \posix_kill($processDetails['pid'], SIGQUIT);

        \sleep(1);

        $processDetails = \proc_get_status($projectionProcess);
        $this->assertFalse(
            $processDetails['running']
        );
    }

    /**
     * @test
     */
    public function it_should_update_lock_if_projection_is_not_locked(): void
    {
        $projectorRef = new \ReflectionClass(MongoDbEventStoreProjector::class);

        $shouldUpdateLock = new \ReflectionMethod(MongoDbEventStoreProjector::class, 'shouldUpdateLock');

        $shouldUpdateLock->setAccessible(true);

        $projector = $projectorRef->newInstanceWithoutConstructor();

        $this->assertTrue($shouldUpdateLock->invoke($projector, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    /**
     * @test
     */
    public function it_should_update_lock_if_update_lock_threshold_is_set_to_0(): void
    {
        $projectorRef = new \ReflectionClass(MongoDbEventStoreProjector::class);

        $shouldUpdateLock = new \ReflectionMethod(MongoDbEventStoreProjector::class, 'shouldUpdateLock');

        $shouldUpdateLock->setAccessible(true);

        $projector = $projectorRef->newInstanceWithoutConstructor();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $lastLockUpdateProp = $projectorRef->getProperty('lastLockUpdate');
        $lastLockUpdateProp->setAccessible(true);
        $lastLockUpdateProp->setValue($projector, $now);

        $updateLockThresholdProp = $projectorRef->getProperty('updateLockThreshold');
        $updateLockThresholdProp->setAccessible(true);
        $updateLockThresholdProp->setValue($projector, 0);

        $this->assertTrue($shouldUpdateLock->invoke($projector, $now));
    }

    /**
     * @test
     */
    public function it_should_update_lock_if_now_is_greater_than_last_lock_update_plus_threshold(): void
    {
        $projectorRef = new \ReflectionClass(MongoDbEventStoreProjector::class);

        $shouldUpdateLock = new \ReflectionMethod(MongoDbEventStoreProjector::class, 'shouldUpdateLock');

        $shouldUpdateLock->setAccessible(true);

        $projector = $projectorRef->newInstanceWithoutConstructor();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $lastLockUpdateProp = $projectorRef->getProperty('lastLockUpdate');
        $lastLockUpdateProp->setAccessible(true);
        $lastLockUpdateProp->setValue($projector, TestUtil::subMilliseconds($now, 800));

        $updateLockThresholdProp = $projectorRef->getProperty('updateLockThreshold');
        $updateLockThresholdProp->setAccessible(true);
        $updateLockThresholdProp->setValue($projector, 500);

        $this->assertTrue($shouldUpdateLock->invoke($projector, $now));
    }

    /**
     * @test
     */
    public function it_should_not_update_lock_if_now_is_lower_than_last_lock_update_plus_threshold(): void
    {
        $projectorRef = new \ReflectionClass(MongoDbEventStoreProjector::class);

        $shouldUpdateLock = new \ReflectionMethod(MongoDbEventStoreProjector::class, 'shouldUpdateLock');

        $shouldUpdateLock->setAccessible(true);

        $projector = $projectorRef->newInstanceWithoutConstructor();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $lastLockUpdateProp = $projectorRef->getProperty('lastLockUpdate');
        $lastLockUpdateProp->setAccessible(true);
        $lastLockUpdateProp->setValue($projector, TestUtil::subMilliseconds($now, 300));

        $updateLockThresholdProp = $projectorRef->getProperty('updateLockThreshold');
        $updateLockThresholdProp->setAccessible(true);
        $updateLockThresholdProp->setValue($projector, 500);

        $this->assertFalse($shouldUpdateLock->invoke($projector, $now));
    }
}
