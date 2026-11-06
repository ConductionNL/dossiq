<?php

/**
 * One melding opening a handhaving case and an onderhoud case.
 *
 * The scenario the register asks for is a fan-out at intake, not a hierarchy
 * drawn afterwards, so the test drives the submission and then looks at what is
 * in the store: two cases, each carrying only its own department, both naming
 * the submission, and related to one another.
 *
 * The failing destination is driven deliberately. A retired case type on one
 * destination must not cost the other departments their case, and a failure that
 * is only logged is a case nobody knows is missing, so the reason comes back in
 * the answer and it reads as a sentence rather than as an engine code.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\IntakeFanOut;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An in-memory case store that can be told to refuse one case type.
 */
class FanOutCaseStore {

	/**
	 * Stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * The case type this store refuses to write, standing in for a retired one.
	 *
	 * @var string
	 */
	public string $retiredCaseType = '';

	/**
	 * How many cases have been written.
	 *
	 * @var integer
	 */
	private int $written = 0;

	/**
	 * Store one case.
	 *
	 * @param array<string, mixed> $object   The case.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string|null          $uuid     The id to update, or null to create.
	 *
	 * @return array<string, mixed> The stored case.
	 *
	 * @throws RuntimeException When the case type is the retired one.
	 */
	public function saveObject(
		array $object,
		string $register = '',
		string $schema = '',
		?string $uuid = null,
	): array {
		if ((string)($object['caseType'] ?? '') === $this->retiredCaseType && $this->retiredCaseType !== '') {
			throw new RuntimeException('The case type has been retired.');
		}

		$this->written++;
		$id = ($uuid ?? ('case-' . $this->written));
		$this->cases[$id] = array_merge($object, ['id' => $id]);

		return $this->cases[$id];
	}//end saveObject()

	/**
	 * Merge changes into a stored case.
	 *
	 * @param string               $objectId The case.
	 * @param array<string, mixed> $data     The changes.
	 * @param mixed                $register Unused.
	 * @param mixed                $schema   Unused.
	 *
	 * @return array<string, mixed> The case as stored.
	 */
	public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
		$this->cases[$objectId] = array_merge($this->cases[$objectId] ?? [], $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * Unit tests for one submission opening several cases, tracked together.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeFanOut
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Routing\RoutingNote
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class IntakeFanOutTest extends TestCase {

	/**
	 * Two destinations in two departments.
	 *
	 * @var array<string, mixed>
	 */
	private const TWO_DESTINATIONS = [
		'title' => 'Melding openbare ruimte',
		'intakeDestinations' => [
			['title' => 'Handhaving', 'caseType' => 'ct-handhaving', 'department' => 'handhaving'],
			['title' => 'Onderhoud', 'caseType' => 'ct-onderhoud', 'department' => 'wijkbeheer'],
		],
	];

	/**
	 * The in-memory case store.
	 *
	 * @var FanOutCaseStore|LifecycleRegister
	 */
	private FanOutCaseStore|LifecycleRegister $store;

	/**
	 * What the intake case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * The relations that were drawn, as "a|b" pairs.
	 *
	 * @var array<int, string>
	 */
	private array $relations = [];

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FanOutCaseStore();
		$this->caseType = self::TWO_DESTINATIONS;
		$this->relations = [];
	}//end setUp()

	/**
	 * The fan-out, wired against the in-memory store.
	 *
	 * @return IntakeFanOut The fan-out under test.
	 */
	private function fanOut(): IntakeFanOut {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$relations = $this->createMock(originalClassName: CaseRelationService::class);
		$relations->method('addRelation')->willReturnCallback(
			function (string $caseId, string $targetId, string $natureRelationship): array {
				$this->relations[] = $caseId . '|' . $targetId . '|' . $natureRelationship;

				return ['ok' => true];
			}
		);

		return new IntakeFanOut(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			relations: $relations,
			logger: new NullLogger(),
		);
	}//end fanOut()

	/**
	 * One melding opens a handhaving case and an onderhoud case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testOneSubmissionOpensACaseInEachDeclaredDepartment(): void {
		$result = $this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal in het park'],
			submissionId: 'sub-1'
		);

		$this->assertCount(2, $result['created']);
		$this->assertSame([], $result['failed']);
		$this->assertSame(
			['handhaving', 'wijkbeheer'],
			array_column($result['created'], 'department')
		);
		$this->assertSame('ct-handhaving', $this->store->cases['case-1']['caseType']);
		$this->assertSame('ct-onderhoud', $this->store->cases['case-2']['caseType']);
	}//end testOneSubmissionOpensACaseInEachDeclaredDepartment()

	/**
	 * Each created case names the submission it came from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testEachCaseNamesTheSubmission(): void {
		$this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		);

		$this->assertSame('sub-1', $this->store->cases['case-1']['intakeSubmission']);
		$this->assertSame('sub-1', $this->store->cases['case-2']['intakeSubmission']);
	}//end testEachCaseNamesTheSubmission()

	/**
	 * A submission a company filed for one branch opens every case on that
	 * branch, and one without a branch opens cases without one
	 * (portal-case-list-declarations D3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-case-list-declarations/specs/portal-contribution/spec.md
	 */
	public function testEveryCaseCarriesTheBranchTheSubmissionWasFiledUnder(): void {
		$this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal', 'portalBranch' => '000012345678'],
			submissionId: 'sub-1'
		);
		$this->assertSame('000012345678', $this->store->cases['case-1']['portalBranch']);
		$this->assertSame('000012345678', $this->store->cases['case-2']['portalBranch']);

		$this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-2'
		);
		$this->assertArrayNotHasKey('portalBranch', $this->store->cases['case-3']);
	}//end testEveryCaseCarriesTheBranchTheSubmissionWasFiledUnder()

	/**
	 * The cases know about each other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testTheCasesKnowAboutEachOther(): void {
		$this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		);

		$this->assertSame(['case-1|case-2|' . IntakeFanOut::RELATION], $this->relations);
	}//end testTheCasesKnowAboutEachOther()

	/**
	 * The answer says the relation carries no inverse name yet.
	 *
	 * The cases are tied with `samenhang`, which the case schema declares
	 * symmetric, so both ends read the same word because that is what the
	 * relation IS and not because nothing better existed. The answer no longer
	 * carries `relationHasNoInverse`, because there is nothing left to warn of.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testTheSiblingsAreTiedWithTheSymmetricRelation(): void {
		$result = $this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: [],
			submissionId: 'sub-1'
		);

		$this->assertSame(CaseRelationService::RELATION_SAMENHANG, IntakeFanOut::RELATION);
		$this->assertArrayNotHasKey('relationHasNoInverse', $result);
	}//end testTheSiblingsAreTiedWithTheSymmetricRelation()

	/**
	 * A department carries its own case and nothing of the sibling's.
	 *
	 * dossiq computes no effective permission, per ADR-022: what it can do is
	 * put each case in one department and no other, which is what the grant is
	 * evaluated against.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testEachCaseCarriesOnlyItsOwnDepartment(): void {
		$this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		);

		$this->assertSame('handhaving', $this->store->cases['case-1']['assignedGroup']);
		$this->assertSame('wijkbeheer', $this->store->cases['case-2']['assignedGroup']);
		$this->assertNotSame(
			$this->store->cases['case-1']['assignedGroup'],
			$this->store->cases['case-2']['assignedGroup']
		);
	}//end testEachCaseCarriesOnlyItsOwnDepartment()

	/**
	 * A destination that cannot be created stops nothing silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testAFailedDestinationIsReportedAndTheOthersAreCreated(): void {
		$this->store->retiredCaseType = 'ct-handhaving';

		$result = $this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		);

		$this->assertCount(1, $result['created']);
		$this->assertCount(1, $result['failed']);
		$this->assertSame('Handhaving', $result['failed'][0]['destination']);
		$this->assertSame('The case type has been retired.', $result['failed'][0]['reason']);
		$this->assertSame('ct-onderhoud', $this->store->cases['case-1']['caseType']);
		$this->assertSame(
			[
				'kind' => 'intake-split',
				'level' => 'info',
				'submission' => 'sub-1',
				'siblings' => [],
				'failed' => [['destination' => 'Handhaving', 'reason' => 'The case type has been retired.']],
			],
			$this->store->cases['case-1']['routingNote'],
			'The case that was opened says which destination it lost.',
		);
	}//end testAFailedDestinationIsReportedAndTheOthersAreCreated()

	/**
	 * Every case of a split names its siblings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-routing-note/specs/kcc-routing/spec.md#requirement-a-case-keeps-a-note-of-its-last-routing-event
	 */
	public function testEveryCaseOfASplitNamesItsSiblings(): void {
		$this->fanOut()->submit(formCaseTypeId: 'ct-melding', submission: ['title' => 'Lantaarnpaal'], submissionId: 'sub-1');

		$this->assertSame(['Onderhoud'], $this->store->cases['case-1']['routingNote']['siblings']);
		$this->assertSame(['Handhaving'], $this->store->cases['case-2']['routingNote']['siblings']);
	}//end testEveryCaseOfASplitNamesItsSiblings()

	/**
	 * A disabled destination opens no case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testADisabledDestinationOpensNoCase(): void {
		$this->caseType = [
			'intakeDestinations' => [
				['title' => 'Handhaving', 'caseType' => 'ct-handhaving', 'department' => 'handhaving'],
				[
					'title' => 'Onderhoud',
					'caseType' => 'ct-onderhoud',
					'department' => 'wijkbeheer',
					'enabled' => false,
				],
			],
		];

		$result = $this->fanOut()->submit(formCaseTypeId: 'ct-melding', submission: [], submissionId: 's');

		$this->assertCount(1, $result['created']);
		$this->assertSame('handhaving', $result['created'][0]['department']);
	}//end testADisabledDestinationOpensNoCase()

	/**
	 * A form that declares nothing is refused rather than opening nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testAFormWithNoDestinationsIsRefused(): void {
		$this->caseType = ['title' => 'Melding'];

		try {
			$this->fanOut()->submit(formCaseTypeId: 'ct-melding', submission: [], submissionId: 's');
			$this->fail('The submission should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(IntakeFanOut::RULE_NO_DESTINATIONS, $e->getRule());
		}

		$this->assertSame([], $this->store->cases);
	}//end testAFormWithNoDestinationsIsRefused()

	/**
	 * Three destinations relate every pair, not only the neighbours.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-one-submission-opens-several-cases-tracked-together-req-triage-06
	 */
	public function testThreeDestinationsRelateEveryPair(): void {
		$this->caseType = [
			'intakeDestinations' => [
				['title' => 'A', 'caseType' => 'ct-a', 'department' => 'a'],
				['title' => 'B', 'caseType' => 'ct-b', 'department' => 'b'],
				['title' => 'C', 'caseType' => 'ct-c', 'department' => 'c'],
			],
		];

		$this->fanOut()->submit(formCaseTypeId: 'ct-melding', submission: [], submissionId: 's');

		$this->assertCount(3, $this->relations);
		$this->assertContains('case-1|case-3|' . IntakeFanOut::RELATION, $this->relations);
	}//end testThreeDestinationsRelateEveryPair()

	/**
	 * Each destination's case opens in its own case type's initial status,
	 * whatever status the submission names. The register refuses a create in
	 * any other status (openregister#5400).
	 *
	 * @return void
	 */
	public function testEachCaseOpensInItsOwnTypesInitialStatus(): void {
		$this->store = new LifecycleRegister();
		$this->store->seed(schema: 'caseType', uuid: 'ct-handhaving', row: ['initialStatus' => 'st-handhaving-intake']);
		$this->store->seed(schema: 'caseType', uuid: 'ct-onderhoud', row: ['initialStatus' => 'st-onderhoud-intake']);

		$result = $this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['title' => 'Kapotte lantaarnpaal', 'status' => 'st-handhaving-intake'],
			submissionId: 'sub-1'
		);

		$this->assertSame([], $result['failed']);
		$this->assertCount(2, $result['created']);
		$this->assertSame(
			['st-handhaving-intake', 'st-onderhoud-intake'],
			array_column($this->store->all('case'), 'status')
		);
	}//end testEachCaseOpensInItsOwnTypesInitialStatus()

	/**
	 * An id the submission sends opens new cases and leaves the case it names as it was.
	 *
	 * @return void
	 */
	public function testAForgedIdInTheSubmissionDoesNotTouchTheNamedCase(): void {
		$this->store = new LifecycleRegister();
		$this->store->seed(schema: 'case', uuid: 'victim-1', row: ['title' => 'Andermans zaak', 'caseType' => 'ct-other']);

		$result = $this->fanOut()->submit(
			formCaseTypeId: 'ct-melding',
			submission: ['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1'], 'title' => 'Kapotte lantaarnpaal'],
			submissionId: 'sub-1'
		);

		$this->assertSame([], $result['failed']);
		$this->assertCount(3, $this->store->all('case'));
		$this->assertSame(['title' => 'Andermans zaak', 'caseType' => 'ct-other', 'id' => 'victim-1'], $this->store->row('case', 'victim-1'));
	}//end testAForgedIdInTheSubmissionDoesNotTouchTheNamedCase()
}//end class
