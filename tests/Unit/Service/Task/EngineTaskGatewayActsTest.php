<?php

/**
 * The engine gateway's reassign, claim and completion refusals, and the
 * reason it gives when the container cannot build the task service.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskGateway
 * @uses \OCA\Dossiq\Service\Task\EngineTaskPayload
 * @uses \OCA\Dossiq\Service\Task\EngineTaskRow
 */
class EngineTaskGatewayActsTest extends TestCase {

	/**
	 * Settings that report OpenRegister installed.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(): SettingsService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('getConfigValue')->willReturn('1');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return $settings;
	}//end settings()

	/**
	 * A gateway whose task service class exists and resolves to the given double.
	 *
	 * @param object|null $service The engine service, or null when it cannot be built.
	 *
	 * @return EngineTaskGateway The gateway.
	 */
	private function gatewayWith(?object $service): EngineTaskGateway {
		return new class ($this->settings(), $this->createMock(ContainerInterface::class), new NullLogger(), $service) extends EngineTaskGateway {
			/**
			 * @param SettingsService    $settings  The settings double.
			 * @param ContainerInterface $container The container double.
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
			 * @return object|null The injected double.
			 */
			protected function resolveService(): ?object {
				return $this->service;
			}

			/**
			 * @return boolean The class is present.
			 */
			protected function taskServiceExists(): bool {
				return true;
			}
		};
	}//end gatewayWith()

	/**
	 * A service that records reassignments and refuses one task.
	 *
	 * @return object The service double.
	 */
	private function reassigningService(): object {
		return new class {

			/**
			 * The reassignments made.
			 *
			 * @var array<int, array<int, string|null>>
			 */
			public array $calls = [];

			/**
			 * @param string      $uuid     The task.
			 * @param string      $assignee The new assignee.
			 * @param string|null $actor    Who reassigns.
			 *
			 * @return void
			 */
			public function reassign(string $uuid, string $assignee, ?string $actor): void {
				if ($uuid === 'locked') {
					throw new RuntimeException('task is closed');
				}

				$this->calls[] = [$uuid, $assignee, $actor];
			}
		};
	}//end reassigningService()

	/**
	 * Present class, but a container that cannot build it, is named as such.
	 *
	 * @return void
	 */
	public function testUnavailableReasonNamesTheContainer(): void {
		$this->assertSame(
			expected: 'the container could not construct the task service',
			actual: $this->gatewayWith(null)->unavailableReason()
		);
		$this->assertSame(expected: '', actual: $this->gatewayWith(new \stdClass())->unavailableReason());
	}//end testUnavailableReasonNamesTheContainer()

	/**
	 * A reassignment is trimmed and handed to the engine.
	 *
	 * @return void
	 */
	public function testReassignHandsTheEngineTheTrimmedValues(): void {
		$service = $this->reassigningService();

		$this->assertTrue(condition: $this->gatewayWith($service)->reassign(taskId: ' task-1 ', assignee: ' anna ', actor: 'beheerder'));
		$this->assertSame(expected: [['task-1', 'anna', 'beheerder']], actual: $service->calls);
	}//end testReassignHandsTheEngineTheTrimmedValues()

	/**
	 * Blank input or an absent engine reassigns nothing.
	 *
	 * @return void
	 */
	public function testReassignRefusesBlankInputAndAnAbsentEngine(): void {
		$service = $this->reassigningService();
		$gateway = $this->gatewayWith($service);

		$this->assertFalse(condition: $gateway->reassign(taskId: ' ', assignee: 'anna', actor: null));
		$this->assertFalse(condition: $gateway->reassign(taskId: 'task-1', assignee: ' ', actor: null));
		$this->assertFalse(condition: $this->gatewayWith(null)->reassign(taskId: 'task-1', assignee: 'anna', actor: null));
		$this->assertSame(expected: [], actual: $service->calls);
	}//end testReassignRefusesBlankInputAndAnAbsentEngine()

	/**
	 * An engine refusal is reported per task and its message kept.
	 *
	 * @return void
	 */
	public function testReassignKeepsTheEngineRefusal(): void {
		$gateway = $this->gatewayWith($this->reassigningService());

		$this->assertFalse(condition: $gateway->reassign(taskId: 'locked', assignee: 'anna', actor: null));
		$this->assertSame(expected: 'task is closed', actual: $gateway->lastError());
	}//end testReassignKeepsTheEngineRefusal()

	/**
	 * A claim is supported only when the engine has a claim act.
	 *
	 * @return void
	 */
	public function testClaimSupportFollowsTheEngine(): void {
		$this->assertFalse(condition: $this->gatewayWith(null)->supportsClaim());
		$this->assertFalse(condition: $this->gatewayWith(new \stdClass())->supportsClaim());

		$gateway = $this->gatewayWith(new \stdClass());
		$this->assertFalse(condition: $gateway->claim(taskId: 'task-1', actor: 'anna'));
		$this->assertSame(expected: 'The task engine answers no claim act.', actual: $gateway->lastError());
	}//end testClaimSupportFollowsTheEngine()

	/**
	 * A completion the engine refuses, or cannot build, is a named failure.
	 *
	 * @return void
	 */
	public function testCompletionRefusalsAreKept(): void {
		$completion = $this->getMockBuilder(TaskFormCompletion::class)->onlyMethods(['complete'])->getMock();
		$completion->method('complete')->willThrowException(new RuntimeException('required_field:verslag'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($completion);
		$gateway = new EngineTaskGateway($this->settings(), $container, new NullLogger());

		$this->assertFalse(condition: $gateway->complete(taskId: 'task-1', data: [], outcome: 'done', actor: 'anna'));
		$this->assertSame(expected: 'required_field:verslag', actual: $gateway->lastError());

		$broken = $this->createMock(ContainerInterface::class);
		$broken->method('get')->willThrowException(new RuntimeException('cannot build'));
		$gateway = new EngineTaskGateway($this->settings(), $broken, new NullLogger());

		$this->assertFalse(condition: $gateway->complete(taskId: 'task-1', data: [], outcome: 'done', actor: 'anna'));
		$this->assertSame(expected: 'cannot build', actual: $gateway->lastError());

		$this->assertFalse(condition: $gateway->complete(taskId: ' ', data: [], outcome: 'done', actor: 'anna'));
		$this->assertSame(expected: 'The task engine cannot complete a task on this instance.', actual: $gateway->lastError());
	}//end testCompletionRefusalsAreKept()
}//end class
