<?php

/**
 * Per-task configuration on a case type.
 *
 * A case type could name a task and say nothing about it. These tests pin the
 * two halves of the block that fixes that: what a declaration MEANS when it is
 * read (this file's first half), and what publishing refuses when it names
 * something that is not there (its second).
 *
 * 🔴 THE ONE-BLOCK RULE IS TESTED IN BOTH DIRECTIONS. A key written beside the
 * block instead of inside it is not read, and a reader that quietly accepted
 * both spellings would be exactly the drift the design refuses. So the reader
 * ignores it AND the validator refuses to publish it: either half alone leaves
 * a declaration that looks honoured and is not.
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
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\Task\TaskDeclarationReader;
use OCA\Dossiq\Service\Task\TaskDeclarationValidator;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskDeclaration
 * @covers \OCA\Dossiq\Service\Task\TaskDeclarationValidator
 * @covers \OCA\Dossiq\Service\Task\TaskDeclarationReader
 */
class PerTaskConfigurationTest extends TestCase {

	/**
	 * One block carries all five settings of one task.
	 *
	 * @return void
	 */
	public function testOneBlockCarriesEverySetting(): void {
		$declaration = TaskDeclaration::of(
			step: [
				'title' => 'Hoor de belanghebbende',
				'status' => 'status-1',
				'task' => [
					'enabled' => true,
					'leadTimeDays' => 10,
					'candidateGroups' => ['Juridische Zaken', 'Juridische Zaken', ' '],
					'candidateUsers' => ['hbakker'],
					'form' => ['kind' => 'fields', 'schema' => 'case', 'fields' => [['field' => 'verslag', 'required' => true]]],
					'effects' => [['type' => 'sendEmail', 'template' => 'uitnodiging']],
				],
			]
		);

		$this->assertTrue($declaration['enabled']);
		$this->assertSame(10, $declaration['leadTimeDays']);
		// Repeated and blank names are dropped: a candidate list with the same
		// team twice offers the task to that team twice in every surface that
		// counts it.
		$this->assertSame(['Juridische Zaken'], $declaration['candidateGroups']);
		$this->assertSame(['hbakker'], $declaration['candidateUsers']);
		$this->assertSame('fields', $declaration['form']['kind']);
		$this->assertCount(1, $declaration['effects']);
	}

	/**
	 * A step with no block behaves exactly as it did before the block existed.
	 *
	 * @return void
	 */
	public function testAStepWithNoBlockRunsAsItAlwaysDid(): void {
		$declaration = TaskDeclaration::of(step: ['title' => 'Toets ontvankelijkheid']);

		$this->assertSame(TaskDeclaration::NONE, $declaration);
		$this->assertTrue($declaration['enabled']);
		$this->assertNull($declaration['form']);
	}

	/**
	 * A task switched off for this case type is switched off.
	 *
	 * @return void
	 */
	public function testATaskCanBeSwitchedOff(): void {
		$declaration = TaskDeclaration::of(
			step: ['title' => 'Vraag advies', 'task' => ['enabled' => false]]
		);

		$this->assertFalse($declaration['enabled']);
	}

	/**
	 * A key written beside the block is not read.
	 *
	 * @return void
	 */
	public function testAKeyBesideTheBlockIsNotRead(): void {
		$declaration = TaskDeclaration::of(
			step: [
				'title' => 'Vraag advies',
				'leadTimeDays' => 30,
				'candidateGroups' => ['Advies'],
				'task' => ['leadTimeDays' => 5],
			]
		);

		$this->assertSame(5, $declaration['leadTimeDays']);
		$this->assertSame([], $declaration['candidateGroups']);
	}

	/**
	 * ...and publishing refuses it, naming the key and the task.
	 *
	 * @return void
	 */
	public function testPublishRefusesAKeyBesideTheBlock(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Vraag advies', 'candidateGroups' => ['Advies'], 'task' => []]]
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('misplaced_task_key', $refusals[0]['code']);
		$this->assertStringContainsString('Vraag advies', $refusals[0]['message']);
		$this->assertStringContainsString('candidateGroups', $refusals[0]['message']);
	}

	/**
	 * An unresolvable form refuses publication, naming the form and the task.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFormRefusesPublication(): void {
		$refusals = $this->validator()->refusals(
			steps: [
				['title' => 'Hoorzitting', 'task' => ['form' => ['kind' => 'wizard']]],
				['title' => 'Advies', 'task' => ['form' => ['kind' => 'fields', 'fields' => [['field' => 'verslag']]]]],
			]
		);

		$this->assertCount(2, $refusals);
		$this->assertSame('unresolvable_form', $refusals[0]['code']);
		$this->assertStringContainsString('Hoorzitting', $refusals[0]['message']);
		$this->assertStringContainsString('wizard', $refusals[0]['message']);
		// The second names the task AND what the form is missing, which is the
		// half that tells an administrator where to look.
		$this->assertStringContainsString('Advies', $refusals[1]['message']);
		$this->assertStringContainsString('schema', $refusals[1]['message']);
	}

	/**
	 * A candidate group nobody has refuses publication.
	 *
	 * @return void
	 */
	public function testAnUnknownCandidateGroupRefusesPublication(): void {
		$refusals = $this->validator(groups: ['Juridische Zaken'])->refusals(
			steps: [['title' => 'Hoorzitting', 'task' => ['candidateGroups' => ['Juridische Zaken', 'Geen Team']]]]
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('unresolvable_group', $refusals[0]['code']);
		$this->assertStringContainsString('Geen Team', $refusals[0]['message']);
	}

	/**
	 * An effect no handler answers to refuses publication, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownEffectRefusesPublication(): void {
		$refusals = $this->validator()->refusals(
			steps: [['title' => 'Hoorzitting', 'task' => ['effects' => [['type' => 'sendEmail'], ['type' => 'teleport']]]]]
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('unresolvable_effect', $refusals[0]['code']);
		$this->assertStringContainsString('teleport', $refusals[0]['message']);
		// The message says what IS available, so the fix does not need the
		// source of the registry.
		$this->assertStringContainsString('sendEmail', $refusals[0]['message']);
	}

	/**
	 * A workflow whose tasks all resolve is publishable.
	 *
	 * @return void
	 */
	public function testAResolvableWorkflowIsPublishable(): void {
		$refusals = $this->validator(groups: ['Juridische Zaken'])->refusals(
			steps: [
				[
					'title' => 'Hoorzitting',
					'task' => [
						'leadTimeDays' => 10,
						'candidateGroups' => ['Juridische Zaken'],
						'form' => ['kind' => 'fields', 'schema' => 'case', 'fields' => [['field' => 'verslag', 'required' => true]]],
						'effects' => [['type' => 'sendEmail']],
					],
				],
				['title' => 'Zonder blok'],
			]
		);

		$this->assertSame([], $refusals);
	}

	/**
	 * The declaration read for a task is the one its status and title name.
	 *
	 * @return void
	 */
	public function testTheStepIsFoundByStatusAndTitle(): void {
		$steps = [
			['title' => 'Hoorzitting', 'status' => 'status-a', 'task' => ['leadTimeDays' => 10]],
			['title' => 'Hoorzitting', 'status' => 'status-b', 'task' => ['leadTimeDays' => 21]],
		];

		$found = TaskDeclarationReader::matching(steps: $steps, statusTypeId: 'status-b', title: 'Hoorzitting');

		$this->assertNotNull($found);
		$this->assertSame(21, TaskDeclaration::of(step: $found)['leadTimeDays']);
		$this->assertNull(TaskDeclarationReader::matching(steps: $steps, statusTypeId: 'status-a', title: 'Advies'));
	}

	/**
	 * A step that names no status answers for its title in any status.
	 *
	 * @return void
	 */
	public function testAStatuslessStepMatchesOnTitleAlone(): void {
		$found = TaskDeclarationReader::matching(
			steps: [['title' => 'Hoorzitting', 'task' => ['leadTimeDays' => 3]]],
			statusTypeId: 'status-z',
			title: 'Hoorzitting'
		);

		$this->assertNotNull($found);
	}

	/**
	 * The steps of a definition decode from the JSON-encoded property.
	 *
	 * @return void
	 */
	public function testStepsDecodeFromTheJsonProperty(): void {
		$steps = TaskDeclarationReader::stepsOf(
			definition: ['steps' => json_encode([['title' => 'Hoorzitting'], 'not a step'])],
			json: new WorkflowJsonProperty()
		);

		$this->assertCount(1, $steps);
		$this->assertSame('Hoorzitting', $steps[0]['title']);
	}

	/**
	 * A validator whose registry knows two effects and whose groups are named.
	 *
	 * @param array<int, string> $groups The groups that exist.
	 *
	 * @return TaskDeclarationValidator The validator.
	 */
	private function validator(array $groups = []): TaskDeclarationValidator {
		$registry = $this->createMock(ActionHandlerRegistry::class);
		$registry->method('getRegisteredTypes')->willReturn(['sendEmail', 'createTask']);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(
			static fn (string $group): bool => in_array($group, $groups, true)
		);

		return new TaskDeclarationValidator(handlers: $registry, groups: $groupManager);
	}
}//end class
