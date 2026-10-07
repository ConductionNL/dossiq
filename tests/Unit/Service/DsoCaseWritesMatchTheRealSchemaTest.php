<?php

/**
 * The DSO case writes, validated against the case schema dossiq really ships.
 *
 * WHY IT EXISTS. DsoCaseService wrote `caseType: 'omgevingsvergunning'` and
 * `status: 'submitted'` on a schema that declares both as uuid references
 * (`$ref: caseType` and `$ref: statusType`). Every unit test passed, and
 * OpenRegister refused the first real save: "Property 'caseType' should match
 * format 'uuid'". So no DSO intake ever became a case, and nothing ever wrote
 * `dsoStatus`, which is the field DsoDeadlineJob selects on. These tests run
 * the real service and hand what it would save to the merged register's own
 * schema, so a value that does not fit its declaration fails here first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Dso\DsoStatusChangeNotifier;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DsoCaseService writes the shape the case schema accepts.
 */
class DsoCaseWritesMatchTheRealSchemaTest extends TestCase {

	private const CASE_TYPE_ID = '3562629e-bf38-4ce2-9c42-25dbfac308ca';
	private const INITIAL_STATUS_ID = 'c49063df-4484-4bc6-a92f-fe7442b11f9a';
	private const VERZOEK_ID = 'ed3a759e-e1c2-4bd3-b3f6-d826775ff7ea';
	private const CASE_ID = '9b1f3c4e-2d6a-4f1e-8a7b-5c3d2e1f0a9b';

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * What the service handed to saveObject() or patchObject(), in order.
	 *
	 * @var array<int, array{method: string, data: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Rows find() answers, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The integriq dso_verzoek as it stands after mapping (measured live, dsc-live).
	 *
	 * @return array<string, mixed>
	 */
	private function mappedVerzoek(): array {
		return [
			'id' => self::VERZOEK_ID,
			'@self' => ['id' => self::VERZOEK_ID, 'register' => '20', 'schema' => '60'],
			'verzoekId' => 'dsc-live-b1',
			'status' => 'mapped',
			'submissionDate' => '2026-10-04',
			'mappedTitle' => 'Dsc: milieubelastende activiteit',
			'mappedSummary' => 'Aanvraag via het Omgevingsloket',
			'mappedCaseTypes' => ['DSC-MILIEU'],
			'rawRequest' => [
				'activiteiten' => [
					['imowId' => 'nl.imow-gm0000.activiteit.DscMilieu', 'activityName' => 'Dsc: milieubelastende activiteit'],
				],
			],
		];
	}//end mappedVerzoek()

	/**
	 * The case type the mapping row names by its catalogue identifier.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(): array {
		return [
			'id' => self::CASE_TYPE_ID,
			'title' => 'Omgevingsvergunning milieu',
			'identifier' => 'DSC-MILIEU',
			'isDraft' => false,
			'initialStatus' => self::INITIAL_STATUS_ID,
		];
	}//end caseType()

	/**
	 * An entity double serialising to the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rows = [
			self::VERZOEK_ID => $this->mappedVerzoek(),
			self::CASE_TYPE_ID => $this->caseType(),
		];

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturnCallback(
			fn (string|int $id): ?ObjectEntityInterface => isset($this->rows[(string)$id]) === true ? $this->entity(row: $this->rows[(string)$id]) : null
		);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				if (($query['identifier'] ?? null) === 'DSC-MILIEU') {
					return [$this->entity(row: $this->caseType())];
				}

				return [];
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->writes[] = ['method' => 'saveObject', 'data' => $object];
				return $this->entity(row: (['id' => self::CASE_ID] + $object));
			}
		);
		$this->objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->writes[] = ['method' => 'patchObject', 'data' => $data];
				return $this->entity(row: (['id' => $objectId] + $data));
			}
		);
	}//end setUp()

	/**
	 * Build the real service with whatever collaborators its constructor takes.
	 *
	 * @return DsoCaseService
	 */
	private function service(): DsoCaseService {
		$config = [
			'register' => '21',
			'case_schema' => '113',
			'case_type_schema' => '106',
			'status_type_schema' => '107',
			'dso_vergunningaanvraag_schema' => '60',
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$userSession = $this->createMock(IUserSession::class);

		$available = [
			'appConfig' => $appConfig,
			'container' => $this->createMock(ContainerInterface::class),
			'notifier' => new DsoStatusChangeNotifier(eventDispatcher: $this->createMock(IEventDispatcher::class)),
			'logger' => $this->createMock(LoggerInterface::class),
			'objectService' => $this->objectService,
			'workingDays' => new WorkingDayCalculator(),
		];
		if (class_exists(CaseTypeReferenceResolver::class) === true) {
			$available['caseTypes'] = new CaseTypeReferenceResolver(store: new CaseTypeStore(settingsService: $settings));
			$available['journal'] = new CaseJournal(userSession: $userSession);
		}

		$args = [];
		foreach ((new \ReflectionClass(DsoCaseService::class))->getConstructor()->getParameters() as $parameter) {
			$args[$parameter->getName()] = $available[$parameter->getName()];
		}

		return new DsoCaseService(...$args);
	}//end service()

	/**
	 * The case an intake writes fits the case schema, on the mapped case type.
	 *
	 * @return void
	 */
	public function testTheIntakeCaseFitsTheRealCaseSchema(): void {
		$this->service()->createZaakFromVergunningaanvraag(permitApplicationId: self::VERZOEK_ID);

		$creates = array_values(array_filter($this->writes, static fn (array $write): bool => $write['method'] === 'saveObject'));
		$this->assertCount(1, $creates, 'the intake writes one case');
		$case = $creates[0]['data'];

		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $case));
		$this->assertSame(self::CASE_TYPE_ID, $case['caseType'], 'the case type is the one the mapping row names, by uuid');
		$this->assertSame(self::INITIAL_STATUS_ID, $case['status'], 'the case opens at its type\'s initial status');
		$this->assertSame('submitted', $case['dsoStatus'], 'dsoStatus is written, so DsoDeadlineJob finds the case');
		$this->assertSame(self::VERZOEK_ID, $case['permitApplicationRef']);
	}//end testTheIntakeCaseFitsTheRealCaseSchema()

	/**
	 * A DSO status move writes dsoStatus and leaves the case's own status alone.
	 *
	 * @return void
	 */
	public function testAStatusMoveFitsTheRealCaseSchema(): void {
		$this->rows[self::CASE_ID] = [
			'id' => self::CASE_ID,
			'title' => 'Omgevingsvergunning: Dsc',
			'caseType' => self::CASE_TYPE_ID,
			'status' => self::INITIAL_STATUS_ID,
			'dsoStatus' => 'submitted',
			'permitApplicationRef' => '',
		];

		$this->service()->transitionStatus(
			caseId: self::CASE_ID,
			newStatus: 'granted',
			besluitdatum: '2026-10-08',
			notes: 'Voldoet aan de regels.',
			userId: 'alice'
		);

		$this->assertCount(1, $this->writes, 'one write per move');
		$write = $this->writes[0];

		$this->assertSame(
			[],
			(new RealSchemaValidator())->errors(slug: 'case', payload: $write['data'], creating: false)
		);
		$this->assertSame('granted', $write['data']['dsoStatus'] ?? null);
		$this->assertNotSame('granted', $write['data']['status'] ?? null, 'the DSO value never lands on the uuid status');
	}//end testAStatusMoveFitsTheRealCaseSchema()
}//end class
