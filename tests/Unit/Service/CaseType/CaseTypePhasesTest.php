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
 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypePhases;
use OCA\Dossiq\Service\CaseTypeResolver;
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
	 *
	 * @return CaseTypePhases The service.
	 */
	private function phases(array $caseType, array $statuses, ?array $template, string $language = 'nl'): CaseTypePhases {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);
		$resolver->method('statusTypesFor')->willReturn($statuses);
		$templates = $this->createMock(WorkflowTemplateLoader::class);
		$templates->method('getActiveTemplate')->willReturn($template);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);

		return new CaseTypePhases(resolver: $resolver, templates: $templates, declarations: new TaskDeclaration(), text: new TranslatedText(l10n: $l10n));
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
	 * A status name is translatable, so the store answers a language map; the
	 * endpoint answers it as text in the reader's language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/screens-round12-case-type-phases/specs/case-types/spec.md
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
}//end class
