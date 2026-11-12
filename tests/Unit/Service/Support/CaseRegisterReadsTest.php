<?php

/**
 * Reading a case and its case type out of the configured register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseRegisterReads;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Support\CaseRegisterReads
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRegisterReadsTest extends TestCase {

	/**
	 * The reads over these config values and object service.
	 *
	 * @param array<string, string> $config        Config values.
	 * @param object|null           $objectService The object service, null when OpenRegister is away.
	 *
	 * @return CaseRegisterReads The reads.
	 */
	private function reads(array $config = [], ?object $objectService = null): CaseRegisterReads {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($config[$key] ?? '')
		);

		return new CaseRegisterReads(settingsService: $settings);
	}//end reads()

	/**
	 * An object service over rows per "schema/id"; records every find.
	 *
	 * @param array<string, mixed> $rows Rows per "schema/id"; a missing row throws DoesNotExist.
	 *
	 * @return object The fake.
	 */
	private function objects(array $rows): object {
		return new class ($rows) {
			/**
			 * Every find as "register/schema/id".
			 *
			 * @var array<int, string>
			 */
			public array $finds = [];

			/**
			 * @param array<string, mixed> $rows Rows per "schema/id".
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string     $id       Object id.
			 * @param int|string $register Register.
			 * @param int|string $schema   Schema.
			 *
			 * @return mixed The row.
			 */
			public function find(string $id, int|string $register, int|string $schema): mixed {
				$this->finds[] = $register . '/' . $schema . '/' . $id;
				if (array_key_exists($schema . '/' . $id, $this->rows) === false) {
					throw new DoesNotExistException('missing');
				}

				return $this->rows[$schema . '/' . $id];
			}
		};
	}//end objects()

	/**
	 * The object service is handed out, or its absence is an error.
	 *
	 * @return void
	 */
	public function testObjectService(): void {
		$objects = $this->objects(rows: []);
		$this->assertSame($objects, $this->reads(objectService: $objects)->objectService());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->reads()->objectService();
	}//end testObjectService()

	/**
	 * A configured schema is returned; an unconfigured one names its key.
	 *
	 * @return void
	 */
	public function testSchemaKey(): void {
		$this->assertSame('cases', $this->reads(config: ['case_schema' => 'cases'])->schemaKey(key: 'case_schema'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The schema 'case_type_schema' is not configured.");
		$this->reads()->schemaKey(key: 'case_type_schema');
	}//end testSchemaKey()

	/**
	 * A reference is a trimmed string, or the id / uuid of an expanded object.
	 *
	 * @return void
	 */
	public function testUuidOf(): void {
		$this->assertSame('abc', CaseRegisterReads::uuidOf(value: ' abc '));
		$this->assertSame('i1', CaseRegisterReads::uuidOf(value: ['id' => ' i1 ', 'uuid' => 'u1']));
		$this->assertSame('u1', CaseRegisterReads::uuidOf(value: ['id' => 7, 'uuid' => 'u1']));
		$this->assertSame('', CaseRegisterReads::uuidOf(value: ['name' => 'x']));
		$this->assertSame('', CaseRegisterReads::uuidOf(value: 42));
		$this->assertSame('', CaseRegisterReads::uuidOf(value: null));
	}//end testUuidOf()

	/**
	 * The case type is read from the case's reference, in the same register.
	 *
	 * @return void
	 */
	public function testCaseTypeOfFollowsTheCasesReference(): void {
		$objects = $this->objects(
			rows: [
				'cases/c1' => ['caseType' => ['id' => 'ct1']],
				'types/ct1' => ['id' => 'ct1', 'title' => 'Bezwaar'],
			]
		);
		$reads = $this->reads(config: ['case_schema' => 'cases', 'case_type_schema' => 'types'], objectService: $objects);

		$this->assertSame(['id' => 'ct1', 'title' => 'Bezwaar'], $reads->caseTypeOf(location: [$objects, 'reg'], caseId: 'c1'));
		$this->assertSame(['reg/cases/c1', 'reg/types/ct1'], $objects->finds);
	}//end testCaseTypeOfFollowsTheCasesReference()

	/**
	 * A case without a case type has none; the case type is then not read.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeHasNone(): void {
		$objects = $this->objects(rows: ['cases/c1' => ['caseType' => '']]);
		$reads = $this->reads(config: ['case_schema' => 'cases', 'case_type_schema' => 'types']);

		$this->assertNull($reads->caseTypeOf(location: [$objects, 'reg'], caseId: 'c1'));
		$this->assertSame(['reg/cases/c1'], $objects->finds);
	}//end testACaseWithoutACaseTypeHasNone()

	/**
	 * A missing case and a dangling case type are errors.
	 *
	 * @return void
	 */
	public function testMissingRowsAreErrors(): void {
		$config = ['case_schema' => 'cases', 'case_type_schema' => 'types'];

		try {
			$this->reads(config: $config)->caseTypeOf(location: [$this->objects(rows: []), 'reg'], caseId: 'c1');
			$this->fail('A missing case must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('Case not found', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Case type not found');
		$this->reads(config: $config)->caseTypeOf(location: [$this->objects(rows: ['cases/c1' => ['caseType' => 'gone']]), 'reg'], caseId: 'c1');
	}//end testMissingRowsAreErrors()
}//end class
