<?php

/**
 * Unit tests for CaseRebindService.
 *
 * 🔴 WHAT THESE GUARD IS A CASE GOVERNED BY A BLUEPRINT NOBODY CHOSE. The
 * rebind writes four fields at once and re-arms the statutory clock, so every
 * way it can half-happen is a case that reads as one thing and behaves as
 * another. The four that matter, and the four tested here, are: a required
 * property the target asks for and the case does not carry; the engine
 * refusing to move the run; a handler who is not a coordinator; and the happy
 * path, where the number stays and the terms keep their start date.
 *
 * The engine test is the one that is easy to get wrong. A refusal must leave
 * NOTHING written, which means the assertion is not "the call threw" but "the
 * store recorded no write at all": a service that threw after writing looks
 * identical from the outside of the exception.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseRebindService;
use OCA\Dossiq\Service\CaseType\EngineRunMigration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaseRebindService.
 *
 * @covers \OCA\Dossiq\Service\CaseRebindService
 *
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseTypeSlugResolver
 * @uses \OCA\Dossiq\Service\CaseType\EngineRunMigration
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\TermijnService
 */
class CaseRebindServiceTest extends TestCase {

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
	 * Two unrelated published case types, and a case running on the first.
	 *
	 * `Kapvergunning` and `Omgevingsvergunning` share the status name
	 * "In behandeling" on purpose: that is exactly the coincidence a rebind
	 * must not treat as a mapping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'ct-kap' => ['__schema' => 'caseType', 'data' => [
				'id' => 'ct-kap',
				'title' => 'Kapvergunning',
				'identifier' => 'CT-KAP',
				'isDraft' => false,
				'version' => 1,
			]],
			'ct-omg' => ['__schema' => 'caseType', 'data' => [
				'id' => 'ct-omg',
				'title' => 'Omgevingsvergunning',
				'identifier' => 'CT-OMG',
				'isDraft' => false,
				'version' => 1,
			]],
			'ct-draft' => ['__schema' => 'caseType', 'data' => [
				'id' => 'ct-draft',
				'title' => 'Sloopmelding',
				'identifier' => 'CT-SLO',
				'isDraft' => true,
				'version' => 1,
			]],
			'kap-behandeling' => ['__schema' => 'statusType', 'data' => [
				'id' => 'kap-behandeling', 'caseType' => 'ct-kap', 'name' => 'In behandeling',
			]],
			'omg-behandeling' => ['__schema' => 'statusType', 'data' => [
				'id' => 'omg-behandeling', 'caseType' => 'ct-omg', 'name' => 'In behandeling',
			]],
			'omg-toetsing' => ['__schema' => 'statusType', 'data' => [
				'id' => 'omg-toetsing', 'caseType' => 'ct-omg', 'name' => 'Toetsing',
			]],
			'pd-boom' => ['__schema' => 'propertyDefinition', 'data' => [
				'id' => 'pd-boom', 'caseType' => 'ct-kap', 'name' => 'boomsoort',
			]],
			'pd-bouwjaar' => ['__schema' => 'propertyDefinition', 'data' => [
				'id' => 'pd-bouwjaar',
				'caseType' => 'ct-omg',
				'name' => 'bouwjaar',
				'requiredAtStatus' => 'omg-toetsing',
			]],
			'pd-opp' => ['__schema' => 'propertyDefinition', 'data' => [
				'id' => 'pd-opp',
				'caseType' => 'ct-omg',
				'name' => 'oppervlakte',
				'requiredAtStatus' => 'omg-toetsing',
			]],
			'wf-omg' => ['__schema' => 'workflowTemplate', 'data' => [
				'id' => 'wf-omg', 'caseType' => 'ct-omg', 'isActive' => true, 'version' => 3,
			]],
			'case-1' => ['__schema' => 'case', 'data' => [
				'id' => 'case-1',
				'caseNumber' => 'ZAAK-2026-0001',
				'caseType' => 'ct-kap',
				'status' => 'kap-behandeling',
				'properties' => ['boomsoort' => 'eik'],
			]],
		];
		$this->writes = [];
	}//end setUp()

	/**
	 * The service under test, over the in-memory store.
	 *
	 * @param boolean     $isCoordinator Whether the actor is in the coordinator group.
	 * @param string|null $engineRefusal The engine's refusal sentence, or null when it migrates.
	 *
	 * @return CaseRebindService The service.
	 */
	private function service(bool $isCoordinator = true, ?string $engineRefusal = null): CaseRebindService {
		$settings = $this->settings();
		$store = new CaseTypeStore($settings);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool
				=> ($isCoordinator === true && $group === CaseRebindService::COORDINATOR_GROUP)
		);

		return new CaseRebindService(
			settingsService: $settings,
			store: $store,
			resolver: new CaseTypeResolver(store: $store),
			engine: $this->engine(refusal: $engineRefusal),
			terms: $this->terms(),
			slugs: $this->createMock(CaseTypeSlugResolver::class),
			groupManager: $groups,
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * The settings seam over the fake object service.
	 *
	 * @return SettingsService The mock.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService());
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'status_type_schema' => 'statusType',
					'property_definition_schema' => 'propertyDefinition',
					'result_type_schema' => 'resultType',
					'workflow_template_schema' => 'workflowTemplate',
					default => $default,
				};
			}
		);

		return $settings;
	}//end settings()

	/**
	 * The engine seam, migrating or refusing.
	 *
	 * 🔴 `onlyMethods`, not `addMethods`. A double that can invent a method the
	 * real class lacks passes on a call production would fatal on, which is how
	 * a green suite once covered an unlink that 500s.
	 *
	 * @param string|null $refusal The refusal sentence, or null.
	 *
	 * @return EngineRunMigration The double.
	 */
	private function engine(?string $refusal): EngineRunMigration {
		$engine = $this->getMockBuilder(EngineRunMigration::class)
			->disableOriginalConstructor()
			->onlyMethods(['migrate'])
			->getMock();

		if ($refusal === null) {
			$engine->method('migrate')->willReturn(
				['asked' => false, 'migrated' => false, 'reason' => EngineRunMigration::RUN_NOT_MOVED]
			);

			return $engine;
		}

		$engine->method('migrate')->willThrowException(
			new RefusedException(
				rule: 'engine-refused-the-run-migration',
				sentence: $refusal,
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);

		return $engine;
	}//end engine()

	/**
	 * The term service, recording what it was asked to re-arm.
	 *
	 * @return TermijnService The double.
	 */
	private function terms(): TermijnService {
		$terms = $this->getMockBuilder(TermijnService::class)
			->disableOriginalConstructor()
			->onlyMethods(['rearmForDefinition'])
			->getMock();

		$terms->method('rearmForDefinition')->willReturn(['rearmed' => 1, 'kept' => 0, 'note' => '']);

		return $terms;
	}//end terms()

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
	 * The targets are every published case type except the case's own.
	 *
	 * @return void
	 */
	public function testTheTargetsAreThePublishedTypesOtherThanItsOwn(): void {
		$options = $this->service()->options(caseId: 'case-1');

		self::assertSame('Kapvergunning', $options['current']['title']);
		self::assertSame('In behandeling', $options['current']['status']);
		self::assertSame(['ct-omg'], array_column($options['targets'], 'id'));
	}//end testTheTargetsAreThePublishedTypesOtherThanItsOwn()

	/**
	 * 🔴 The landing status is ASKED for, never matched by name.
	 *
	 * Both case types have a status called "In behandeling", and they are
	 * unrelated rows. The preview offers the target's statuses and nothing is
	 * preselected, because a rebind that landed a case in a status it never
	 * chose is a write nobody can read back.
	 *
	 * @return void
	 */
	public function testThePreviewOffersTheTargetsStatusesAndPicksNone(): void {
		$preview = $this->service()->preview(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-omg',
			targetStatusId: ''
		);

		self::assertSame(
			['omg-behandeling', 'omg-toetsing'],
			array_column($preview['statuses'], 'id')
		);
		self::assertFalse($preview['canRebind']);
		self::assertSame([], $preview['missingProperties']);
	}//end testThePreviewOffersTheTargetsStatusesAndPicksNone()

	/**
	 * The preview names the properties the target requires in that status.
	 *
	 * @return void
	 */
	public function testThePreviewNamesTheMissingRequiredProperties(): void {
		$preview = $this->service()->preview(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-omg',
			targetStatusId: 'omg-toetsing'
		);

		self::assertSame(['bouwjaar', 'oppervlakte'], $preview['missingProperties']);
		self::assertFalse($preview['canRebind']);
	}//end testThePreviewNamesTheMissingRequiredProperties()

	/**
	 * 🔴 A missing required property refuses the rebind and NAMES it.
	 *
	 * And nothing is written: a case left on its old type with a refusal is
	 * recoverable, a case half-rebound is not.
	 *
	 * @return void
	 */
	public function testAMissingRequiredPropertyRefusesTheRebind(): void {
		try {
			$this->service()->rebind(
				caseId: 'case-1',
				targetCaseTypeId: 'ct-omg',
				targetStatusId: 'omg-toetsing',
				reason: 'Verkeerd ingeboekt',
				properties: ['bouwjaar' => '1974'],
				actorUid: 'coordinator',
			);
			self::fail('A rebind missing a required property must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('rebind-missing-required-properties', $e->getRule());
			self::assertStringContainsString('oppervlakte', $e->getSentence());
			self::assertStringNotContainsString('bouwjaar', $e->getSentence());
		}

		self::assertSame([], $this->writes);
		self::assertSame('ct-kap', $this->store['case-1']['data']['caseType']);
	}//end testAMissingRequiredPropertyRefusesTheRebind()

	/**
	 * 🔴 The engine's refusal stops the rebind, and nothing is written.
	 *
	 * The assertion that matters is the empty write log, not the exception: a
	 * service that wrote the case and then threw is indistinguishable from this
	 * one if you only catch.
	 *
	 * @return void
	 */
	public function testTheEnginesRefusalStopsTheRebindWithNothingWritten(): void {
		$service = $this->service(engineRefusal: 'This run is suspended awaiting an advice.');

		try {
			$service->rebind(
				caseId: 'case-1',
				targetCaseTypeId: 'ct-omg',
				targetStatusId: 'omg-behandeling',
				reason: 'Verkeerd ingeboekt',
				properties: [],
				actorUid: 'coordinator',
			);
			self::fail('An engine refusal must stop the rebind.');
		} catch (RefusedException $e) {
			self::assertSame('engine-refused-the-run-migration', $e->getRule());
			self::assertSame('This run is suspended awaiting an advice.', $e->getSentence());
		}

		self::assertSame([], $this->writes);
		self::assertSame('ct-kap', $this->store['case-1']['data']['caseType']);
	}//end testTheEnginesRefusalStopsTheRebindWithNothingWritten()

	/**
	 * 🔴 A handler outside the coordinators is refused, with the rule named.
	 *
	 * Probed with the least privileged principal that should be refused, and
	 * before anything else is checked: the refusal must not depend on the
	 * target being valid, or a handler could learn the catalogue by guessing.
	 *
	 * @return void
	 */
	public function testAHandlerOutsideTheCoordinatorsIsRefused(): void {
		$service = $this->service(isCoordinator: false);

		self::assertFalse($service->mayRebind(uid: 'handler'));

		try {
			$service->rebind(
				caseId: 'case-1',
				targetCaseTypeId: 'ct-omg',
				targetStatusId: 'omg-behandeling',
				reason: 'Verkeerd ingeboekt',
				properties: [],
				actorUid: 'handler',
			);
			self::fail('A handler outside the coordinators must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('rebind-is-for-coordinators', $e->getRule());
			self::assertSame(RefusedException::STATUS_FORBIDDEN, $e->getStatus());
		}

		self::assertSame([], $this->writes);
	}//end testAHandlerOutsideTheCoordinatorsIsRefused()

	/**
	 * Nobody at all is refused too, so an unauthenticated actor never rebinds.
	 *
	 * @return void
	 */
	public function testAnEmptyActorMayNotRebind(): void {
		self::assertFalse($this->service()->mayRebind(uid: ''));
	}//end testAnEmptyActorMayNotRebind()

	/**
	 * A draft case type is never something a running case is moved onto.
	 *
	 * @return void
	 */
	public function testADraftTargetIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('rebind_target_is_a_draft');

		$this->service()->rebind(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-draft',
			targetStatusId: 'omg-behandeling',
			reason: 'Verkeerd ingeboekt',
			properties: [],
			actorUid: 'coordinator',
		);
	}//end testADraftTargetIsRefused()

	/**
	 * 🔴 A status of ANOTHER case type is refused, not written.
	 *
	 * The mapping is explicit, which makes it untrusted: a status id posted
	 * straight at the endpoint could name a row of any type, and a case sitting
	 * in a foreign status is invisible to every lens that reads its blueprint.
	 *
	 * @return void
	 */
	public function testAStatusOfAnotherCaseTypeIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('rebind_status_is_not_the_targets');

		$this->service()->rebind(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-omg',
			targetStatusId: 'kap-behandeling',
			reason: 'Verkeerd ingeboekt',
			properties: [],
			actorUid: 'coordinator',
		);
	}//end testAStatusOfAnotherCaseTypeIsRefused()

	/**
	 * A rebind with no reason is refused: the journal entry would say nothing.
	 *
	 * @return void
	 */
	public function testARebindWithoutAReasonIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('rebind_needs_a_reason');

		$this->service()->rebind(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-omg',
			targetStatusId: 'omg-behandeling',
			reason: '   ',
			properties: [],
			actorUid: 'coordinator',
		);
	}//end testARebindWithoutAReasonIsRefused()

	/**
	 * 🔴 The happy path: the blueprint moves and the case number does not.
	 *
	 * The answers given in the dialog count towards what the target requires,
	 * the four fields are written together, the journal carries both bindings
	 * and the reason, and `caseNumber` is untouched. A rebind that renumbered
	 * the case would be the refile it exists to replace.
	 *
	 * @return void
	 */
	public function testTheHappyPathMovesTheBlueprintAndKeepsTheNumber(): void {
		$result = $this->service()->rebind(
			caseId: 'case-1',
			targetCaseTypeId: 'ct-omg',
			targetStatusId: 'omg-toetsing',
			reason: 'Verkeerd ingeboekt bij intake',
			properties: ['bouwjaar' => '1974', 'oppervlakte' => 120],
			actorUid: 'coordinator',
		);

		self::assertTrue($result['rebound']);
		self::assertSame('ct-kap', $result['from']);
		self::assertSame('ct-omg', $result['to']);
		self::assertSame(1, $result['terms']['rearmed']);
		self::assertFalse($result['run']['moved']);
		self::assertSame(EngineRunMigration::RUN_NOT_MOVED, $result['run']['reason']);

		$written = $this->store['case-1']['data'];
		self::assertSame('ct-omg', $written['caseType']);
		self::assertSame('omg-toetsing', $written['status']);
		self::assertSame('wf-omg', $written['workflowTemplate']);
		self::assertSame(3, $written['workflowVersion']);
		self::assertSame('ZAAK-2026-0001', $written['caseNumber']);
		self::assertSame('eik', $written['properties']['boomsoort']);
		self::assertSame('1974', $written['properties']['bouwjaar']);

		$journal = json_decode((string)$written['activity'], true);
		self::assertSame('case-type-rebind', $journal[0]['type']);
		self::assertSame('Kapvergunning', $journal[0]['fromCaseTypeTitle']);
		self::assertSame('Omgevingsvergunning', $journal[0]['toCaseTypeTitle']);
		self::assertSame('Verkeerd ingeboekt bij intake', $journal[0]['reason']);
		self::assertSame('coordinator', $journal[0]['actor']);
	}//end testTheHappyPathMovesTheBlueprintAndKeepsTheNumber()
}//end class
