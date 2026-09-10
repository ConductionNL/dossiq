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
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CreateTaskHandler
 *
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class CreateTaskHandlerTest extends TestCase {
	/**
	 * A gateway that is switched off, which is the default everywhere.
	 *
	 * Every pre-existing test in this file asserts the register write and
	 * nothing else, and must keep asserting exactly that: the dual-run seam
	 * is additive, so a disabled gateway is what proves it changed nothing.
	 *
	 * @param TestCase $test The test case, for the mock builder.
	 *
	 * @return EngineTaskGateway&\PHPUnit\Framework\MockObject\MockObject
	 */
	private static function disabledGateway(TestCase $test): EngineTaskGateway {
		$gateway = $test->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('mirrorCreate')->willReturn('');

		return $gateway;
	}//end disabledGateway()

	/**
	 * @return void
	 */
	public function testFailsWhenObjectServiceUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = new CreateTaskHandler($settings, new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

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

		$handler = new CreateTaskHandler($settings, new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

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

		$handler = new CreateTaskHandler($settings, new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

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
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

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
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

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

		$handler = new CreateTaskHandler($settings, new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('create_task_failed', $result->error);
	}//end testCatchesExceptionFromObjectService()

	/**
	 * 🔴 A templated assignee is RESOLVED, not written down as the template.
	 *
	 * This is the defect. The shipped spelling everywhere else in the app is
	 * `{{ case.assignee }}`, and this handler used to copy it onto the task
	 * verbatim. No real uid ever equals that string, so a task addressed to it
	 * is addressed to nobody, and the schema's `taskAssigned` notification is
	 * sent to whatever the field holds.
	 *
	 * @return void
	 */
	public function testATemplatedAssigneeIsResolvedAgainstTheCase(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id', 'assignee' => '{{ case.assignee }}'],
			case: ['id' => 'case-9', 'assignee' => 'alice'],
			transitionContext: [],
		);

		self::assertSame('alice', $recorded['object']['assignee']);
	}//end testATemplatedAssigneeIsResolvedAgainstTheCase()

	/**
	 * Rendering really happens, and is not the case fallback wearing its coat.
	 *
	 * `{{ case.assignee }}` on a case whose assignee is alice answers alice
	 * whether the template was rendered or quietly dropped, so that assertion
	 * alone cannot tell the two apart. This one names a DIFFERENT field, so it
	 * can only pass if the template was actually rendered.
	 *
	 * @return void
	 */
	public function testTheTemplateIsRenderedRatherThanFallenBackFrom(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id', 'assignee' => '{{ case.responsible }}'],
			case: ['id' => 'case-9', 'assignee' => 'alice', 'responsible' => 'carol'],
			transitionContext: [],
		);

		self::assertSame('carol', $recorded['object']['assignee']);
	}//end testTheTemplateIsRenderedRatherThanFallenBackFrom()

	/**
	 * The declared fallback takes the task when the primary names nobody.
	 *
	 * The same rule the flow node follows, from the same resolver.
	 *
	 * @return void
	 */
	public function testTheDeclaredFallbackTakesTheTask(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: [
				'type' => 'createTask',
				'title' => 'Check id',
				'assignee' => '{{ case.assignee }}',
				'assigneeFallback' => 'behandelaars',
			],
			case: ['id' => 'case-9'],
			transitionContext: [],
		);

		self::assertSame('behandelaars', $recorded['object']['assignee']);
	}//end testTheDeclaredFallbackTakesTheTask()

	/**
	 * An action naming nobody still reaches the case's own handler.
	 *
	 * Not a guess: a task created by a status transition belongs to the case,
	 * and the case says who is handling it. Before this, an action with no
	 * assignee produced an unassigned task on a case with a named handler
	 * sitting one field away.
	 *
	 * @return void
	 */
	public function testAnActionNamingNobodyFallsBackToTheCasesHandler(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id'],
			case: ['id' => 'case-9', 'assignee' => 'bob'],
			transitionContext: [],
		);

		self::assertSame('bob', $recorded['object']['assignee']);
	}//end testAnActionNamingNobodyFallsBackToTheCasesHandler()

	/**
	 * The case's team comes along, and is not confused with its handler.
	 *
	 * A case can carry a team, a personal assignee, or both, and the task
	 * schema has a field for each. Writing the team into `assignee` would put
	 * an organisatieRol uuid where a Nextcloud principal belongs.
	 *
	 * @return void
	 */
	public function testTheCasesTeamIsCarriedOntoTheTask(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id'],
			case: ['id' => 'case-9', 'assignedGroup' => 'rol-7'],
			transitionContext: [],
		);

		self::assertSame('rol-7', $recorded['object']['assigneeGroup']);
		self::assertSame('', $recorded['object']['assignee']);
	}//end testTheCasesTeamIsCarriedOntoTheTask()

	/**
	 * 🔴 An EXPANDED team reference writes the uuid, not the word "Array".
	 *
	 * `case.assignedGroup` is a `$ref`, so OpenRegister answers it as a uuid
	 * string on a plain read and as the expanded object when the caller asked
	 * for it. A `(string)` cast on the expanded form yields the literal
	 * "Array" and a PHP warning, and this suite runs with `failOnWarning`
	 * false: the warning is invisible and what survives is a task whose team
	 * is four characters that resolve to nothing, in a column the Tasks list
	 * has to render.
	 *
	 * @return void
	 */
	public function testAnExpandedTeamReferenceWritesTheUuid(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id'],
			case: ['id' => 'case-9', 'assignedGroup' => ['id' => 'rol-7', 'name' => 'Vergunningen']],
			transitionContext: [],
		);

		self::assertSame('rol-7', $recorded['object']['assigneeGroup']);
		self::assertNotSame('Array', $recorded['object']['assigneeGroup']);
	}//end testAnExpandedTeamReferenceWritesTheUuid()

	/**
	 * An expanded personal assignee resolves to its id too.
	 *
	 * @return void
	 */
	public function testAnExpandedCaseAssigneeResolvesToItsId(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id'],
			case: ['id' => 'case-9', 'assignee' => ['id' => 'alice']],
			transitionContext: [],
		);

		self::assertSame('alice', $recorded['object']['assignee']);
	}//end testAnExpandedCaseAssigneeResolvesToItsId()

	/**
	 * A case with no team writes no team, rather than an empty one.
	 *
	 * An empty `assigneeGroup` is a value the task lists would have to
	 * special-case, exactly as the workflowStepId comment beside it says.
	 *
	 * @return void
	 */
	public function testACaseWithNoTeamWritesNoTeamField(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id'],
			case: ['id' => 'case-9'],
			transitionContext: [],
		);

		self::assertArrayNotHasKey('assigneeGroup', $recorded['object']);
	}//end testACaseWithNoTeamWritesNoTeamField()

	/**
	 * A literal assignee is still written through unchanged.
	 *
	 * The rule is resolution, not rewriting: `behandelaars` is a group id the
	 * resume guard compares against, and it must survive.
	 *
	 * @return void
	 */
	public function testALiteralAssigneeSurvivesResolution(): void {
		$recorded = null;
		$handler = new CreateTaskHandler($this->recordingSettings($recorded), new AssigneeResolver(new NullLogger()), self::disabledGateway($this), new NullLogger());

		$handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Check id', 'assignee' => 'behandelaars'],
			case: ['id' => 'case-9', 'assignee' => 'alice'],
			transitionContext: [],
		);

		self::assertSame('behandelaars', $recorded['object']['assignee']);
	}//end testALiteralAssigneeSurvivesResolution()
}//end class
