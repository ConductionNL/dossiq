<?php

/**
 * Unit tests for CaseVersionMove: what a move costs, and when it is refused.
 *
 * 🔴 WHAT THESE GUARD IS A CASE LANDING SOMEWHERE NOBODY CHOSE. A statusType
 * carries no identity that survives a version: the copy writes each row as a
 * new object with a new uuid, so the only thing two versions of one status
 * share is what it is called. The mapping is therefore by name, and a version
 * where the author renamed or deleted the status a case sits in is precisely
 * the case that cannot be mapped. Guessing a landing status there, the target's
 * first one say, would be a write nobody can read back afterwards, so the test
 * that matters most is the refusal, and that it NAMES the status.
 *
 * The second thing they guard is what the preview promises. A handler about to
 * move a case is shown which fields the other version drops, and the answer
 * that matters is which of those this case has actually FILLED IN. A preview
 * that listed every dropped field equally would bury the two answers about to
 * be lost in a list of twenty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionChain;
use OCA\Dossiq\Service\CaseType\CaseVersionDiff;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaseVersionMove.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseVersionMove
 *
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeVersionChain
 * @uses \OCA\Dossiq\Service\CaseType\CaseVersionDiff
 * @uses \OCA\Dossiq\Service\CaseType\DerivedCaseTypePayload
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseVersionMoveTest extends TestCase {

	/**
	 * The store every test reads and writes, keyed by id.
	 *
	 * @var array<string, array{__schema: string, data: array<string, mixed>}>
	 */
	private array $store = [];

	/**
	 * The writes the object service recorded.
	 *
	 * @var array<int, array{schema: string, id: string, object: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Two published versions of one case type, and a case running on version 1.
	 *
	 * Version 2 renames nothing by default, so the happy path maps. Each test
	 * that wants a hole in the mapping makes it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'ct-1' => ['__schema' => 'caseType', 'data' => [
				'id' => 'ct-1',
				'title' => 'Bezwaar',
				'identifier' => 'CT-1000',
				'isDraft' => false,
				'version' => 1,
				'supersededBy' => 'ct-2',
			]],
			'ct-2' => ['__schema' => 'caseType', 'data' => [
				'id' => 'ct-2',
				'title' => 'Bezwaar',
				'identifier' => 'CT-1000',
				'isDraft' => false,
				'version' => 2,
				'previousVersion' => 'ct-1',
			]],
			's1-a' => ['__schema' => 'statusType', 'data' => ['id' => 's1-a', 'caseType' => 'ct-1', 'name' => 'Ontvangen']],
			's1-b' => ['__schema' => 'statusType', 'data' => ['id' => 's1-b', 'caseType' => 'ct-1', 'name' => 'Ingetrokken']],
			's2-a' => ['__schema' => 'statusType', 'data' => ['id' => 's2-a', 'caseType' => 'ct-2', 'name' => 'Ontvangen']],
			's2-c' => ['__schema' => 'statusType', 'data' => ['id' => 's2-c', 'caseType' => 'ct-2', 'name' => 'In behandeling']],
			'pd-1' => ['__schema' => 'propertyDefinition', 'data' => ['id' => 'pd-1', 'caseType' => 'ct-1', 'name' => 'kenteken']],
			'pd-2' => ['__schema' => 'propertyDefinition', 'data' => ['id' => 'pd-2', 'caseType' => 'ct-1', 'name' => 'oppervlakte']],
			'pd-3' => ['__schema' => 'propertyDefinition', 'data' => ['id' => 'pd-3', 'caseType' => 'ct-2', 'name' => 'kenteken']],
			'pd-4' => ['__schema' => 'propertyDefinition', 'data' => ['id' => 'pd-4', 'caseType' => 'ct-2', 'name' => 'bouwjaar']],
			'wf-2' => ['__schema' => 'workflowTemplate', 'data' => ['id' => 'wf-2', 'caseType' => 'ct-2', 'isActive' => true, 'version' => 4]],
			'case-1' => ['__schema' => 'case', 'data' => [
				'id' => 'case-1',
				'caseType' => 'ct-1',
				'status' => 's1-a',
				'properties' => ['kenteken' => 'AB-12-CD', 'oppervlakte' => 42],
			]],
		];
		$this->writes = [];
	}//end setUp()

	/**
	 * The service under test, over the in-memory store.
	 *
	 * @return CaseVersionMove The service.
	 */
	private function service(): CaseVersionMove {
		$objectService = $this->objectService();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'status_type_schema' => 'statusType',
					'property_definition_schema' => 'propertyDefinition',
					'workflow_template_schema' => 'workflowTemplate',
					default => $default,
				};
			}
		);

		$store = new CaseTypeStore($settings);

		$chain = new CaseTypeVersionChain(store: $store, payloads: new DerivedCaseTypePayload());

		return new CaseVersionMove(
			settingsService: $settings,
			store: $store,
			chain: $chain,
			diff: new CaseVersionDiff(
				store: $store,
				resolver: new CaseTypeResolver(store: $store),
				chain: $chain,
			),
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * An in-memory OpenRegister object service over the seeded store.
	 *
	 * @return object The fake.
	 */
	private function objectService(): object {
		return new class($this->store, $this->writes) {
			/**
			 * @param array<string, array{__schema: string, data: array<string, mixed>}> $store  The store.
			 * @param array<int, array<string, mixed>>                                   $writes The recorded writes.
			 */
			public function __construct(
				private array &$store,
				private array &$writes,
			) {
			}//end __construct()

			/**
			 * Read one object.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$entry = ($this->store[$id] ?? null);
				if ($entry === null || ($schema !== null && $entry['__schema'] !== $schema)) {
					return null;
				}

				return $entry['data'];
			}//end find()

			/**
			 * Search one schema on its bare filter keys.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				$filters = $query;
				unset($filters['@self'], $filters['_limit']);

				$found = [];
				foreach ($this->store as $entry) {
					if ($entry['__schema'] !== $schema) {
						continue;
					}

					$matches = true;
					foreach ($filters as $key => $value) {
						$own = (string)($entry['data'][$key] ?? '');
						if ($value === 'IS NULL') {
							$matches = ($matches === true && $own === '');
							continue;
						}

						$matches = ($matches === true && $own === (string)$value);
					}

					if ($matches === true) {
						$found[] = $entry['data'];
					}
				}

				return $found;
			}//end searchObjects()

			/**
			 * Record a write and apply it to the store.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $id       The object id.
			 * @param array<string, mixed> $object   The object.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function updateObject(string $register, string $schema, string $id, array $object): array {
				$this->writes[] = ['schema' => $schema, 'id' => $id, 'object' => $object];
				$this->store[$id] = ['__schema' => $schema, 'data' => $object];

				return $object;
			}//end updateObject()
		};
	}//end objectService()

	/**
	 * The chain offers the other published version, and not the case's own.
	 *
	 * @return void
	 */
	public function testTheOptionsOfferTheOtherVersion(): void {
		$options = $this->service()->options(caseId: 'case-1');

		self::assertSame(1, $options['current']['version']);
		self::assertCount(1, $options['targets']);
		self::assertSame('ct-2', $options['targets'][0]['id']);
	}//end testTheOptionsOfferTheOtherVersion()

	/**
	 * A draft version is never offered as somewhere to move a running case to.
	 *
	 * @return void
	 */
	public function testADraftVersionIsNotOffered(): void {
		$this->store['ct-3'] = ['__schema' => 'caseType', 'data' => [
			'id' => 'ct-3',
			'title' => 'Bezwaar',
			'identifier' => 'CT-1000',
			'isDraft' => true,
			'version' => 3,
			'previousVersion' => 'ct-2',
		]];

		$targets = $this->service()->options(caseId: 'case-1')['targets'];

		self::assertSame(['ct-2'], array_column($targets, 'id'));
	}//end testADraftVersionIsNotOffered()

	/**
	 * The preview says where the case lands and what the version changes.
	 *
	 * @return void
	 */
	public function testThePreviewNamesTheLandingStatusAndTheDifferences(): void {
		$preview = $this->service()->preview(caseId: 'case-1', targetCaseTypeId: 'ct-2');

		self::assertTrue($preview['canMove']);
		self::assertSame('Ontvangen', $preview['status']['to']);
		self::assertSame('s2-a', $preview['status']['targetStatusId']);
		self::assertSame(['In behandeling'], $preview['statuses']['added']);
		self::assertSame(['Ingetrokken'], $preview['statuses']['removed']);
		self::assertSame(['bouwjaar'], $preview['fields']['added']);
		self::assertSame(['oppervlakte'], $preview['fields']['removed']);
	}//end testThePreviewNamesTheLandingStatusAndTheDifferences()

	/**
	 * 🔴 The preview separates the dropped fields this case has ANSWERED.
	 *
	 * A list of every dropped field buries the answers about to be lost among
	 * fields nobody filled in. This case has a value for `oppervlakte` and the
	 * other version does not carry it, which is the sentence the dialog shows.
	 *
	 * @return void
	 */
	public function testThePreviewSeparatesTheAnswersAboutToBeLost(): void {
		$preview = $this->service()->preview(caseId: 'case-1', targetCaseTypeId: 'ct-2');

		self::assertSame(['oppervlakte'], $preview['fields']['answered']);

		// An empty answer is not an answer, so it is not reported as a loss.
		$this->store['case-1']['data']['properties']['oppervlakte'] = '';
		self::assertSame([], $this->service()->preview(caseId: 'case-1', targetCaseTypeId: 'ct-2')['fields']['answered']);
	}//end testThePreviewSeparatesTheAnswersAboutToBeLost()

	/**
	 * 🔴 A status the other version does not carry refuses the move BY NAME.
	 *
	 * The name is the only thing the person reading the refusal can act on:
	 * they either add that status to the version or move the case on first.
	 *
	 * @return void
	 */
	public function testAnUnmappableStatusRefusesTheMoveAndNamesIt(): void {
		$this->store['case-1']['data']['status'] = 's1-b';

		$preview = $this->service()->preview(caseId: 'case-1', targetCaseTypeId: 'ct-2');

		self::assertFalse($preview['canMove']);
		self::assertStringContainsString('Ingetrokken', $preview['refusals'][0]);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('status_does_not_exist_in_target_version');
		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: 'Correctie', actorUid: 'ruben');
	}//end testAnUnmappableStatusRefusesTheMoveAndNamesIt()

	/**
	 * The refusal a caller can show names the status too, not only the rule.
	 *
	 * @return void
	 */
	public function testTheRefusalSentenceNamesTheStatus(): void {
		$this->store['case-1']['data']['status'] = 's1-b';

		try {
			$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: 'Correctie', actorUid: 'ruben');
			self::fail('the move was not refused');
		} catch (RefusedException $e) {
			self::assertStringContainsString('Ingetrokken', $e->getSentence());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
	}//end testTheRefusalSentenceNamesTheStatus()

	/**
	 * The move writes the case type, the status and the workflow pin.
	 *
	 * @return void
	 */
	public function testTheMoveRebindsTheCaseAndItsWorkflow(): void {
		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: 'Nieuwe regels', actorUid: 'ruben');

		$case = $this->store['case-1']['data'];
		self::assertSame('ct-2', $case['caseType']);
		self::assertSame('s2-a', $case['status']);
		self::assertSame('wf-2', $case['workflowTemplate']);
		self::assertSame(4, $case['workflowVersion']);
	}//end testTheMoveRebindsTheCaseAndItsWorkflow()

	/**
	 * The move is recorded on the case, with both versions and the reason.
	 *
	 * "The case type changed" with nothing beside it sends the next person
	 * digging through the store to work out what it used to be.
	 *
	 * @return void
	 */
	public function testTheMoveIsRecordedWithBothVersionsAndTheReason(): void {
		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: 'Nieuwe regels', actorUid: 'ruben');

		$activity = json_decode($this->store['case-1']['data']['activity'], true);
		$entry = end($activity);

		self::assertSame('case-type-version-move', $entry['type']);
		self::assertSame('ct-1', $entry['fromCaseType']);
		self::assertSame('ct-2', $entry['toCaseType']);
		self::assertSame(1, $entry['fromVersion']);
		self::assertSame(2, $entry['toVersion']);
		self::assertSame('Nieuwe regels', $entry['reason']);
		self::assertSame('ruben', $entry['actor']);
		self::assertSame(['oppervlakte'], $entry['fieldsRemoved']);
	}//end testTheMoveIsRecordedWithBothVersionsAndTheReason()

	/**
	 * A move with no reason is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAMoveWithoutAReasonIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('version_move_needs_a_reason');

		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-2', reason: '   ', actorUid: 'ruben');
	}//end testAMoveWithoutAReasonIsRefused()

	/**
	 * 🔴 A case type that is not another VERSION of this one is refused.
	 *
	 * Moving a case to a different case type changes the vocabulary rather than
	 * its edition. That is a rebind, it owes a mapping of its own, and
	 * `case-type-rebind` owns it.
	 *
	 * @return void
	 */
	public function testAnotherCaseTypeEntirelyIsRefused(): void {
		$this->store['other-1'] = ['__schema' => 'caseType', 'data' => [
			'id' => 'other-1',
			'title' => 'Omgevingsvergunning',
			'identifier' => 'CT-2000',
			'isDraft' => false,
			'version' => 1,
		]];

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('version_move_crosses_case_types');

		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'other-1', reason: 'Verkeerd type', actorUid: 'ruben');
	}//end testAnotherCaseTypeEntirelyIsRefused()

	/**
	 * Moving a case to the version it is already on is refused.
	 *
	 * @return void
	 */
	public function testMovingToTheVersionItIsAlreadyOnIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('version_move_to_itself');

		$this->service()->move(caseId: 'case-1', targetCaseTypeId: 'ct-1', reason: 'Nergens heen', actorUid: 'ruben');
	}//end testMovingToTheVersionItIsAlreadyOnIsRefused()

	/**
	 * 🔴 The answer says the engine run did NOT move with the case.
	 *
	 * The case moves onto the target version's own workflow template, which is
	 * what dossiq owns. The run the engine has in flight stays pinned to the
	 * flow definition version it was queued against, because moving one is
	 * openregister's `migrate-run-between-versions`, specified there and not
	 * yet shipped. A move that quietly left the run where it was and reported
	 * plain success is the silent half-write this sentence prevents.
	 *
	 * @return void
	 */
	public function testTheAnswerSaysTheEngineRunDidNotMove(): void {
		$result = $this->service()->move(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-2',
			reason: 'Nieuwe regels',
			actorUid: 'ruben'
		);

		self::assertTrue($result['moved']);
		self::assertFalse($result['run']['moved']);
		self::assertStringContainsString('migrate-run-between-versions', $result['run']['reason']);
	}//end testTheAnswerSaysTheEngineRunDidNotMove()

	/**
	 * A case whose case type names nothing has no chain to move along.
	 *
	 * @return void
	 */
	public function testACaseWithNoCaseTypeIsRefused(): void {
		$this->store['case-1']['data']['caseType'] = '';

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('case_has_no_case_type');

		$this->service()->options(caseId: 'case-1');
	}//end testACaseWithNoCaseTypeIsRefused()
}//end class
