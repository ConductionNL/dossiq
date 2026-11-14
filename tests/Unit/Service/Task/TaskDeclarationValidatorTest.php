<?php

/**
 * What publishing refuses in a task block: forms, groups and effects that
 * cannot be resolved, and task keys written beside the block.
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
 * @uses \OCA\Dossiq\Service\Task\TaskFormAt
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 */
class TaskDeclarationValidatorTest extends TestCase {

	/**
	 * Build a validator that knows the given effect types and groups.
	 *
	 * @param string[]                 $types   Registered effect types.
	 * @param string[]                 $groups  Groups that exist.
	 * @param RetiredActionRunner|null $retired The retired-action runner, if any.
	 *
	 * @return TaskDeclarationValidator The validator.
	 */
	private function validator(array $types=[], array $groups=[], ?RetiredActionRunner $retired=null): TaskDeclarationValidator {
		$handlers = $this->createMock(ActionHandlerRegistry::class);
		$handlers->method('getRegisteredTypes')->willReturn($types);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $gid): bool => in_array($gid, $groups, true)
		);

		return new TaskDeclarationValidator(
			handlers: $handlers,
			groups: $groupManager,
			declaration: new TaskDeclaration(),
			steps: new WorkflowJsonProperty(),
			retired: $retired
		);
	}//end validator()

	/**
	 * Non-array steps are skipped and an untitled step is named by its index.
	 *
	 * @return void
	 */
	public function testUntitledStepIsNamedByIndex(): void {
		$refusals = $this->validator()->refusals(steps: ['noise', ['leadTimeDays' => 3]]);

		$this->assertSame(
			expected: [
				[
					'path' => 'steps[1].leadTimeDays',
					'code' => 'misplaced_task_key',
					'message' => 'Task "step 1" sets "leadTimeDays" outside its task block. Nothing reads it there.',
				],
			],
			actual: $refusals
		);
	}//end testUntitledStepIsNamedByIndex()

	/**
	 * An external form with a positive form id is accepted.
	 *
	 * @return void
	 */
	public function testExternalFormWithIdIsAccepted(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Intake', 'task' => ['form' => ['kind' => 'external', 'formId' => '12']]]]
		);

		$this->assertSame(expected: [], actual: $refusals);
	}//end testExternalFormWithIdIsAccepted()

	/**
	 * An external form without a usable id is refused at its formId.
	 *
	 * @return void
	 */
	public function testExternalFormWithoutIdIsRefused(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Intake', 'task' => ['form' => ['kind' => 'external', 'formId' => 0]]]]
		);

		$this->assertSame(
			expected: [
				[
					'path' => 'steps[0].task.form.formId',
					'code' => 'unresolvable_form',
					'message' => 'Task "Intake" names an external form without a form id. Nobody can open it.',
				],
			],
			actual: $refusals
		);
	}//end testExternalFormWithoutIdIsRefused()

	/**
	 * An unknown form kind lists the kinds that exist.
	 *
	 * @return void
	 */
	public function testUnknownFormKindIsRefused(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Intake', 'task' => ['form' => ['kind' => 'pdf']]]]
		);

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertSame(expected: 'steps[0].task.form.kind', actual: $refusals[0]['path']);
		$this->assertSame(
			expected: 'Task "Intake" names a form of kind "pdf", which does not exist. Use one of: fields, external.',
			actual: $refusals[0]['message']
		);
	}//end testUnknownFormKindIsRefused()

	/**
	 * A form of fields without schema and with an unnamed field gets both refusals.
	 *
	 * @return void
	 */
	public function testFieldFormRefusesMissingSchemaAndUnnamedField(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Intake', 'task' => ['form' => ['kind' => 'fields', 'fields' => [['field' => 'a'], 'x']]]]]
		);

		$this->assertSame(
			expected: ['steps[0].task.form.schema', 'steps[0].task.form.fields[1].field'],
			actual: array_column($refusals, 'path')
		);
	}//end testFieldFormRefusesMissingSchemaAndUnnamedField()

	/**
	 * A form of fields with no fields asks for nothing and is refused.
	 *
	 * @return void
	 */
	public function testFieldFormWithoutFieldsIsRefused(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Intake', 'task' => ['form' => ['kind' => 'fields', 'schema' => 'case', 'fields' => 'none']]]]
		);

		$this->assertSame(expected: ['steps[0].task.form.fields'], actual: array_column($refusals, 'path'));
	}//end testFieldFormWithoutFieldsIsRefused()

	/**
	 * Only the candidate groups that do not exist are refused, at their position.
	 *
	 * @return void
	 */
	public function testUnknownGroupIsRefused(): void {
		$refusals = $this->validator(groups: ['Juridisch'])->refusals(
			steps: [['title' => 'Toets', 'task' => ['candidateGroups' => ['Juridisch', 'Spook']]]]
		);

		$this->assertSame(
			expected: [
				[
					'path' => 'steps[0].task.candidateGroups[1]',
					'code' => 'unresolvable_group',
					'message' => 'Task "Toets" names the candidate group "Spook", which does not exist.',
				],
			],
			actual: $refusals
		);
	}//end testUnknownGroupIsRefused()

	/**
	 * An effect is known when the registry or the retired runner answers to it.
	 *
	 * @return void
	 */
	public function testRetiredRunnerTypesAreKnownEffects(): void {
		$retired = $this->createMock(RetiredActionRunner::class);
		$retired->expects($this->once())->method('runnableTypes')->willReturn(['legacyMail']);

		$refusals = $this->validator(types: ['sendEmail'], retired: $retired)->refusals(
			steps: [['title' => 'Toets', 'task' => ['effects' => [['type' => 'sendEmail'], ['type' => 'legacyMail'], ['type' => 'fax']]]]]
		);

		$this->assertSame(
			expected: [
				[
					'path' => 'steps[0].task.effects[2].type',
					'code' => 'unresolvable_effect',
					'message' => 'Task "Toets" declares the effect "fax", which no handler answers to. Known effects: sendEmail, legacyMail.',
				],
			],
			actual: $refusals
		);
	}//end testRetiredRunnerTypesAreKnownEffects()

	/**
	 * Steps are read from a definition whose steps are stored as JSON.
	 *
	 * @return void
	 */
	public function testRefusalsForReadsTheDefinitionSteps(): void {
		$refusals = $this->validator()->refusalsFor(
			definition: ['steps' => json_encode([['title' => 'A', 'effects' => []]])]
		);

		$this->assertSame(expected: ['steps[0].effects'], actual: array_column($refusals, 'path'));
	}//end testRefusalsForReadsTheDefinitionSteps()
}//end class
