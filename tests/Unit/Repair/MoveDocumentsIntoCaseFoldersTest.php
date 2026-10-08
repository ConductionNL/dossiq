<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The migration moves each existing document's file into its case, once.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\MoveDocumentsIntoCaseFolders;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MoveDocumentsIntoCaseFoldersTest extends TestCase {
	/** @var DocumentRecordStore&MockObject The record store double. */
	private DocumentRecordStore&MockObject $store;

	/** @var DocumentProjectionService&MockObject The projection double. */
	private DocumentProjectionService&MockObject $projection;

	/** @var IAppConfig&MockObject The version gate. */
	private IAppConfig&MockObject $appConfig;

	/** @var LoggerInterface&MockObject Where skipped records are named. */
	private LoggerInterface&MockObject $logger;

	/** @var object The stub object service answering the record pages. */
	private object $objects;

	/** @var MoveDocumentsIntoCaseFolders The step under test. */
	private MoveDocumentsIntoCaseFolders $step;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> The records, one page. */
			public array $records = [];

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> One page of records.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				if ((int)($filters['_page'] ?? 1) > 1) {
					return [];
				}

				return $this->records;
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
			][$key] ?? $default)
		);
		$this->store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$this->projection = $this->createMock(originalClassName: DocumentProjectionService::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->step = new MoveDocumentsIntoCaseFolders(
			settingsService: $settings,
			store: $this->store,
			projection: $this->projection,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A folder double that does or does not hold the file.
	 *
	 * @param bool $holdsFile Whether the file is there.
	 *
	 * @return Folder&MockObject The folder.
	 */
	private function folder(bool $holdsFile): Folder&MockObject {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('nodeExists')->willReturn($holdsFile);
		return $folder;
	}//end folder()

	/**
	 * @return void
	 */
	public function testEachDocumentMovesIntoItsEarliestCaseOnce(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [
			['@self' => ['id' => 'rec-1'], 'fileName' => 'a.pdf', 'fileId' => 1],
			['@self' => ['id' => 'rec-2'], 'fileName' => 'b.pdf', 'fileId' => 2],
			['@self' => ['id' => 'rec-3'], 'fileName' => 'c.pdf', 'fileId' => 3],
		];
		$this->projection->method('folderOf')->willReturnCallback(
			fn (string $objectId): Folder => $this->folder(holdsFile: $objectId !== 'rec-3')
		);
		$this->store->method('joinsFor')->willReturnCallback(
			static fn (string $recordId): array => match ($recordId) {
				'rec-1' => [
					['case' => 'case-later', 'registrationDate' => '2026-03-02T10:00:00Z'],
					['case' => 'case-first', 'registrationDate' => '2026-03-01T10:00:00Z'],
				],
				'rec-2' => [['case' => 'case-b', 'registrationDate' => '2026-03-01T10:00:00Z']],
				default => [],
			}
		);
		$moves = [];
		$this->projection->method('homeDocument')->willReturnCallback(
			static function (string $recordId, string $caseId) use (&$moves): bool {
				$moves[] = [$recordId, $caseId];
				return true;
			}
		);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, MoveDocumentsIntoCaseFolders::DONE_KEY, MoveDocumentsIntoCaseFolders::DONE_VERSION);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'moved 2 file(s)'));

		$this->step->run(output: $output);

		$this->assertSame(
			expected: [['rec-1', 'case-first'], ['rec-2', 'case-b']],
			actual: $moves,
			message: 'the earliest join owns the file; rec-3 is already home',
		);
	}//end testEachDocumentMovesIntoItsEarliestCaseOnce()

	/**
	 * @return void
	 */
	public function testARecordJoinedToNoCaseIsNamedAndLeftAlone(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [['@self' => ['id' => 'rec-9'], 'fileName' => 'z.pdf', 'fileId' => 9]];
		$this->projection->method('folderOf')->willReturn($this->folder(holdsFile: true));
		$this->store->method('joinsFor')->willReturn([]);
		$this->projection->expects($this->never())->method('homeDocument');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'rec-9'));

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));
	}//end testARecordJoinedToNoCaseIsNamedAndLeftAlone()

	/**
	 * @return void
	 */
	public function testAFailedMoveIsNamedAndTheRunGoesOn(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [
			['@self' => ['id' => 'rec-1'], 'fileName' => 'a.pdf', 'fileId' => 1],
			['@self' => ['id' => 'rec-2'], 'fileName' => 'b.pdf', 'fileId' => 2],
		];
		$this->projection->method('folderOf')->willReturn($this->folder(holdsFile: true));
		$this->store->method('joinsFor')->willReturn([['case' => 'case-1', 'registrationDate' => '2026-01-01T00:00:00Z']]);
		$this->projection->method('homeDocument')->willReturnCallback(
			static function (string $recordId): bool {
				if ($recordId === 'rec-1') {
					throw new RuntimeException('storage gone');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'storage gone'));
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'moved 1 file(s)'));

		$this->step->run(output: $output);
	}//end testAFailedMoveIsNamedAndTheRunGoesOn()

	/**
	 * @return void
	 */
	public function testASecondRunMovesNothing(): void {
		$this->appConfig->method('getValueString')->willReturn(MoveDocumentsIntoCaseFolders::DONE_VERSION);
		$this->projection->expects($this->never())->method('homeDocument');
		$this->projection->expects($this->never())->method('folderOf');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));
		$this->addToAssertionCount(count: 1);
	}//end testASecondRunMovesNothing()

	/**
	 * @return void
	 */
	public function testARecordWithoutAFileIsSkippedWithoutALookup(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [['@self' => ['id' => 'rec-0'], 'fileName' => 'seed.pdf']];
		$this->projection->expects($this->never())->method('folderOf');
		$this->projection->expects($this->never())->method('homeDocument');

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));
		$this->addToAssertionCount(count: 1);
	}//end testARecordWithoutAFileIsSkippedWithoutALookup()
}//end class
