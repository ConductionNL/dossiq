<?php

/**
 * Milestone Repository Test
 *
 * Pins where milestone data is read from: definitions by case type and
 * records by case, each from its configured schema in the dossier register,
 * and a case's case type from the case row. Also pins what each read answers
 * when OpenRegister or the configuration is missing, or the case cannot be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for MilestoneRepository.
 */
#[CoversClass(MilestoneRepository::class)]
final class MilestoneRepositoryTest extends TestCase {
	/**
	 * The default configuration: numeric register and schemas.
	 */
	private const CONFIG = [
		'register'                    => '5',
		'milestone_definition_schema' => '21',
		'milestone_record_schema'     => '22',
		'case_schema'                 => '10',
	];

	/**
	 * A fake object service recording its searches and answering a case row.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows any search answers.
	 * @param mixed                          $case  What getObject() answers: an object, null, or a Throwable to throw.
	 *
	 * @return object
	 */
	private static function objectService(array $rows = [], mixed $case = null): object {
		return new class ($rows, $case) {
			/**
			 * The search queries made.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $queries = [];

			/**
			 * The register, schema and object set before reading a case.
			 *
			 * @var array<int,string>
			 */
			public array $selected = [];

			/**
			 * Build the double.
			 *
			 * @param array<int,array<string,mixed>> $rows Rows any search answers.
			 * @param mixed                          $case The case answer.
			 */
			public function __construct(private readonly array $rows, private readonly mixed $case) {
			}

			/**
			 * Search objects.
			 *
			 * @param array<string,mixed> $query The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				return $this->rows;
			}

			/**
			 * Select the register.
			 *
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
				$this->selected[] = 'register:' . $register;
			}

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->selected[] = 'schema:' . $schema;
			}

			/**
			 * Select the object.
			 *
			 * @param string $id The object id.
			 *
			 * @return void
			 */
			public function setObject(string $id): void {
				$this->selected[] = 'object:' . $id;
			}

			/**
			 * Read the selected object.
			 *
			 * @return object|null
			 */
			public function getObject(): ?object {
				if ($this->case instanceof \Throwable) {
					throw $this->case;
				}

				return $this->case;
			}
		};
	}//end objectService()

	/**
	 * A case entity whose object data is the given array.
	 *
	 * @param array<string,mixed> $data The case data.
	 *
	 * @return object
	 */
	private static function caseEntity(array $data): object {
		return new class ($data) {
			/**
			 * Build the double.
			 *
			 * @param array<string,mixed> $data The case data.
			 */
			public function __construct(private readonly array $data) {
			}

			/**
			 * The object data.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}
		};
	}//end caseEntity()

	/**
	 * The repository over the given object service and configuration.
	 *
	 * @param object|null          $objectService The object service, or null when OpenRegister is absent.
	 * @param array<string,string> $config        Config values.
	 *
	 * @return MilestoneRepository
	 */
	private function repository(?object $objectService, array $config = self::CONFIG): MilestoneRepository {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new MilestoneRepository(settingsService: $settings);
	}//end repository()

	/**
	 * Definitions are searched by case type in the definition schema of the dossier register.
	 *
	 * @return void
	 */
	public function testDefinitionsAreSearchedByCaseType(): void {
		$service = self::objectService(rows: [['id' => 'm1', 'title' => 'Intake']]);

		$definitions = $this->repository(objectService: $service)->findDefinitions(caseTypeId: 'ct-1');

		self::assertSame(expected: [['caseType' => 'ct-1', '_limit' => 100, '@self' => ['register' => 5, 'schema' => 21]]], actual: $service->queries);
		self::assertSame(expected: 'm1', actual: $definitions[0]['id']);
	}//end testDefinitionsAreSearchedByCaseType()

	/**
	 * Without OpenRegister, reading definitions fails loudly; without configuration it finds none.
	 *
	 * @return void
	 */
	public function testDefinitionsWithoutOpenRegisterOrConfiguration(): void {
		$service = self::objectService(rows: [['id' => 'm1']]);

		self::assertSame(expected: [], actual: $this->repository(objectService: $service, config: ['register' => '5'])->findDefinitions(caseTypeId: 'ct-1'));
		self::assertSame(expected: [], actual: $service->queries);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->repository(objectService: null)->findDefinitions(caseTypeId: 'ct-1');
	}//end testDefinitionsWithoutOpenRegisterOrConfiguration()

	/**
	 * Records are searched by case in the record schema; without OpenRegister or configuration there are none.
	 *
	 * @return void
	 */
	public function testRecordsAreSearchedByCase(): void {
		$service = self::objectService(rows: [['id' => 'r1']]);

		$records = $this->repository(objectService: $service)->findRecords(caseId: 'case-1');

		self::assertSame(expected: [['case' => 'case-1', '_limit' => 100, '@self' => ['register' => 5, 'schema' => 22]]], actual: $service->queries);
		self::assertSame(expected: 'r1', actual: $records[0]['id']);
		self::assertSame(expected: [], actual: $this->repository(objectService: null)->findRecords(caseId: 'case-1'));
		self::assertSame(expected: [], actual: $this->repository(objectService: $service, config: ['milestone_record_schema' => '22'])->findRecords(caseId: 'case-1'));
	}//end testRecordsAreSearchedByCase()

	/**
	 * A case's case type is read from the case row in the case schema.
	 *
	 * @return void
	 */
	public function testCaseTypeIsReadFromTheCase(): void {
		$service = self::objectService(case: self::caseEntity(data: ['caseType' => 'ct-7', 'title' => 'Bouw']));

		self::assertSame(expected: 'ct-7', actual: $this->repository(objectService: $service)->findCaseTypeId(caseId: 'case-1'));
		self::assertSame(expected: ['register:5', 'schema:10', 'object:case-1'], actual: $service->selected);
	}//end testCaseTypeIsReadFromTheCase()

	/**
	 * Without OpenRegister, configuration, a readable case or a string case type there is no case type.
	 *
	 * @return void
	 */
	public function testNoCaseTypeWhenItCannotBeRead(): void {
		$cases = [
			'no OpenRegister'      => $this->repository(objectService: null),
			'no case schema'       => $this->repository(objectService: self::objectService(case: self::caseEntity(data: ['caseType' => 'ct-7'])), config: ['register' => '5']),
			'read fails'           => $this->repository(objectService: self::objectService(case: new RuntimeException('gone'))),
			'no case'              => $this->repository(objectService: self::objectService(case: null)),
			'no case type'         => $this->repository(objectService: self::objectService(case: self::caseEntity(data: []))),
			'empty case type'      => $this->repository(objectService: self::objectService(case: self::caseEntity(data: ['caseType' => '']))),
			'non-string case type' => $this->repository(objectService: self::objectService(case: self::caseEntity(data: ['caseType' => ['id' => 'ct-7']]))),
		];

		foreach ($cases as $name => $repository) {
			self::assertNull(actual: $repository->findCaseTypeId(caseId: 'case-1'), message: $name);
		}
	}//end testNoCaseTypeWhenItCannotBeRead()
}//end class
