<?php

/**
 * Adding a task to a phase of a case type.
 *
 * A task is a step of the case type's newest draft workflow definition, under
 * the status it runs in. A published definition is never changed, so a type
 * without a draft is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/changes/case-type-phase-add-task/specs/case-type-phase-add-task/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypePhaseSteps;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for adding a task to a phase.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypePhaseSteps
 */
class CaseTypePhaseStepsTest extends TestCase {

	private WorkflowDefinitionRepository&MockObject $definitions;

	/** @var array<int, array{payload: array<string, mixed>, uuid: string|null}> */
	private array $saved = [];

	/**
	 * The service over a case type with two statuses and the given definitions.
	 *
	 * @param array<int, array<string, mixed>> $versions The definitions, newest first.
	 *
	 * @return CaseTypePhaseSteps The service.
	 */
	private function steps(array $versions): CaseTypePhaseSteps {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturn([['id' => 'ontvangen'], ['@self' => ['id' => 'beoordeling']]]);

		$this->definitions = $this->createMock(WorkflowDefinitionRepository::class);
		$this->definitions->method('listVersionsForCaseType')->willReturn($versions);
		$this->definitions->method('save')->willReturnCallback(
			function (array $payload, ?string $uuid = null): array {
				$this->saved[] = ['payload' => $payload, 'uuid' => $uuid];
				return $payload;
			}
		);

		$guard = $this->createMock(WorkflowLifecycleGuard::class);
		$guard->method('statusOf')->willReturnCallback(static fn (array $row): string => (string) $row['lifecycleStatus']);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 32));

		return new CaseTypePhaseSteps(resolver: $resolver, definitions: $this->definitions, guard: $guard, random: $random);
	}//end steps()

	/**
	 * A draft version 5 over a published version 4, the draft with one task in each phase.
	 *
	 * @return array<int, array<string, mixed>> The definitions, newest first.
	 */
	private function versions(): array {
		return [
			[
				'id'              => 'wf-5',
				'version'         => 5,
				'lifecycleStatus' => 'draft',
				'steps'           => json_encode([
					['id' => 's1', 'title' => 'Volledigheid toetsen', 'status' => 'ontvangen', 'order' => 1],
					['id' => 's2', 'title' => 'Toets aan het omgevingsplan', 'status' => 'beoordeling', 'order' => 3],
				]),
			],
			['id' => 'wf-4', 'version' => 4, 'lifecycleStatus' => 'published', 'steps' => '[]'],
		];
	}//end versions()

	public function testAddsTheTaskToTheDraftAfterThePhasesLastTask(): void {
		$result = $this->steps($this->versions())->addStep(
			'ct-1',
			'beoordeling',
			['title' => ' Welstandsadvies vragen ', 'leadTimeDays' => '10', 'candidateGroups' => ['vergunningen', '', 3]]
		);

		$this->assertSame(CaseTypePhaseSteps::ADDED, $result['outcome']);
		$this->assertSame('wf-5', $result['definitionId']);
		$this->assertSame(5, $result['version']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('wf-5', $this->saved[0]['uuid']);

		$steps = json_decode($this->saved[0]['payload']['steps'], true);
		$this->assertCount(3, $steps);
		$added = $steps[2];
		$this->assertSame('Welstandsadvies vragen', $added['title']);
		$this->assertSame('beoordeling', $added['status']);
		$this->assertSame(4, $added['order']);
		$this->assertSame(['leadTimeDays' => 10, 'candidateGroups' => ['vergunningen']], $added['task']);
		$this->assertSame('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', $added['id']);
	}//end testAddsTheTaskToTheDraftAfterThePhasesLastTask()

	public function testAnInheritedPhaseTakesTheTask(): void {
		$result = $this->steps($this->versions())->addStep('ct-1', 'ontvangen', ['title' => 'Ontvangstbevestiging']);

		$this->assertSame(CaseTypePhaseSteps::ADDED, $result['outcome']);
		$this->assertSame(2, $result['step']['order']);
	}//end testAnInheritedPhaseTakesTheTask()

	public function testNeverChangesAPublishedDefinition(): void {
		$result = $this->steps([$this->versions()[1]])->addStep('ct-1', 'ontvangen', ['title' => 'Ontvangstbevestiging']);

		$this->assertSame(CaseTypePhaseSteps::NO_DRAFT, $result['outcome']);
		$this->assertSame([], $this->saved);
	}//end testNeverChangesAPublishedDefinition()

	public function testRefusesAPhaseTheTypeDoesNotHave(): void {
		$result = $this->steps($this->versions())->addStep('ct-1', 'elsewhere', ['title' => 'Ontvangstbevestiging']);

		$this->assertSame(CaseTypePhaseSteps::NO_PHASE, $result['outcome']);
		$this->assertSame([], $this->saved);
	}//end testRefusesAPhaseTheTypeDoesNotHave()

	public function testRefusesATaskWithoutATitle(): void {
		$result = $this->steps($this->versions())->addStep('ct-1', 'ontvangen', ['title' => '  ']);

		$this->assertSame(CaseTypePhaseSteps::NO_TITLE, $result['outcome']);
		$this->assertSame([], $this->saved);
	}//end testRefusesATaskWithoutATitle()

	public function testKeepsStepsAnArrayWhenTheDraftStoresThemSo(): void {
		$versions = $this->versions();
		$versions[0]['steps'] = json_decode($versions[0]['steps'], true);

		$this->steps($versions)->addStep('ct-1', 'ontvangen', ['title' => 'Ontvangstbevestiging']);

		$this->assertIsArray($this->saved[0]['payload']['steps']);
		$this->assertCount(3, $this->saved[0]['payload']['steps']);
	}//end testKeepsStepsAnArrayWhenTheDraftStoresThemSo()
}//end class
