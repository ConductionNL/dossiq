<?php

/**
 * A case type's phases list the tasks its workflow runs, in order.
 *
 * Each case pairs what is listed with what is not, because a grouping that
 * drops everything would pass every "is it under the right phase" check.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypePhases;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the phases a case type page shows.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypePhases
 */
class CaseTypePhasesTest extends TestCase {

	/**
	 * The service over a case type, its statuses and its active template.
	 *
	 * @param array<string, mixed>                   $caseType The effective case type.
	 * @param array<int, array<string, mixed>>       $statuses The statuses.
	 * @param array<string, mixed>|null              $template The active template.
	 * @param string                                 $language The reader's language.
	 * @param array<int, array<string, mixed>>       $roles    The role types.
	 *
	 * @return CaseTypePhases The service.
	 */
	private function phases(array $caseType, array $statuses, ?array $template, string $language = 'nl', array $roles = []): CaseTypePhases {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);
		$resolver->method('statusTypesFor')->willReturn($statuses);
		$resolver->method('chainFor')->willReturn([['id' => 'ct']]);
		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->method('rowsOfType')->willReturnMap([['role_type_schema', 'ct', $roles]]);
		$store->method('rowId')->willReturnCallback(
			static fn (array $row): string => (string) ($row['id'] ?? ($row['@self']['id'] ?? ''))
		);
		$templates = $this->createMock(WorkflowTemplateLoader::class);
		$templates->method('getActiveTemplate')->willReturn($template);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);

		return new CaseTypePhases(
			resolver: $resolver,
			templates: $templates,
			declarations: new TaskDeclaration(),
			text: new TranslatedText(l10n: $l10n),
			store: $store
		);
	}//end phases()

	/**
	 * Two statuses, out of order on the wire.
	 *
	 * @return array<int, array<string, mixed>> The statuses.
	 */
	private function statuses(): array {
		return [
			['id' => 's2', 'name' => 'Beoordeling', 'order' => 2],
			['id' => 's1', 'name' => 'Ontvangst', 'order' => 1],
		];
	}//end statuses()

	/**
	 * Tasks sit under their status, both in order, with role and lead time.
	 *
	 * @return void
	 */
	public function testTasksSitUnderTheirPhaseInOrder(): void {
		$template = ['steps' => [
			['id' => 't2', 'title' => 'Ontvangstbevestiging', 'status' => 's1', 'order' => 2],
			['id' => 't1', 'title' => 'Volledigheid toetsen', 'status' => 's1', 'order' => 1, 'task' => ['leadTimeDays' => 5, 'candidateGroups' => ['Intaker']]],
			['id' => 't3', 'title' => 'Toets aan het omgevingsplan', 'status' => 's2', 'order' => 1, 'routingRule' => ['roleType' => 'r-vv']],
		]];
		$result = $this->phases(caseType: ['id' => 'ct'], statuses: $this->statuses(), template: $template)->phasesFor(caseTypeId: 'ct');

		$this->assertSame(['s1', 's2'], array_column($result['phases'], 'id'));
		$this->assertSame(['ct', 'ct'], array_column($result['phases'], 'caseTypeId'));
		$this->assertSame(['Volledigheid toetsen', 'Ontvangstbevestiging'], array_column($result['phases'][0]['tasks'], 'title'));
		$this->assertSame(5, $result['phases'][0]['tasks'][0]['leadTimeDays']);
		$this->assertSame(['Intaker'], $result['phases'][0]['tasks'][0]['candidateGroups']);
		$this->assertSame('r-vv', $result['phases'][1]['tasks'][0]['roleTypeId']);
		$this->assertSame(3, $result['taskCount']);
		$this->assertSame([], $result['unplaced']);
	}//end testTasksSitUnderTheirPhaseInOrder()

	/**
	 * A switched-off task is not listed; one on an unknown status is listed apart.
	 *
	 * @return void
	 */
	public function testOffTasksAreLeftOutAndStrayTasksAreShownApart(): void {
		$template = ['steps' => [
			['id' => 'off', 'title' => 'Niet meer', 'status' => 's1', 'task' => ['enabled' => false]],
			['id' => 'stray', 'title' => 'Zwerver', 'status' => 'gone'],
			['id' => 'kept', 'title' => 'Blijft', 'status' => 's2'],
		]];
		$result = $this->phases(caseType: ['id' => 'ct'], statuses: $this->statuses(), template: $template)->phasesFor(caseTypeId: 'ct');

		$this->assertSame([], $result['phases'][0]['tasks']);
		$this->assertSame(['Blijft'], array_column($result['phases'][1]['tasks'], 'title'));
		$this->assertSame(['Zwerver'], array_column($result['unplaced'], 'title'));
		$this->assertSame(2, $result['taskCount']);
	}//end testOffTasksAreLeftOutAndStrayTasksAreShownApart()

	/**
	 * The acts available in every phase come along; an unreadable type answers null.
	 *
	 * @return void
	 */
	public function testAlwaysAvailableActsAndAnUnreadableType(): void {
		$caseType = ['id' => 'ct', 'alwaysAvailableActs' => [['id' => 'suspend', 'label' => 'Termijn opschorten']]];
		$result   = $this->phases(caseType: $caseType, statuses: $this->statuses(), template: null)->phasesFor(caseTypeId: 'ct');

		$this->assertSame(['suspend'], array_column($result['alwaysAvailable'], 'id'));
		$this->assertSame(0, $result['taskCount']);
		$this->assertNull($this->phases(caseType: [], statuses: [], template: null)->phasesFor(caseTypeId: 'missing'));
	}//end testAlwaysAvailableActsAndAnUnreadableType()
	/**
	 * Statuses that name one phase share it: named by the phase, ordered by the
	 * first of them, holding the tasks of all of them. A final status with no
	 * phase and no task is not a phase once the type is phased; without any
	 * phase every status stays its own phase.
	 *
	 * @return void
	 */
	public function testStatusesThatNameOnePhaseShareIt(): void {
		$statuses = [
			['id' => 'ontv', 'name' => 'Ontvangen', 'order' => 1, 'phase' => 'Ontvangst'],
			['id' => 'aanv', 'name' => 'Aanvullende gegevens gevraagd', 'order' => 2, 'phase' => 'Ontvangst'],
			['id' => 'beh', 'name' => 'In behandeling', 'order' => 3, 'phase' => 'Beoordeling'],
			['id' => 'bes', 'name' => 'Besluit', 'order' => 4, 'phase' => ['nl' => 'Besluit', 'en' => 'Decision']],
			['id' => 'verl', 'name' => 'Verleend', 'order' => 5, 'isFinal' => true],
			['id' => 'weig', 'name' => 'Geweigerd', 'order' => 6, 'isFinal' => true],
		];
		$template = ['steps' => [
			['id' => 't1', 'title' => 'Volledigheid toetsen', 'status' => 'ontv', 'order' => 1],
			['id' => 't2', 'title' => 'Ontvangstbevestiging', 'status' => 'aanv', 'order' => 2],
			['id' => 't3', 'title' => 'Toets aan het omgevingsplan', 'status' => 'beh', 'order' => 1],
			['id' => 't4', 'title' => 'Besluit opstellen', 'status' => 'bes', 'order' => 1],
		]];
		$result = $this->phases(caseType: ['id' => 'ct'], statuses: $statuses, template: $template)->phasesFor(caseTypeId: 'ct');

		$this->assertSame(['Ontvangst', 'Beoordeling', 'Besluit'], array_column($result['phases'], 'name'));
		$this->assertSame(['ontv', 'aanv'], $result['phases'][0]['statusIds']);
		$this->assertSame(['Volledigheid toetsen', 'Ontvangstbevestiging'], array_column($result['phases'][0]['tasks'], 'title'));
		$this->assertSame(['Besluit opstellen'], array_column($result['phases'][2]['tasks'], 'title'));
		$this->assertSame([], $result['unplaced']);
		// Numbered by place, not by the first status's order (3 and 4): "Fase 2: Beoordeling".
		$this->assertSame(expected: [1, 2, 3], actual: array_column(array: $result['phases'], column_key: 'number'));

		$plain = $this->phases(caseType: ['id' => 'ct'], statuses: $this->statuses(), template: null)->phasesFor(caseTypeId: 'ct');
		$this->assertSame(['Ontvangst', 'Beoordeling'], array_column($plain['phases'], 'name'));
	}//end testStatusesThatNameOnePhaseShareIt()

	/**
	 * A status name is translatable, so the store answers a language map; the
	 * endpoint answers it as text in the reader's language.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testAPhaseIsNamedAsTextInTheReadersLanguage(): void {
		$result = $this->phases(
			['id' => 'ct-1'],
			[['id' => 's1', 'name' => ['en' => 'Intake', 'nl' => 'Ontvangst'], 'order' => 1]],
			null,
			'en'
		)->phasesFor(caseTypeId: 'ct-1');

		self::assertSame('Intake', $result['phases'][0]['name']);
	}//end testAPhaseIsNamedAsTextInTheReadersLanguage()

	/**
	 * A task names the role it routes to, in the reader's language, and keeps
	 * its description: DqZaaktype reads "Intaker · 5 werkdagen" on a task with
	 * a term, and the description on one without. A task routed to no role, or
	 * to a role the type does not have, names none.
	 *
	 * @return void
	 */
	public function testATaskNamesItsRoleAndKeepsItsDescription(): void {
		$template = ['steps' => [
			[
				'id'          => 't1',
				'title'       => 'Volledigheid toetsen',
				'status'      => 's1',
				'order'       => 1,
				'routingRule' => ['roleType' => 'r-in'],
				'task'        => ['leadTimeDays' => 5],
			],
			['id' => 't2', 'title' => 'Ontvangstbevestiging', 'status' => 's1', 'order' => 2, 'description' => ' Automatisch, bericht aan de aanvrager '],
			['id' => 't3', 'title' => 'Besluit opstellen', 'status' => 's2', 'order' => 1, 'assigneeRole' => 'r-vv'],
			['id' => 't4', 'title' => 'Zwerver', 'status' => 's2', 'order' => 2, 'routingRule' => ['roleType' => 'r-gone']],
		]];
		$roles    = [
			['id' => 'r-in', 'name' => ['nl' => 'Intaker', 'en' => 'Intake officer']],
			['@self' => ['id' => 'r-vv'], 'name' => 'Vergunningverlener'],
		];
		$result   = $this->phases(
			caseType: ['id' => 'ct'],
			statuses: $this->statuses(),
			template: $template,
			roles: $roles
		)->phasesFor(caseTypeId: 'ct');

		self::assertSame(expected: ['Intaker', ''], actual: array_column($result['phases'][0]['tasks'], 'roleName'));
		self::assertSame(expected: ['', 'Automatisch, bericht aan de aanvrager'], actual: array_column($result['phases'][0]['tasks'], 'description'));
		self::assertSame(expected: ['Vergunningverlener', ''], actual: array_column($result['phases'][1]['tasks'], 'roleName'));
	}//end testATaskNamesItsRoleAndKeepsItsDescription()
}//end class
