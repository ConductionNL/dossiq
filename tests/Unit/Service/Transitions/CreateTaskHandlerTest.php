<?php

/**
 * CreateTaskHandler Unit Tests
 *
 * Verifies the createTask action handler envelope under storage-unavailable,
 * missing-config, success, and exception-swallow paths.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CreateTaskHandler
 *
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class CreateTaskHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenObjectServiceUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Doe X'],
			case: ['id' => 'case-1'],
			transitionContext: ['transitionLabel' => 'Approve'],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenObjectServiceUnavailable()

	/**
	 * @return void
	 */
	public function testFailsWhenTaskSchemaNotConfigured(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return ['id' => 'unreachable'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return $key === 'register' ? 'reg-1' : '';
			}
		);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('task_schema_not_configured', $result->error);
	}//end testFailsWhenTaskSchemaNotConfigured()

	/**
	 * @return void
	 */
	public function testCreatesTaskWithCaseLinkAndAssigneeOnSuccess(): void {
		$recorded = null;

		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return ['id' => 'task-uuid'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'task_schema' => 'task-schema',
				][$key] ?? '';
			}
		);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Review docs', 'assignee' => 'alice'],
			case: ['id' => 'case-9'],
			transitionContext: ['transitionLabel' => 'In Review'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('task-uuid', $result->data['taskId']);
		self::assertSame('Review docs', $recorded['object']['title']);
		self::assertSame('case-9', $recorded['object']['case']);
		self::assertSame('alice', $recorded['object']['assignee']);
		// The task schema declares enum available|active|completed|terminated|disabled
		// with initial state 'available'. A status outside that enum yields a task no
		// lifecycle transition can advance, so assert the declared initial state.
		self::assertSame('available', $recorded['object']['status']);
		self::assertContains(
			$recorded['object']['status'],
			['available', 'active', 'completed', 'terminated', 'disabled'],
			'CreateTaskHandler must write a status the task schema allows'
		);
		self::assertSame('reg-1', $recorded['register']);
		self::assertSame('task-schema', $recorded['schema']);
	}//end testCreatesTaskWithCaseLinkAndAssigneeOnSuccess()

	/**
	 * A checklist action's status is written onto the task.
	 *
	 * `workflowStepId` is what says which status made this task, and it is
	 * what the checklist reader filters on to know it has been here before. A
	 * handler that dropped it would create the whole list again on every
	 * re-entry, and the required-item guard would never find its task.
	 *
	 * @return void
	 */
	public function testWritesTheWorkflowStepIdTheActionNames(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new NullLogger());

		$result = $handler->handle(
			actionConfig: [
				'type' => 'createTask',
				'title' => 'Check the objection is on time',
				'workflowStepId' => 'status-intake',
			],
			case: ['id' => 'case-9'],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('status-intake', $recorded['object']['workflowStepId']);
	}//end testWritesTheWorkflowStepIdTheActionNames()

	/**
	 * An action naming no status leaves the field off the task entirely.
	 *
	 * @return void
	 */
	public function testLeavesTheWorkflowStepIdOffWhenTheActionNamesNone(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Review docs', 'workflowStepId' => '  '],
			case: ['id' => 'case-9'],
			transitionContext: [],
		);

		self::assertArrayNotHasKey('workflowStepId', $recorded['object']);
	}//end testLeavesTheWorkflowStepIdOffWhenTheActionNamesNone()

	/**
	 * A SettingsService whose object service records what it is asked to save.
	 *
	 * @param mixed $recorded Filled with `['object' =>, 'register' =>, 'schema' =>]`.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject The settings double.
	 */
	private function recordingSettings(&$recorded): SettingsService {
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			/**
			 * @param mixed $recorded The recording slot.
			 */
			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			/**
			 * @param array<string, mixed> $object   The task to save.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved task.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return ['id' => 'task-uuid'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'reg-1',
				'task_schema' => 'task-schema',
			][$key] ?? '')
		);

		return $settings;
	}//end recordingSettings()

	/**
	 * @return void
	 */
	public function testCatchesExceptionFromObjectService(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('storage went away');
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'task_schema' => 'task-schema',
				][$key] ?? '';
			}
		);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('create_task_failed', $result->error);
	}//end testCatchesExceptionFromObjectService()
}//end class
