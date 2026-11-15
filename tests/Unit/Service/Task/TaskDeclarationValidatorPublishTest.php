<?php

/**
 * TaskDeclarationValidator: refusals over a raw definition, bound forms and
 * retired effects.
 *
 * PerTaskConfigurationTest pins the field-form, group and misplaced-key
 * refusals. This file pins the rest of what publishing a definition asks:
 * the steps arrive JSON-encoded and are decoded here, a bound (external)
 * form needs a form id, a step without a title is still named in its
 * refusal, and a retired effect type that still runs as its replacement node
 * is not refused as unknown.
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
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Flow\RetiredActionRunner;
use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\Task\TaskDeclarationValidator;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskDeclarationValidator
 * @uses \OCA\Dossiq\Service\Task\TaskDeclaration
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 * @uses \OCA\Dossiq\Service\Task\TaskFormAt
 */
class TaskDeclarationValidatorPublishTest extends TestCase {

	/**
	 * A validator whose registry knows `sendEmail` and whose groups all exist.
	 *
	 * @param RetiredActionRunner|null $retired The retired-effect runner, if any.
	 *
	 * @return TaskDeclarationValidator The validator.
	 */
	private function validator(?RetiredActionRunner $retired = null): TaskDeclarationValidator {
		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->method('getRegisteredTypes')->willReturn(['sendEmail']);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturn(true);

		return new TaskDeclarationValidator(
			handlers: $registry,
			groups: $groups,
			declaration: new TaskDeclaration(),
			steps: new WorkflowJsonProperty(),
			retired: $retired
		);
	}//end validator()

	/**
	 * A raw definition's JSON-encoded steps are decoded before they are judged.
	 *
	 * @return void
	 */
	public function testARawDefinitionIsDecodedAndJudged(): void {
		$definition = [
			'steps' => json_encode(
				[
					['title' => 'Beoordeel', 'task' => ['effects' => [['type' => 'sendEmail']]]],
					['title' => 'Verstuur', 'task' => ['effects' => [['type' => 'faxen']]]],
				]
			),
		];

		$refusals = $this->validator()->refusalsFor($definition);

		self::assertCount(1, $refusals);
		self::assertSame('steps[1].task.effects[0].type', $refusals[0]['path']);
		self::assertSame('unresolvable_effect', $refusals[0]['code']);
		self::assertSame(
			'Task "Verstuur" declares the effect "faxen", which no handler answers to. Known effects: sendEmail.',
			$refusals[0]['message']
		);
	}//end testARawDefinitionIsDecodedAndJudged()

	/**
	 * A definition with nothing to refuse may be published.
	 *
	 * @return void
	 */
	public function testACleanDefinitionHasNoRefusals(): void {
		self::assertSame([], $this->validator()->refusalsFor(['steps' => '']));
		self::assertSame(
			[],
			$this->validator()->refusalsFor(['steps' => [['title' => 'Beoordeel', 'task' => ['leadTimeDays' => 5]]]])
		);
	}//end testACleanDefinitionHasNoRefusals()

	/**
	 * A bound form with a form id is resolvable.
	 *
	 * @return void
	 */
	public function testAnExternalFormWithAnIdPasses(): void {
		$refusals = $this->validator()->refusals(
			[['title' => 'Vul in', 'task' => ['form' => ['kind' => 'external', 'formId' => 12]]]]
		);

		self::assertSame([], $refusals);
	}//end testAnExternalFormWithAnIdPasses()

	/**
	 * A bound form without a form id is refused, naming the task.
	 *
	 * @return void
	 */
	public function testAnExternalFormWithoutAnIdIsRefused(): void {
		$refusals = $this->validator()->refusals(
			[['title' => 'Vul in', 'task' => ['form' => ['kind' => 'external', 'formId' => '0']]]]
		);

		self::assertSame(
			[
				[
					'path' => 'steps[0].task.form.formId',
					'code' => 'unresolvable_form',
					'message' => 'Task "Vul in" names an external form without a form id. Nobody can open it.',
				],
			],
			$refusals
		);
	}//end testAnExternalFormWithoutAnIdIsRefused()

	/**
	 * A step with no title is named by its position, and a non-array step is skipped.
	 *
	 * @return void
	 */
	public function testAnUntitledStepIsNamedByItsPosition(): void {
		$refusals = $this->validator()->refusals(
			[
				'not a step',
				['task' => ['form' => ['kind' => 'sheet']]],
			]
		);

		self::assertCount(1, $refusals);
		self::assertSame('steps[1].task.form.kind', $refusals[0]['path']);
		self::assertSame(
			'Task "step 1" names a form of kind "sheet", which does not exist. Use one of: fields, external.',
			$refusals[0]['message']
		);
	}//end testAnUntitledStepIsNamedByItsPosition()

	/**
	 * A retired effect type that still runs is known; one that does not stays refused.
	 *
	 * @return void
	 */
	public function testARunnableRetiredEffectIsNotRefused(): void {
		$retired = $this->createMock(RetiredActionRunner::class);
		$retired->expects($this->once())->method('runnableTypes')->willReturn(['notify']);

		$refusals = $this->validator($retired)->refusals(
			[
				[
					'title' => 'Meld',
					'task' => ['effects' => [['type' => 'notify'], ['type' => 'webhook']]],
				],
			]
		);

		self::assertCount(1, $refusals);
		self::assertSame('steps[0].task.effects[1].type', $refusals[0]['path']);
		self::assertSame(
			'Task "Meld" declares the effect "webhook", which no handler answers to. Known effects: sendEmail, notify.',
			$refusals[0]['message']
		);
	}//end testARunnableRetiredEffectIsNotRefused()
}//end class
