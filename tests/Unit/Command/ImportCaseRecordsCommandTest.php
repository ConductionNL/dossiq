<?php

/**
 * `occ dossiq:case:import-records` prints what the real import answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Command
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\ImportCaseRecordsCommand;
use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\TermCarryOver;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Built on the real import class over an in-memory register.
 *
 * @covers \OCA\Dossiq\Command\ImportCaseRecordsCommand
 * @uses   \OCA\Dossiq\Service\Import\CaseRecordImport
 * @uses   \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @uses   \OCA\Dossiq\Service\Import\RecordImportStore
 * @uses   \OCA\Dossiq\Service\Term\TermCarryOver
 */
class ImportCaseRecordsCommandTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The command over a register holding two source records and a case type declaring their import.
	 *
	 * @param bool $installed Whether the source app is installed.
	 *
	 * @return CommandTester
	 */
	private function tester(bool $installed = true): CommandTester {
		$store = new InMemoryRegister();
		$store->seed(schema: 'record', uuid: 'r1', row: ['status' => 'open', 'title' => 'One', 'number' => 'A-1']);
		$store->seed(schema: 'record', uuid: 'r2', row: ['status' => 'open', 'title' => 'Two', 'number' => 'A-2', 'movedTo' => 'case-x']);
		$store->seed(
			schema: 'caseType',
			uuid: 'type-1',
			row: [
				'identifier' => 'type-a',
				'recordImports' => [
					[
						'key' => 'from-source',
						'sourceApp' => 'sourceapp',
						'sourceRegister' => 'src',
						'sourceSchema' => 'record',
						'statuses' => ['open' => ['status' => 's1', 'term' => 'running']],
						'fields' => [['to' => 'title', 'from' => 'title'], ['to' => 'origin.id', 'from' => '@id']],
						'matchField' => 'origin.id',
						'formerReference' => ['application' => 'sourceapp', 'from' => 'number'],
						'stamp' => ['caseId' => 'movedTo'],
					],
				],
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? $default
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$dates = $this->caseDates();

		$import = new CaseRecordImport(
			settingsService: $settings,
			appManager: $apps,
			mapping: new RecordCaseMapping(dates: $dates),
			term: new TermCarryOver(terms: $this->createMock(TermijnService::class), timers: $this->createMock(TermijnTimerService::class), dates: $dates),
			store: new RecordImportStore(settingsService: $settings, mapping: new RecordCaseMapping(dates: $dates), time: $this->createMock(ITimeFactory::class), logger: $this->createMock(LoggerInterface::class)),
		);

		return new CommandTester(new ImportCaseRecordsCommand(import: $import));
	}//end tester()

	/**
	 * A dry run prints the count of records that have not moved, and exits 0.
	 *
	 * @return void
	 */
	public function testTheCommandPrintsTheUnmigratedCount(): void {
		$tester = $this->tester();

		$exit = $tester->execute(['caseType' => 'type-a', '--dry-run' => true]);

		self::assertSame(0, $exit);
		self::assertStringContainsString('from-source: 0 imported, 1 already imported, 0 failed, 1 not moved.', $tester->getDisplay());
	}//end testTheCommandPrintsTheUnmigratedCount()

	/**
	 * A source app that is not installed is said so, and exits 0.
	 *
	 * @return void
	 */
	public function testAnAbsentSourceAppIsSaidAndExitsZero(): void {
		$tester = $this->tester(installed: false);

		self::assertSame(0, $tester->execute(['caseType' => 'type-1']));
		self::assertStringContainsString('sourceapp is not installed, nothing to import.', $tester->getDisplay());
	}//end testAnAbsentSourceAppIsSaidAndExitsZero()

	/**
	 * A failed record (here: its term cannot be armed, there is none) is printed with its reason, exit 1.
	 *
	 * @return void
	 */
	public function testAFailedRecordIsPrintedAndExitsOne(): void {
		$tester = $this->tester();

		self::assertSame(1, $tester->execute(['caseType' => 'type-a', '--import' => 'from-source']));
		self::assertStringContainsString('failed A-1 (r1): The case generated-1 was written, but its term could not be armed (missing).', $tester->getDisplay());
	}//end testAFailedRecordIsPrintedAndExitsOne()

	/**
	 * An unknown case type exits 1; a key nothing declares runs nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeExitsOne(): void {
		$tester = $this->tester();
		self::assertSame(1, $tester->execute(['caseType' => 'nope']));

		self::assertSame(0, $tester->execute(['caseType' => 'type-a', '--import' => 'other']));
		self::assertStringContainsString('declares no record import to run', $tester->getDisplay());
	}//end testAnUnknownCaseTypeExitsOne()
}//end class
