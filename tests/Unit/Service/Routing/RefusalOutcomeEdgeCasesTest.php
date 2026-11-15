<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use DateTime;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Routing\RefusalOutcome;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;

/**
 * An object store whose searches and writes can be made to fail.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 */
class FailingRefusalStore {

	/**
	 * The stored cases, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * The organisatieRol rows a search answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $roles = [];

	/**
	 * The filters each search was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $searches = [];

	/**
	 * When set, every search throws.
	 *
	 * @var boolean
	 */
	public bool $searchFails = false;

	/**
	 * When set, every write throws.
	 *
	 * @var boolean
	 */
	public bool $writeFails = false;

	/**
	 * Search the organisatieRol rows.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		$this->searches[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
		if ($this->searchFails === true) {
			throw new RuntimeException('search index offline');
		}

		return $this->roles;
	}//end searchObjectsBySlug()

	/**
	 * Read one case.
	 *
	 * @param string $id       The id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
		$register = $options?->register ?? '';
		$schema = $options?->schema ?? '';
		return ($this->cases[$id] ?? null);
	}//end find()

	/**
	 * Patch one case.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The changes.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true): array {
		$objectId = $patch->objectId;
		$data = $patch->data;
		$register = $patch->target->register ?? '';
		$schema = $patch->target->schema ?? '';
		if ($this->writeFails === true) {
			throw new RuntimeException('disk full');
		}

		$this->cases[$objectId] = array_merge(($this->cases[$objectId] ?? []), $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * The refusal outcome when the team cannot be resolved, the write does not
 * land, or the case names its type as an object.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @covers \OCA\Dossiq\Service\Routing\RefusalOutcome
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Support\RefusesWhenIndeterminate
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/specs/kcc-routing/spec.md
 */
class RefusalOutcomeEdgeCasesTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var FailingRefusalStore
	 */
	private FailingRefusalStore $store;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The case type ids the resolver was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $resolvedTypes = [];

	/**
	 * The configuration the settings answer with.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'organisatie_rol_schema' => 'organisatieRol',
	];

	/**
	 * Set up a store with one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FailingRefusalStore();
		$this->store->cases['case-1'] = ['id' => 'case-1', 'caseType' => 'ct-1'];
		$this->store->roles = [['id' => 'rol-1', 'ncGroupId' => 'juza-intake']];
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The outcome under test.
	 *
	 * @param array<int, object|null>|null $objectServices The object service per call, or the store every time.
	 *
	 * @return RefusalOutcome
	 */
	private function outcome(?array $objectServices = null): RefusalOutcome {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		if ($objectServices === null) {
			$settings->method('getObjectService')->willReturn($this->store);
		} else {
			$settings->method('getObjectService')->willReturnOnConsecutiveCalls(...$objectServices);
		}

		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			function (string $caseTypeId): array {
				$this->resolvedTypes[] = $caseTypeId;

				return ['refusalDestination' => ['department' => ' Juridische Zaken ', 'role' => 'intake']];
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14 11:00:00'));

		return new RefusalOutcome(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			writer: new CaseFieldWriter(),
			time: $time,
			logger: $this->logger,
		);
	}//end outcome()

	/**
	 * A case naming its type as an object resolves the type by that object's id.
	 *
	 * @return void
	 */
	public function testACaseTypeGivenAsAnObjectIsResolvedByItsId(): void {
		$this->store->cases['case-1']['caseType'] = ['id' => ' ct-object ', 'title' => 'Melding'];

		$record = $this->outcome()->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');

		self::assertSame(expected: ['ct-object'], actual: $this->resolvedTypes);
		self::assertSame(expected: 'Juridische Zaken', actual: $record['department']);
		self::assertSame(
			expected: ['department' => 'Juridische Zaken', 'roleName' => 'intake', '_limit' => 1],
			actual: $this->store->searches[0]['filters']
		);
		self::assertSame(expected: 'organisatieRol', actual: $this->store->searches[0]['schema']);
		self::assertSame(expected: 'juza-intake', actual: $this->store->cases['case-1']['assignedGroup']);
	}//end testACaseTypeGivenAsAnObjectIsResolvedByItsId()

	/**
	 * A case type object that only carries its id under `@self` still resolves.
	 *
	 * @return void
	 */
	public function testACaseTypeObjectIdUnderSelfIsUsed(): void {
		$this->store->cases['case-1']['caseType'] = ['@self' => ['id' => 'ct-self']];

		$this->outcome()->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');

		self::assertSame(expected: ['ct-self'], actual: $this->resolvedTypes);
	}//end testACaseTypeObjectIdUnderSelfIsUsed()

	/**
	 * Without an organisation role schema the case is refused, unassigned, and no search runs.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRoleSchemaRefusesTheCaseUnassigned(): void {
		$this->config['organisatie_rol_schema'] = '';

		$record = $this->outcome()->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');

		self::assertTrue(condition: $record['refused']);
		self::assertSame(expected: [], actual: $this->store->searches);
		self::assertSame(expected: $record, actual: $this->store->cases['case-1']['intakeRefusal']);
		self::assertArrayNotHasKey(key: 'assignedGroup', array: $this->store->cases['case-1']);
	}//end testAnUnconfiguredRoleSchemaRefusesTheCaseUnassigned()

	/**
	 * A search that throws is logged with the destination and leaves the refused case unassigned.
	 *
	 * @return void
	 */
	public function testAFailingRoleSearchIsLoggedAndLeavesTheCaseUnassigned(): void {
		$this->store->searchFails = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq refusal: could not resolve the team for {department}/{role}',
			['department' => 'Juridische Zaken', 'role' => 'intake', 'reason' => 'search index offline'],
		);

		$record = $this->outcome()->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');

		self::assertTrue(condition: $record['refused']);
		self::assertSame(expected: 'Niet voor ons.', actual: $this->store->cases['case-1']['intakeRefusal']['reason']);
		self::assertArrayNotHasKey(key: 'assignedGroup', array: $this->store->cases['case-1']);
	}//end testAFailingRoleSearchIsLoggedAndLeavesTheCaseUnassigned()

	/**
	 * A write that does not land refuses as indeterminate, logs, and keeps the cause.
	 *
	 * @return void
	 */
	public function testAWriteThatDoesNotLandRefusesIndeterminate(): void {
		$this->store->writeFails = true;
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq refusal: case {case} could not record the refusal',
			['case' => 'case-1', 'reason' => 'disk full'],
		);

		try {
			$this->outcome()->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');
			self::fail(message: 'A write that did not land must not report a refusal.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusalOutcome::RULE_CASE_UNREADABLE, actual: $e->getRule());
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
			self::assertSame(
				expected: 'The refusal could not be recorded on the case, so nothing was refused.',
				actual: $e->getSentence()
			);
			self::assertInstanceOf(expected: RuntimeException::class, actual: $e->getPrevious());
		}

		self::assertArrayNotHasKey(key: 'intakeRefusal', array: $this->store->cases['case-1']);
	}//end testAWriteThatDoesNotLandRefusesIndeterminate()

	/**
	 * When the object service is gone by the time of the write, nothing is recorded and the answer is indeterminate.
	 *
	 * @return void
	 */
	public function testAStoreGoneAtWriteTimeRefusesIndeterminate(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq refusal: the register is not configured, so case {case} records nothing',
			['case' => 'case-1'],
		);

		// Read the case, resolve the team, then lose the store before the write.
		$outcome = $this->outcome(objectServices: [$this->store, $this->store, null]);

		try {
			$outcome->refuse(caseId: 'case-1', reason: 'Niet voor ons.', refusedBy: 'jdevries');
			self::fail(message: 'A refusal with no store to write to must not report success.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusalOutcome::RULE_CASE_UNREADABLE, actual: $e->getRule());
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
			self::assertSame(
				expected: 'The register is not configured, so the refusal was not recorded.',
				actual: $e->getSentence()
			);
		}

		self::assertArrayNotHasKey(key: 'intakeRefusal', array: $this->store->cases['case-1']);
	}//end testAStoreGoneAtWriteTimeRefusesIndeterminate()

	/**
	 * A recorded refusal is read back whole; a record not marked refused reads empty.
	 *
	 * @return void
	 */
	public function testARecordedRefusalIsReadBack(): void {
		$record = ['refused' => true, 'reason' => 'Niet voor ons.', 'department' => 'Juridische Zaken'];

		self::assertSame(expected: $record, actual: $this->outcome()->refusalOn(case: ['intakeRefusal' => $record]));
		self::assertSame(
			expected: [],
			actual: $this->outcome()->refusalOn(case: ['intakeRefusal' => ['refused' => 'yes']])
		);
		self::assertSame(expected: [], actual: $this->outcome()->refusalOn(case: ['intakeRefusal' => 'refused']));
	}//end testARecordedRefusalIsReadBack()
}//end class
