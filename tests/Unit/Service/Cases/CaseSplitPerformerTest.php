<?php

/**
 * Dossiq CaseSplitPerformer test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Cases\CaseSplitPerformer;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The half that was missing: reading the case, opening the second one and
 * performing the plan the two merged services describe.
 *
 * The policy and the plan are the REAL ones, not doubles. They are pure and
 * they are the judgement; substituting them would leave this test asserting
 * that the performer calls something, rather than that a split divides a case.
 *
 * @spec openspec/changes/case-split-surface/specs/case-management/spec.md
 */
class CaseSplitPerformerTest extends TestCase {
	/**
	 * The object store.
	 *
	 * @var FakeSplitObjectService
	 */
	private FakeSplitObjectService $objects;

	/**
	 * The one writer of a typed case link.
	 *
	 * @var CaseRelationService&MockObject
	 */
	private CaseRelationService $relations;

	/**
	 * The relations written, in order.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $related = [];

	/**
	 * One case with three documents and two parties.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new FakeSplitObjectService();
		$this->related = [];

		$this->relations = $this->createMock(CaseRelationService::class);
		$this->relations->method('addRelation')->willReturnCallback(
			function (string $caseId, string $targetId, string $natureRelationship, ?string $notes = null): array {
				$this->related[] = [
					'from' => $caseId,
					'to' => $targetId,
					'nature' => $natureRelationship,
					'notes' => (string)$notes,
				];
				return ['ok' => true];
			}
		);

		$this->objects->store('2', 'case-1', ['id' => 'case-1', 'title' => 'Twee klachten', 'caseType' => 'ct-1']);
		$this->objects->store('2', 'case-other', ['id' => 'case-other', 'title' => 'Andermans zaak']);

		foreach (['d1', 'd2', 'd3'] as $doc) {
			$this->objects->store('4', $doc, ['id' => $doc, 'case' => 'case-1', 'title' => 'stuk ' . $doc]);
		}

		$this->objects->store('4', 'd-foreign', ['id' => 'd-foreign', 'case' => 'case-other', 'title' => 'andermans stuk']);

		foreach (['p1', 'p2'] as $party) {
			$this->objects->store('5', $party, ['id' => $party, 'case' => 'case-1', 'name' => $party]);
		}
	}//end setUp()

	/**
	 * Scenario: A chosen document moves and the rest stays.
	 *
	 * @return void
	 */
	public function testAChosenDocumentMovesAndTheRestStays(): void {
		$outcome = $this->performer()->perform(
			source: $this->objects->read('2', 'case-1'),
			caseType: null,
			selection: ['documents' => ['d1']],
			title: 'Tweede klacht'
		);

		$newId = (string)$outcome['case']['id'];
		$this->assertNotSame('case-1', $newId);
		$this->assertSame(1, $outcome['moved']);

		// 🔴 IT MOVED. A split that copied would leave both cases claiming the
		// same document, which is the defect and not the feature.
		$this->assertSame($newId, $this->objects->read('4', 'd1')['case']);
		$this->assertSame('case-1', $this->objects->read('4', 'd2')['case']);
		$this->assertSame('case-1', $this->objects->read('4', 'd3')['case']);
	}//end testAChosenDocumentMovesAndTheRestStays()

	/**
	 * Scenario: A row that is not on this case is refused by name.
	 *
	 * @return void
	 */
	public function testARowOnAnotherCaseIsRefusedByNameAndNotMoved(): void {
		$outcome = $this->performer()->perform(
			source: $this->objects->read('2', 'case-1'),
			caseType: null,
			selection: ['documents' => ['d1', 'd-foreign']],
			title: ''
		);

		$this->assertSame(1, $outcome['moved']);
		// Named, not silently dropped: a selection that half happened with no
		// word about the rest is the state nobody can reconstruct later.
		$this->assertSame(['d-foreign'], array_column($outcome['refusedRows'], 'id'));
		$this->assertSame('case-other', $this->objects->read('4', 'd-foreign')['case']);
	}//end testARowOnAnotherCaseIsRefusedByNameAndNotMoved()

	/**
	 * Scenario: A forbidden part is refused in the policy's own words, and
	 * nothing moves and no case is opened.
	 *
	 * @return void
	 */
	public function testAForbiddenPartIsRefusedInThePolicysOwnWords(): void {
		$before = count($this->objects->all('2'));

		$outcome = $this->performer()->perform(
			source: $this->objects->read('2', 'case-1'),
			caseType: ['splittableParts' => ['parties', 'tasks']],
			selection: ['documents' => ['d1']],
			title: ''
		);

		$this->assertStringContainsString('does not allow documents', $outcome['refused']);
		// The half a bare refusal leaves out.
		$this->assertStringContainsString('parties', $outcome['refused']);
		$this->assertArrayNotHasKey('case', $outcome);
		$this->assertSame('case-1', $this->objects->read('4', 'd1')['case']);
		$this->assertCount($before, $this->objects->all('2'));
		$this->assertSame([], $this->related);
	}//end testAForbiddenPartIsRefusedInThePolicysOwnWords()

	/**
	 * Scenario: The picker offers only the parts the case type allows.
	 *
	 * @return void
	 */
	public function testThePickerIsOfferedOnlyThePartsTheCaseTypeAllows(): void {
		$offered = $this->performer()->divisible(
			caseId: 'case-1',
			caseType: ['splittableParts' => ['parties', 'tasks']]
		);

		$this->assertSame(['parties', 'tasks'], array_keys($offered));
		$this->assertSame(['p1', 'p2'], array_column($offered['parties'], 'id'));
		// The label a picker shows, and never a bare uuid: a list of thirty-two
		// hex characters is a list nobody can choose from.
		$this->assertSame(['p1', 'p2'], array_column($offered['parties'], 'label'));
	}//end testThePickerIsOfferedOnlyThePartsTheCaseTypeAllows()

	/**
	 * A case type that declares nothing offers all three, because that is what
	 * every case type meant before the key existed.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDeclaresNothingOffersAllThree(): void {
		$this->assertSame(
			['documents', 'parties', 'tasks'],
			array_keys($this->performer()->divisible(caseId: 'case-1', caseType: null))
		);
	}//end testACaseTypeThatDeclaresNothingOffersAllThree()

	/**
	 * Both cases name each other, through the relation and the note the plan
	 * wrote rather than a sentence invented here.
	 *
	 * @return void
	 */
	public function testTheRelationAndItsNoteComeFromThePlan(): void {
		$outcome = $this->performer()->perform(
			source: $this->objects->read('2', 'case-1'),
			caseType: null,
			selection: ['documents' => ['d1', 'd2'], 'parties' => ['p1']],
			title: ''
		);

		$this->assertCount(1, $this->related);
		$this->assertSame('case-1', $this->related[0]['from']);
		$this->assertSame((string)$outcome['case']['id'], $this->related[0]['to']);
		$this->assertSame(CaseSplitPlan::RELATION, $this->related[0]['nature']);
		// One note for the whole split, not one per item.
		$this->assertNotSame('', $this->related[0]['notes']);
	}//end testTheRelationAndItsNoteComeFromThePlan()

	/**
	 * A split of nothing is refused before a second case is opened: an empty
	 * case with a relation to the original is worse than no split at all.
	 *
	 * @return void
	 */
	public function testASplitOfNothingOpensNoCase(): void {
		$before = count($this->objects->all('2'));

		$outcome = $this->performer()->perform(
			source: $this->objects->read('2', 'case-1'),
			caseType: null,
			selection: ['documents' => []],
			title: ''
		);

		$this->assertNotSame('', (string)$outcome['refused']);
		$this->assertCount($before, $this->objects->all('2'));
		$this->assertSame([], $this->related);
	}//end testASplitOfNothingOpensNoCase()

	/**
	 * The performer over the fakes, with the REAL policy and plan.
	 *
	 * @return CaseSplitPerformer The service under test.
	 */
	private function performer(): CaseSplitPerformer {
		$config = [
			'register' => '1',
			'case_schema' => '2',
			'case_type_schema' => '3',
			'case_document_schema' => '4',
			'role_schema' => '5',
			'case_object_schema' => '6',
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->objects);

		return new CaseSplitPerformer(
			settingsService: $settings,
			policy: new CaseSplitPolicy(),
			plan: new CaseSplitPlan(),
			relations: $this->relations,
			logger: new NullLogger()
		);
	}//end performer()
}//end class
