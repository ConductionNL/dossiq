<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The repair keeps one document record per file: the first one written.
 *
 * @spec openspec/changes/document-lookups-stay-in-their-schema/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\RemoveDocumentRecordTwins;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RemoveDocumentRecordTwinsTest extends TestCase {
	/** @var DocumentRecordStore&MockObject The record store double. */
	private DocumentRecordStore&MockObject $store;

	/** @var IAppConfig&MockObject The version gate. */
	private IAppConfig&MockObject $appConfig;

	/** @var object The stub object service answering the record pages. */
	private object $objects;

	/** @var RemoveDocumentRecordTwins The step under test. */
	private RemoveDocumentRecordTwins $step;

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
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->step = new RemoveDocumentRecordTwins(
			settingsService: $settings,
			store: $this->store,
			appConfig: $this->appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Two records naming one file: the later one goes with its joins, the first stays.
	 *
	 * @return void
	 */
	public function testTheLaterRecordOfAFileGoesWithItsJoins(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [
			['@self' => ['id' => 'twin', 'created' => '2026-10-25T14:55:31+00:00'], 'fileId' => 20749, 'sender' => ''],
			['@self' => ['id' => 'set', 'created' => '2026-10-25T14:55:30+00:00'], 'fileId' => 20749, 'sender' => 'Groenbeheer'],
			['@self' => ['id' => 'alone', 'created' => '2026-10-25T14:55:29+00:00'], 'fileId' => 20750],
			['@self' => ['id' => 'no-file', 'created' => '2026-10-25T14:55:29+00:00']],
			['@self' => ['id' => 'no-file-2', 'created' => '2026-10-25T14:55:30+00:00']],
		];
		$this->store->expects($this->once())->method('deleteJoins')->with('twin');
		$this->store->expects($this->once())->method('deleteRecord')->with('twin');
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, RemoveDocumentRecordTwins::DONE_KEY, RemoveDocumentRecordTwins::DONE_VERSION);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'removed 1'));

		$this->step->run(output: $output);
	}//end testTheLaterRecordOfAFileGoesWithItsJoins()

	/**
	 * A later record with a folder of its own may hold files of its own, so it stays.
	 *
	 * @return void
	 */
	public function testALaterRecordWithItsOwnFolderStays(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objects->records = [
			['@self' => ['id' => 'first', 'created' => '2026-10-25T14:55:30+00:00'], 'fileId' => 7],
			['@self' => ['id' => 'second', 'created' => '2026-10-25T14:55:31+00:00', 'folder' => '9001'], 'fileId' => 7],
		];
		$this->store->expects($this->never())->method('deleteJoins');
		$this->store->expects($this->never())->method('deleteRecord');

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));
	}//end testALaterRecordWithItsOwnFolderStays()

	/**
	 * A step that already ran does nothing.
	 *
	 * @return void
	 */
	public function testASecondRunDoesNothing(): void {
		$this->appConfig->method('getValueString')->willReturn(RemoveDocumentRecordTwins::DONE_VERSION);
		$this->objects->records = [
			['@self' => ['id' => 'a', 'created' => '2026-10-25T14:55:30+00:00'], 'fileId' => 7],
			['@self' => ['id' => 'b', 'created' => '2026-10-25T14:55:31+00:00'], 'fileId' => 7],
		];
		$this->store->expects($this->never())->method('deleteRecord');

		$this->step->run(output: $this->createMock(originalClassName: IOutput::class));
	}//end testASecondRunDoesNothing()
}//end class
