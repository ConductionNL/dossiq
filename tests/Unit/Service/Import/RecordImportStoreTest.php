<?php

/**
 * The record-import store's reads and writes, and what each answers when
 * OpenRegister refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Import
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use DateTime;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Import\RecordImportStore
 * @uses   \OCA\Dossiq\Service\Import\RecordCaseMapping
 */
class RecordImportStoreTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The store under test.
	 *
	 * @return RecordImportStore
	 */
	private function store(): RecordImportStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'result_schema' => 'result',
			][$key] ?? $default
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20T09:00:00+02:00'));

		return new RecordImportStore(
			settingsService: $settings,
			mapping: new RecordCaseMapping(dates: $this->caseDates()),
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end store()

	/**
	 * A register whose every call fails.
	 *
	 * @return InMemoryRegister
	 */
	private function refusing(): InMemoryRegister {
		return new class extends InMemoryRegister {
			/**
			 * Refuses.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(...$args): array {
				throw new RuntimeException('down');
			}

			/**
			 * Refuses.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(...$args): array {
				throw new RuntimeException('down');
			}

			/**
			 * Refuses.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(...$args): ?array {
				throw new RuntimeException('down');
			}
		};
	}//end refusing()

	/**
	 * The case type is found by uuid and by identifier.
	 *
	 * @return void
	 */
	public function testTheCaseTypeIsFoundByUuidOrIdentifier(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'type-1', row: ['identifier' => 'type-a']);

		self::assertSame('type-1', $this->store()->caseType(objectService: $register, caseType: 'type-1')['id']);
		self::assertSame('type-1', $this->store()->caseType(objectService: $register, caseType: 'type-a')['id']);
		self::assertNull($this->store()->caseType(objectService: $register, caseType: 'nope'));
		self::assertNull($this->store()->caseType(objectService: $this->refusing(), caseType: 'type-1'));
	}//end testTheCaseTypeIsFoundByUuidOrIdentifier()

	/**
	 * Earlier cases are keyed by the source uuid at the match field; no match field, or a refused read, finds none.
	 *
	 * @return void
	 */
	public function testEarlierCasesAreFoundByTheirSourceUuid(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['caseType' => 'type-1', 'origin' => ['id' => 'rec-1']]);
		$register->seed(schema: 'case', uuid: 'case-2', row: ['caseType' => 'type-1']);
		$register->seed(schema: 'case', uuid: 'case-3', row: ['caseType' => 'type-2', 'origin' => ['id' => 'rec-3']]);
		$type = ['id' => 'type-1'];

		self::assertSame(['rec-1' => 'case-1'], $this->store()->casesBySource(objectService: $register, caseType: $type, import: ['matchField' => 'origin.id']));
		self::assertSame([], $this->store()->casesBySource(objectService: $register, caseType: $type, import: []));
		self::assertSame([], $this->store()->casesBySource(objectService: $this->refusing(), caseType: $type, import: ['matchField' => 'origin.id']));
	}//end testEarlierCasesAreFoundByTheirSourceUuid()

	/**
	 * A case is written with its result; a refused write answers '' and a refused result leaves the case.
	 *
	 * @return void
	 */
	public function testACaseIsWrittenWithItsResult(): void {
		$register = new InMemoryRegister();
		$caseId = $this->store()->writeCase(objectService: $register, mapped: ['case' => ['title' => 'T'], 'result' => 'r-1']);

		self::assertSame('generated-1', $caseId);
		self::assertSame([['case' => 'generated-1', 'resultType' => 'r-1', 'id' => 'generated-1']], $register->all('result'));
		self::assertSame('', $this->store()->writeCase(objectService: $this->refusing(), mapped: ['case' => [], 'result' => '']));
	}//end testACaseIsWrittenWithItsResult()

	/**
	 * The stamp is written and read back; no declared stamp, a refusal or a dropped key is not a stamp.
	 *
	 * @return void
	 */
	public function testTheStampIsReadBack(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'record', uuid: 'rec-1', row: ['title' => 'T']);
		$import = ['sourceRegister' => 'src', 'sourceSchema' => 'record', 'stamp' => ['caseId' => 'movedTo', 'at' => 'movedAt']];

		self::assertTrue($this->store()->stamp(objectService: $register, import: $import, sourceId: 'rec-1', caseId: 'case-1'));
		self::assertSame('2026-10-20T09:00:00+02:00', $register->row('record', 'rec-1')['movedAt']);
		self::assertFalse($this->store()->stamp(objectService: $register, import: ['stamp' => []], sourceId: 'rec-1', caseId: 'case-1'));
		self::assertFalse($this->store()->stamp(objectService: $register, import: $import, sourceId: 'missing', caseId: 'case-1'));
	}//end testTheStampIsReadBack()

	/**
	 * A listing pages until a short page, and stops on a store that ignores the offset.
	 *
	 * @return void
	 */
	public function testAListingStopsOnARepeatedPage(): void {
		$register = new InMemoryRegister();
		for ($i = 0; $i < 501; $i++) {
			$register->seed(schema: 'record', uuid: 'rec-' . $i, row: []);
		}

		self::assertCount(501, $this->store()->listAll(objectService: $register, register: 'src', schema: 'record', filters: []));
		self::assertSame('rec-2', $this->store()->idOf(row: ['@self' => ['id' => 'rec-2']]));
		self::assertSame('', $this->store()->idOf(row: []));
	}//end testAListingStopsOnARepeatedPage()
}//end class
