<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CopyMessageAttachmentsIntoMessageFolders;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task 2.2: each attachment's file lands in its message's folder, once.
 */
class CopyMessageAttachmentsIntoMessageFoldersTest extends TestCase {
	/** @var object The stub object service answering the message pages. */
	private object $objects;

	/** @var DocumentRecordStore&MockObject The record store double. */
	private DocumentRecordStore&MockObject $store;

	/** @var DocumentProjectionService&MockObject The projection double. */
	private DocumentProjectionService&MockObject $projection;

	/** @var IRootFolder&MockObject The root folder double. */
	private IRootFolder&MockObject $rootFolder;

	/** @var LoggerInterface&MockObject Where a missing file is named. */
	private LoggerInterface&MockObject $logger;

	/** @var array<string, string> What the app config holds. */
	private array $config = [];

	/** @var array<string, array<int, string>> File names per message folder. */
	private array $folders = [];

	/** @var array<int, array{0: string, 1: string}> Every copy as [file name, target path]. */
	private array $copies = [];

	/** @var CopyMessageAttachmentsIntoMessageFolders The step under test. */
	private CopyMessageAttachmentsIntoMessageFolders $step;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, array<string, mixed>> The messages, one page. */
			public array $messages = [];

			/** @var array<int, string> The schemas searched. */
			public array $schemas = [];

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> One page of messages.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->schemas[] = $schema;
				if ((int)($filters['_page'] ?? 1) > 1) {
					return [];
				}

				return $this->messages;
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'portaal_bericht_schema' => 'portaalBericht',
			][$key] ?? $default)
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$this->store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$this->projection = $this->createMock(originalClassName: DocumentProjectionService::class);
		$this->projection->method('folderOf')->willReturnCallback(
			fn (string $objectId): ?Folder => $this->folderFor(objectId: $objectId)
		);
		$this->rootFolder = $this->createMock(originalClassName: IRootFolder::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->step = new CopyMessageAttachmentsIntoMessageFolders(
			settingsService: $settings,
			store: $this->store,
			projection: $this->projection,
			rootFolder: $this->rootFolder,
			appConfig: $appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A message folder double that remembers what was copied into it.
	 *
	 * @param string $objectId The message uuid.
	 *
	 * @return Folder|null The folder, null for a message OpenRegister answers no folder for.
	 */
	private function folderFor(string $objectId): ?Folder {
		if ($objectId === 'msg-nofolder') {
			return null;
		}

		$this->folders[$objectId] = ($this->folders[$objectId] ?? []);
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getPath')->willReturn('/system/files/Open Registers/dossiq/' . $objectId);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => in_array($name, $this->folders[$objectId], true)
		);
		return $folder;
	}//end folderFor()

	/**
	 * A file double whose copy lands in the folder its target path names.
	 *
	 * @param string $name The file name.
	 *
	 * @return File The file.
	 */
	private function file(string $name): File {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getName')->willReturn($name);
		$file->method('copy')->willReturnCallback(
			function (string $target) use ($name, $file): File {
				$this->copies[] = [$name, $target];
				$messageId = basename(dirname($target));
				$this->folders[$messageId][] = basename($target);
				return $file;
			}
		);
		return $file;
	}//end file()

	/**
	 * @return void
	 */
	public function testRunTwiceCopiesEachFileOnce(): void {
		$this->objects->messages = [
			['id' => 'msg-1', 'attachments' => ['rec-a', '42']],
			['id' => 'msg-2', 'attachments' => []],
		];
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => ([
				'rec-a' => ['id' => 'rec-a', 'fileId' => 7, 'fileName' => 'besluit.pdf'],
			][$recordId] ?? null)
		);
		$files = [7 => $this->file(name: 'besluit.pdf'), 42 => $this->file(name: 'foto.jpg')];
		$this->rootFolder->method('getById')->willReturnCallback(
			static fn (int $id): array => array_values(array_filter([($files[$id] ?? null)]))
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$this->step->run(output: $output);
		// Second run with the version key cleared: the copies are found and not repeated.
		$this->config = [];
		$this->step->run(output: $output);

		$this->assertSame(
			expected: [
				['besluit.pdf', '/system/files/Open Registers/dossiq/msg-1/besluit.pdf'],
				['foto.jpg', '/system/files/Open Registers/dossiq/msg-1/foto.jpg'],
			],
			actual: $this->copies,
			message: 'a record id and a bare file id each give one copy in the message folder, across two runs',
		);
		$this->assertSame(
			expected: CopyMessageAttachmentsIntoMessageFolders::DONE_VERSION,
			actual: $this->config[CopyMessageAttachmentsIntoMessageFolders::DONE_KEY] ?? '',
		);
		$this->assertSame(expected: 'portaalBericht', actual: $this->objects->schemas[0]);
	}//end testRunTwiceCopiesEachFileOnce()

	/**
	 * @return void
	 */
	public function testTheVersionKeyStopsASecondRun(): void {
		$this->config[CopyMessageAttachmentsIntoMessageFolders::DONE_KEY] = CopyMessageAttachmentsIntoMessageFolders::DONE_VERSION;
		$this->objects->messages = [['id' => 'msg-1', 'attachments' => ['42']]];
		$this->rootFolder->expects($this->never())->method('getById');

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $this->objects->schemas, message: 'no message is even read');
	}//end testTheVersionKeyStopsASecondRun()

	/**
	 * @return void
	 */
	public function testAnAttachmentWithoutAFileIsNamedAndTheRunGoesOn(): void {
		$this->objects->messages = [
			['@self' => ['id' => 'msg-1'], 'attachments' => ['rec-gone', '42']],
			['id' => 'msg-nofolder', 'attachments' => ['42']],
		];
		$this->store->method('findRecord')->willReturn(null);
		$file = $this->file(name: 'foto.jpg');
		$this->rootFolder->method('getById')->willReturnCallback(
			static fn (int $id): array => array_values(array_filter([([42 => $file][$id] ?? null)]))
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'copied 1 file(s)'));

		$this->step->run(output: $output);

		$this->assertCount(expectedCount: 1, haystack: $this->copies);
		$this->assertCount(expectedCount: 2, haystack: $warnings);
		$this->assertStringContainsString(needle: 'rec-gone', haystack: $warnings[0]);
		$this->assertStringContainsString(needle: 'msg-nofolder', haystack: $warnings[1]);
	}//end testAnAttachmentWithoutAFileIsNamedAndTheRunGoesOn()

	/**
	 * @return void
	 */
	public function testWithoutAMessageSchemaNothingRunsAndTheKeyStaysUnset(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->expects($this->never())->method('setValueString');
		$step = new CopyMessageAttachmentsIntoMessageFolders(
			settingsService: $settings,
			store: $this->store,
			projection: $this->projection,
			rootFolder: $this->rootFolder,
			appConfig: $appConfig,
			logger: $this->logger,
		);

		$step->run(output: $this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $this->objects->schemas);
	}//end testWithoutAMessageSchemaNothingRunsAndTheKeyStaysUnset()
}//end class
