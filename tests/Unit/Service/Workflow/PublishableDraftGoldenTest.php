<?php

/**
 * Golden test for WorkflowLifecycleGuard::isPublishableDraft: every refusal in
 * order, its log line, and what the guard remembers about it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Task\TaskDeclarationValidator;
use OCA\Dossiq\Service\Workflow\StepConfigCheck;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class PublishableDraftGoldenTest extends TestCase {

	private array $logged = [];

	private array $asked = [];

	private const DRAFT = ['lifecycleStatus' => 'draft', 'caseType' => 'ct-1'];

	private function guard(array $refusals = [], array $stepErrors = [], array $statusIds = ['s1', 's2'], bool $withTasks = true): WorkflowLifecycleGuard {
		$repository = $this->createMock(WorkflowDefinitionRepository::class);
		$asked      = &$this->asked;
		$repository->method('listStatusTypeIds')->willReturnCallback(
			static function (string $caseTypeId) use (&$asked, $statusIds): array {
				$asked[] = ['statuses', $caseTypeId];
				return $statusIds;
			}
		);
		$tasks = $this->createMock(TaskDeclarationValidator::class);
		$tasks->method('refusalsFor')->willReturnCallback(
			static function (array $definition) use (&$asked, $refusals): array {
				$asked[] = ['tasks'];
				return $refusals;
			}
		);
		$steps = $this->createMock(StepConfigCheck::class);
		$steps->method('errorsFor')->willReturnCallback(
			static function (array $definition) use (&$asked, $stepErrors): array {
				$asked[] = ['steps'];
				return $stepErrors;
			}
		);
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		if ($withTasks === false) {
			return new WorkflowLifecycleGuard(repository: $repository, logger: $logger, stepConfigs: $steps);
		}

		return new WorkflowLifecycleGuard(repository: $repository, logger: $logger, taskDeclarations: $tasks, stepConfigs: $steps);
	}//end guard()

	public function testAPublishableDraft(): void {
		$guard = $this->guard();
		$this->assertTrue($guard->isPublishableDraft(self::DRAFT, [['fromStatus' => 's1', 'toStatus' => 's2'], 'x', ['toStatus' => '']], 'wd-1'));
		$this->assertSame([['tasks'], ['steps'], ['statuses', 'ct-1']], $this->asked);
		$this->assertSame([], $this->logged);
		$this->assertSame([], $guard->lastStepConfigErrors());
		$this->assertSame([], $guard->lastRefusals());

		$this->asked = [];
		$this->assertTrue($this->guard(withTasks: false)->isPublishableDraft(self::DRAFT, [], 'wd-1'));
		$this->assertSame([['steps']], $this->asked);
		$this->assertTrue($this->guard(statusIds: [])->isPublishableDraft(self::DRAFT, [['fromStatus' => 'zz']], 'wd-1'));
	}//end testAPublishableDraft()

	public function testEveryRefusalInOrder(): void {
		$guard = $this->guard(refusals: [['field' => 'form']]);
		$this->assertFalse($guard->isPublishableDraft(['lifecycleStatus' => 'published'], [], 'wd-1'));
		$this->assertSame([['tasks']], $this->asked);
		$this->assertSame([['field' => 'form']], $guard->lastRefusals());

		$this->asked = [];
		$this->assertFalse($this->guard(stepErrors: ['e'])->isPublishableDraft(['lifecycleStatus' => 'published'], [], 'wd-2'));
		$this->assertSame([['tasks']], $this->asked);

		$this->asked = [];
		$guard = $this->guard(stepErrors: ['sla' => 'bad']);
		$this->assertFalse($guard->isPublishableDraft(self::DRAFT, [['fromStatus' => 'zz']], 'wd-3'));
		$this->assertSame([['tasks'], ['steps']], $this->asked);
		$this->assertSame(['sla' => 'bad'], $guard->lastStepConfigErrors());

		$this->asked = [];
		$this->assertFalse($this->guard()->isPublishableDraft(['isDraft' => true], [], 'wd-4'));
		$this->assertSame([['tasks'], ['steps']], $this->asked);

		$this->asked = [];
		$this->assertFalse($this->guard()->isPublishableDraft(self::DRAFT, [['fromStatus' => 's1', 'toStatus' => 'zz']], 'wd-5'));
		$this->assertSame([['tasks'], ['steps'], ['statuses', 'ct-1']], $this->asked);

		$app = 'dossiq';
		$this->assertSame(
			[
				['warning', 'Dossiq: publish() — a task declaration names something that is not there', ['app' => $app, 'id' => 'wd-1', 'refusals' => [['field' => 'form']]]],
				['warning', 'Dossiq: publish() — definition is not a draft', ['app' => $app, 'id' => 'wd-2']],
				['warning', 'Dossiq: publish() refused, a step configuration does not hold', ['app' => $app, 'id' => 'wd-3', 'errors' => ['sla' => 'bad']]],
				['warning', 'Dossiq: publish() — referential integrity failure', ['app' => $app, 'id' => 'wd-4']],
				['warning', 'Dossiq: publish() — referential integrity failure', ['app' => $app, 'id' => 'wd-5']],
			],
			$this->logged
		);
	}//end testEveryRefusalInOrder()

	public function testTheStepErrorsAreForgottenOnTheNextCheck(): void {
		$guard = $this->guard(refusals: ['r']);
		$this->assertFalse($guard->isPublishableDraft(self::DRAFT, [], 'wd-1'));
		$this->assertSame([], $guard->lastStepConfigErrors());
	}//end testTheStepErrorsAreForgottenOnTheNextCheck()
}//end class
