<?php

/**
 * Unit tests for EngineRunMigration: when a rebind asks the engine to move
 * the case's flow run, and what it reports back.
 *
 * Without OpenRegister, or without a container that can build the migration
 * service, or with a service whose verb moved, nothing is asked and the case
 * moves alone. When the engine is asked, its own refusal sentence travels as
 * the refusal, and an answer that does not say the run moved is a refusal
 * too.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\EngineRunMigration;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for EngineRunMigration.
 *
 * @covers \OCA\Dossiq\Service\CaseType\EngineRunMigration
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class EngineRunMigrationTest extends TestCase {

	/**
	 * Build the migration over an OpenRegister that is, or is not, there.
	 *
	 * @param bool                    $available Whether OpenRegister is enabled.
	 * @param ContainerInterface|null $container The container.
	 * @param LoggerInterface|null    $logger    The logger.
	 *
	 * @return EngineRunMigration
	 */
	private function migration(bool $available, ?ContainerInterface $container=null, ?LoggerInterface $logger=null): EngineRunMigration {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);

		return new EngineRunMigration(
			$settings,
			($container ?? $this->createMock(ContainerInterface::class)),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end migration()

	/**
	 * A container that hands back the given service for the migration class.
	 *
	 * @param object $service The service.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(object $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->atLeastOnce())
			->method('get')
			->with(EngineRunMigration::MIGRATION_SERVICE)
			->willReturn($service);

		return $container;
	}//end containerWith()

	/**
	 * A migration service that records its calls and answers as told.
	 *
	 * @param mixed $answer What migrateRunForSubject returns, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function engine(mixed $answer): object {
		return new class($answer) {
			/**
			 * Calls made, in order.
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}//end __construct()

			/**
			 * The verb EngineRunMigration calls.
			 *
			 * @param string $caseId   The case.
			 * @param string $targetId The target case type.
			 * @param string $actorUid The actor.
			 *
			 * @return mixed
			 */
			public function migrateRunForSubject(string $caseId, string $targetId, string $actorUid): mixed {
				$this->calls[] = [$caseId, $targetId, $actorUid];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end migrateRunForSubject()
		};
	}//end engine()

	/**
	 * Make OpenRegister's migration service class exist in this process.
	 *
	 * @return void
	 */
	private static function shipTheMigrationService(): void {
		require_once __DIR__ . '/../../Fixtures/FlowRunMigrationServicePresence.php';
	}//end shipTheMigrationService()

	/**
	 * Without OpenRegister nothing is asked and the case moves alone.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAsked(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$migration = $this->migration(available: false, container: $container);

		$this->assertSame(
			['asked' => false, 'migrated' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			$migration->migrate('case-1', 'type-2', 'pieter')
		);
		$this->assertSame('openregister is not installed or not enabled', $migration->reasonFor());
	}//end testWithoutOpenRegisterNothingIsAsked()

	/**
	 * A container that cannot build the service is logged and asks nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAServiceTheContainerCannotBuildAsksNothing(): void {
		self::shipTheMigrationService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no autowire'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))
			->method('error')
			->with(
				$this->stringContains('the container could not construct it'),
				['exception' => 'no autowire']
			);
		$migration = $this->migration(available: true, container: $container, logger: $logger);

		$this->assertSame(
			['asked' => false, 'migrated' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			$migration->migrate('case-1', 'type-2', 'pieter')
		);
		$this->assertSame('the container could not construct the run migration service', $migration->reasonFor());
	}//end testAServiceTheContainerCannotBuildAsksNothing()

	/**
	 * A service without the verb is a moved capability: logged, nothing asked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAServiceWithoutTheVerbAsksNothing(): void {
		self::shipTheMigrationService();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('has no migrateRunForSubject()'));
		$migration = $this->migration(available: true, container: $this->containerWith(new \stdClass()), logger: $logger);

		$this->assertFalse($migration->migrate('case-1', 'type-2', 'pieter')['asked']);
	}//end testAServiceWithoutTheVerbAsksNothing()

	/**
	 * A true answer, or an array saying it migrated, moves the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAMigratedAnswerMovesTheRun(): void {
		self::shipTheMigrationService();
		$engine    = $this->engine(true);
		$migration = $this->migration(available: true, container: $this->containerWith($engine));

		$this->assertSame(
			['asked' => true, 'migrated' => true, 'reason' => 'The flow run moved to the target case type\'s definition.'],
			$migration->migrate('case-1', 'type-2', 'pieter')
		);
		$this->assertSame([['case-1', 'type-2', 'pieter']], $engine->calls);
		$this->assertSame('', $migration->reasonFor());

		$named = $this->migration(
			available: true,
			container: $this->containerWith($this->engine(['migrated' => true, 'reason' => '  Run moved to v2.  ']))
		);
		$this->assertSame('Run moved to v2.', $named->migrate('case-1', 'type-2', 'pieter')['reason']);
	}//end testAMigratedAnswerMovesTheRun()

	/**
	 * The engine's own refusal sentence travels, with the engine error kept.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testTheEnginesExceptionTravelsAsTheRefusal(): void {
		self::shipTheMigrationService();
		$cause     = new RuntimeException('This run is suspended awaiting an advice.');
		$migration = $this->migration(available: true, container: $this->containerWith($this->engine($cause)));

		try {
			$migration->migrate('case-1', 'type-2', 'pieter');
			$this->fail('A refused migration must throw.');
		} catch (RefusedException $e) {
			$this->assertSame('engine-refused-the-run-migration', $e->getRule());
			$this->assertSame('This run is suspended awaiting an advice.', $e->getSentence());
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testTheEnginesExceptionTravelsAsTheRefusal()

	/**
	 * An answer that does not say the run moved is a refusal, with its reason or a default one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAnAnswerThatDoesNotSayMigratedIsRefused(): void {
		self::shipTheMigrationService();
		$answers = [
			[['migrated' => false, 'reason' => 'The target has no matching step.'], 'The target has no matching step.'],
			[['migrated' => 'yes'], 'The engine refused to move this case\'s flow run, and gave no reason.'],
			[false, 'The engine refused to move this case\'s flow run, and gave no reason.'],
			[new \stdClass(), 'The engine refused to move this case\'s flow run, and gave no reason.'],
		];

		foreach ($answers as [$answer, $sentence]) {
			$migration = $this->migration(available: true, container: $this->containerWith($this->engine($answer)));
			try {
				$migration->migrate('case-1', 'type-2', 'pieter');
				$this->fail('An answer that does not say migrated must be refused.');
			} catch (RefusedException $e) {
				$this->assertSame($sentence, $e->getSentence());
				$this->assertSame('engine-refused-the-run-migration', $e->getRule());
				$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			}
		}
	}//end testAnAnswerThatDoesNotSayMigratedIsRefused()
	/**
	 * OpenRegister without the migration service asks nothing, and says why.
	 *
	 * That reason is distinct from "not installed": an instance that HAS
	 * openregister and still lacks the class is waiting on a release, and the
	 * container is never asked for a class that does not exist.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testOpenRegisterWithoutTheServiceAsksNothing(): void {
		$this->assertFalse(class_exists(EngineRunMigration::MIGRATION_SERVICE));
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$migration = $this->migration(available: true, container: $container);

		$this->assertSame(
			['asked' => false, 'migrated' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED],
			$migration->migrate('case-1', 'type-2', 'pieter')
		);
		$this->assertSame('openregister has not shipped migrate-run-between-versions yet', $migration->reasonFor());
	}//end testOpenRegisterWithoutTheServiceAsksNothing()
}//end class
