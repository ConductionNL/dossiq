<?php

/**
 * EngineTaskGateway: reassignment, refused completions, the claim probe,
 * the unavailability reasons and the column reads of a found task.
 *
 * EngineTaskGatewayTest pins the import payload and the happy completion.
 * This file pins the answers a caller has to tell apart: a write that was
 * refused keeps the engine's reason in `lastError()`, an engine that cannot
 * be built is named as such, and a found task's optional columns are read
 * through their real getters with dates formatted and non-lists dropped.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskGateway
 */
class EngineTaskGatewayLifecycleTest extends TestCase {

	/**
	 * A settings double reporting OpenRegister as installed or not.
	 *
	 * @param boolean $available Whether OpenRegister is available.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(bool $available = true): SettingsService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('isOpenRegisterAvailable')->willReturn($available);

		return $settings;
	}//end settings()

	/**
	 * A gateway whose engine service is the given double (null: unreachable).
	 *
	 * @param object|null $service The engine double.
	 *
	 * @return EngineTaskGateway The gateway.
	 */
	private function gatewayWith(?object $service): EngineTaskGateway {
		return new class ($this->settings(), $this->createMock(ContainerInterface::class), new NullLogger(), $service) extends EngineTaskGateway {

			/**
			 * @param SettingsService    $settings  The settings.
			 * @param ContainerInterface $container The container.
			 * @param NullLogger         $logger    The logger.
			 * @param object|null        $service   The engine double.
			 */
			public function __construct(
				SettingsService $settings,
				ContainerInterface $container,
				NullLogger $logger,
				private readonly ?object $service,
			) {
				parent::__construct($settings, $container, $logger);
			}

			/**
			 * @return object|null The engine double.
			 */
			protected function resolveService(): ?object {
				return $this->service;
			}
		};
	}//end gatewayWith()

	/**
	 * A gateway that believes the task service class exists, over a real container lookup.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return EngineTaskGateway The gateway.
	 */
	private function gatewayOverContainer(ContainerInterface $container, LoggerInterface $logger): EngineTaskGateway {
		return new class ($this->settings(), $container, $logger) extends EngineTaskGateway {

			/**
			 * @return boolean Always true.
			 */
			protected function taskServiceExists(): bool {
				return true;
			}
		};
	}//end gatewayOverContainer()

	/**
	 * An engine that records reassignments, or refuses them.
	 *
	 * @param string|null $refusal The refusal message, or null to accept.
	 *
	 * @return object The double.
	 */
	private function reassigningEngine(?string $refusal = null): object {
		return new class ($refusal) {

			/**
			 * @var array<int, array<int, string|null>>
			 */
			public array $calls = [];

			/**
			 * @param string|null $refusal The refusal message.
			 */
			public function __construct(private readonly ?string $refusal) {
			}

			/**
			 * @param string      $uuid     The task.
			 * @param string      $assignee The new assignee.
			 * @param string|null $actor    The actor.
			 *
			 * @return void
			 */
			public function reassign(string $uuid, string $assignee, ?string $actor): void {
				$this->calls[] = [$uuid, $assignee, $actor];
				if ($this->refusal !== null) {
					throw new RuntimeException($this->refusal);
				}
			}
		};
	}//end reassigningEngine()

	/**
	 * A reassignment reaches the engine trimmed, with the actor.
	 *
	 * @return void
	 */
	public function testAReassignmentIsHandedToTheEngine(): void {
		$engine = $this->reassigningEngine();

		self::assertTrue($this->gatewayWith($engine)->reassign(' task-1 ', ' hbakker ', 'teamleider'));
		self::assertSame([['task-1', 'hbakker', 'teamleider']], $engine->calls);
	}//end testAReassignmentIsHandedToTheEngine()

	/**
	 * A blank task or assignee, or an unreachable engine, is refused without a call.
	 *
	 * @return void
	 */
	public function testAReassignmentWithNothingToDoIsRefused(): void {
		$engine  = $this->reassigningEngine();
		$gateway = $this->gatewayWith($engine);

		self::assertFalse($gateway->reassign(' ', 'hbakker', null));
		self::assertFalse($gateway->reassign('task-1', '  ', null));
		self::assertFalse($this->gatewayWith(null)->reassign('task-1', 'hbakker', null));
		self::assertSame([], $engine->calls);
	}//end testAReassignmentWithNothingToDoIsRefused()

	/**
	 * The engine's refusal of a reassignment is kept, not swallowed.
	 *
	 * @return void
	 */
	public function testARefusedReassignmentKeepsTheEnginesReason(): void {
		$gateway = $this->gatewayWith($this->reassigningEngine('not a candidate'));

		self::assertFalse($gateway->reassign('task-1', 'hbakker', 'teamleider'));
		self::assertSame('not a candidate', $gateway->lastError());
	}//end testARefusedReassignmentKeepsTheEnginesReason()

	/**
	 * Completing without OpenRegister, or without a task id, is a named refusal.
	 *
	 * @return void
	 */
	public function testACompletionThatCannotBeMadeIsNamed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$absent = new EngineTaskGateway($this->settings(false), $container, new NullLogger());
		self::assertFalse($absent->complete('task-1', [], 'approved', 'behandelaar'));
		self::assertSame('The task engine cannot complete a task on this instance.', $absent->lastError());

		$blank = new EngineTaskGateway($this->settings(true), $container, new NullLogger());
		self::assertFalse($blank->complete('  ', [], 'approved', 'behandelaar'));
		self::assertSame('The task engine cannot complete a task on this instance.', $blank->lastError());
	}//end testACompletionThatCannotBeMadeIsNamed()

	/**
	 * The engine's refusal of a completion is returned with its reason.
	 *
	 * @return void
	 */
	public function testARefusedCompletionKeepsTheEnginesReason(): void {
		$completion = $this->getMockBuilder(TaskFormCompletion::class)->onlyMethods(['complete'])->getMock();
		$completion->method('complete')->willThrowException(new RuntimeException('field "verslag" is required'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($completion);

		$gateway = new EngineTaskGateway($this->settings(), $container, new NullLogger());

		self::assertFalse($gateway->complete('task-1', [], 'approved', 'behandelaar'));
		self::assertSame('field "verslag" is required', $gateway->lastError());
	}//end testARefusedCompletionKeepsTheEnginesReason()

	/**
	 * A container that cannot build the completion service is a refusal, not a crash.
	 *
	 * @return void
	 */
	public function testAnUnbuildableCompletionServiceIsARefusal(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('cannot autowire'));

		$gateway = new EngineTaskGateway($this->settings(), $container, new NullLogger());

		self::assertFalse($gateway->complete('task-1', [], 'approved', null));
		self::assertSame('cannot autowire', $gateway->lastError());
	}//end testAnUnbuildableCompletionServiceIsARefusal()

	/**
	 * With no engine there is no claim act, and a claim says so.
	 *
	 * @return void
	 */
	public function testNoEngineMeansNoClaim(): void {
		$gateway = $this->gatewayWith(null);

		self::assertFalse($gateway->supportsClaim());
		self::assertFalse($gateway->claim('task-1', 'hbakker'));
		self::assertSame('The task engine answers no claim act.', $gateway->lastError());
	}//end testNoEngineMeansNoClaim()

	/**
	 * A container that cannot build the task service is named, and logged at error.
	 *
	 * @return void
	 */
	public function testAnUnbuildableTaskServiceIsNamed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('missing dependency'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())
			->method('error')
			->with('Dossiq: could not resolve the OpenRegister task service', ['exception' => 'missing dependency']);

		$gateway = $this->gatewayOverContainer($container, $logger);

		self::assertFalse($gateway->isEnabled());
		self::assertSame('the container could not construct the task service', $gateway->unavailableReason());
	}//end testAnUnbuildableTaskServiceIsNamed()

	/**
	 * A reachable engine has no unavailability reason.
	 *
	 * @return void
	 */
	public function testAReachableEngineHasNoReason(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		$gateway = $this->gatewayOverContainer($container, new NullLogger());

		self::assertTrue($gateway->isEnabled());
		self::assertSame('', $gateway->unavailableReason());
	}//end testAReachableEngineHasNoReason()

	/**
	 * A found task's declared getters are read: dates formatted, strings kept, non-lists dropped.
	 *
	 * @return void
	 */
	public function testAFoundTasksColumnsAreReadThroughTheirGetters(): void {
		$task = new class {

			/** @return string */
			public function getUuid(): string {
				return 'task-1';
			}

			/** @return string */
			public function getTitle(): string {
				return 'Beoordeel';
			}

			/** @return string */
			public function getState(): string {
				return 'active';
			}

			/** @return string|null */
			public function getRunUuid(): ?string {
				return null;
			}

			/** @return string|null */
			public function getNodeId(): ?string {
				return 'node-3';
			}

			/** @return string|null */
			public function getAssignee(): ?string {
				return null;
			}

			/** @return array<int, mixed>|null */
			public function getChecklist(): ?array {
				return null;
			}

			/** @return string */
			public function getObjectUuid(): string {
				return 'case-1';
			}

			/** @return DateTimeImmutable */
			public function getDueAt(): DateTimeImmutable {
				return new DateTimeImmutable('2026-10-01T12:00:00+00:00');
			}

			/** @return string */
			public function getCandidateGroups(): string {
				return 'Juridische Zaken';
			}

			/** @return array<int, string> */
			public function getCandidateUsers(): array {
				return ['hbakker'];
			}
		};
		$engine = new class ($task) {

			/**
			 * @param object $task The task to answer.
			 */
			public function __construct(private readonly object $task) {
			}

			/**
			 * @param string $uuid The task id.
			 *
			 * @return object The task.
			 */
			public function get(string $uuid): object {
				return $this->task;
			}
		};

		$found = $this->gatewayWith($engine)->find('task-1');

		self::assertSame(
			[
				'id' => 'task-1',
				'title' => 'Beoordeel',
				'status' => 'active',
				'flowRun' => '',
				'flowNode' => 'node-3',
				'assignee' => '',
				'checklist' => [],
				'objectUuid' => 'case-1',
				'dueDate' => '2026-10-01T12:00:00+00:00',
				'candidateGroups' => [],
				'candidateUsers' => ['hbakker'],
				'metadata' => [],
			],
			$found
		);
	}//end testAFoundTasksColumnsAreReadThroughTheirGetters()

	/**
	 * A due date the engine answers as a string is passed through as that string.
	 *
	 * @return void
	 */
	public function testAStringDueDateIsKept(): void {
		$task = new class {

			/** @return string */
			public function getUuid(): string {
				return 'task-2';
			}

			/** @return string */
			public function getTitle(): string {
				return 'Bel terug';
			}

			/** @return string */
			public function getState(): string {
				return 'active';
			}

			/** @return null */
			public function getRunUuid(): ?string {
				return null;
			}

			/** @return null */
			public function getNodeId(): ?string {
				return null;
			}

			/** @return null */
			public function getAssignee(): ?string {
				return null;
			}

			/** @return null */
			public function getChecklist(): ?array {
				return null;
			}

			/** @return string */
			public function getDueAt(): string {
				return '2026-10-02';
			}
		};
		$engine = new class ($task) {

			/**
			 * @param object $task The task to answer.
			 */
			public function __construct(private readonly object $task) {
			}

			/**
			 * @param string $uuid The task id.
			 *
			 * @return object The task.
			 */
			public function get(string $uuid): object {
				return $this->task;
			}
		};

		$found = $this->gatewayWith($engine)->find('task-2');

		self::assertSame('2026-10-02', $found['dueDate']);
		self::assertSame('', $found['objectUuid'], 'a column the engine does not have reads empty');
	}//end testAStringDueDateIsKept()
}//end class
