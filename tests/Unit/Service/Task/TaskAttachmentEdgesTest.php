<?php

/**
 * TaskAttachmentService edge tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
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

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An object service fake that holds one case and records every write.
 */
class AttachmentCaseStore {

	/** @var array<string, mixed> */
	public array $case = ['id' => 'case-1'];

	/** @var array<int, array<int, mixed>> */
	public array $patches = [];

	/** @var array<int, array<string, mixed>> */
	public array $saved = [];

	/** @var bool */
	public bool $failSave = false;

	/**
	 * @return array<string, mixed> The case.
	 */
	public function find(string $id, mixed $register, mixed $schema): array {
		return $this->case;
	}//end find()

	/**
	 * @param array<string, mixed> $data The changes.
	 * @return array<string, mixed> The case.
	 */
	public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
		$this->patches[] = [$objectId, $data, $register, $schema];
		$this->case = array_merge($this->case, $data);

		return $this->case;
	}//end patchObject()

	/**
	 * @param array<string, mixed> $object The object.
	 * @return array<string, mixed> The object.
	 */
	public function saveObject(array $object, mixed $register, mixed $schema, ?string $uuid = null): array {
		if ($this->failSave === true) {
			throw new RuntimeException('disk full');
		}

		$this->saved[] = ['object' => $object, 'schema' => $schema];

		return $object;
	}//end saveObject()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Task\TaskAttachmentService
 * @covers \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Task\CaseTask
 */
class TaskAttachmentEdgesTest extends TestCase {

	/**
	 * The service over a store, with the configuration given.
	 *
	 * @param object|null           $store  The object service, or null for none.
	 * @param array<string, string> $config The config values by key.
	 * @param LoggerInterface|null  $logger The logger.
	 * @return TaskAttachmentService The service.
	 */
	private function service(?object $store, array $config, ?LoggerInterface $logger = null): TaskAttachmentService {
		$settings = $this->getMockBuilder(className: SettingsService::class)->disableOriginalConstructor()->getMock();
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new TaskAttachmentService(settings: $settings, logger: ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * The full configuration: register, case schema and document schema.
	 *
	 * @return array<string, string>
	 */
	private static function configured(): array {
		return ['register' => 'reg', 'case_schema' => 'case', 'case_document_schema' => 'doc'];
	}//end configured()

	/**
	 * Without an object service, a register or a case schema, a bind is refused as storage unavailable.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreRefusesABind(): void {
		$cases = [
			[null, self::configured()],
			[new AttachmentCaseStore(), ['case_schema' => 'case']],
			[new AttachmentCaseStore(), ['register' => 'reg']],
		];

		foreach ($cases as [$store, $config]) {
			try {
				$this->service(store: $store, config: $config)->bind(caseId: 'case-1', taskId: 't-1', file: ['file' => 'f-1'], uploader: 'alice');
				self::fail('An unconfigured store must refuse the bind.');
			} catch (RuntimeException $e) {
				self::assertSame('storage_unavailable', $e->getMessage());
			}
		}
	}//end testAnUnconfiguredStoreRefusesABind()

	/**
	 * A bind records the trimmed file with its uploader, and answers only that task's files.
	 *
	 * @return void
	 */
	public function testABindRecordsTheFileAndAnswersThatTasksFiles(): void {
		$store = new AttachmentCaseStore();
		$store->case[TaskAttachmentService::HOLD] = [['task' => 't-2', 'file' => 'f-0']];

		$held = $this->service(store: $store, config: self::configured())->bind(
			caseId: ' case-1 ',
			taskId: ' t-1 ',
			file: ['file' => ' f-1 ', 'link' => ' /f/1 '],
			uploader: 'alice'
		);

		self::assertCount(1, $held);
		self::assertSame('t-1', $held[0]['task']);
		self::assertSame('f-1', $held[0]['file']);
		self::assertSame('f-1', $held[0]['title']);
		self::assertSame('/f/1', $held[0]['link']);
		self::assertSame('alice', $held[0]['uploadedBy']);
		self::assertNotFalse(strtotime($held[0]['uploadedAt']));

		self::assertCount(1, $store->patches);
		[$id, $data, $register, $schema] = $store->patches[0];
		self::assertSame('case-1', $id);
		self::assertSame('reg', $register);
		self::assertSame('case', $schema);
		self::assertSame(['t-2', 't-1'], array_column($data[TaskAttachmentService::HOLD], 'task'));
	}//end testABindRecordsTheFileAndAnswersThatTasksFiles()

	/**
	 * A release removes only that file of that task; the same file on another task stays.
	 *
	 * @return void
	 */
	public function testAReleaseRemovesOnlyThatTasksFile(): void {
		$store = new AttachmentCaseStore();
		$store->case[TaskAttachmentService::HOLD] = [
			['task' => 't-1', 'file' => 'f-1'],
			['task' => 't-1', 'file' => 'f-2'],
			['task' => 't-2', 'file' => 'f-1'],
		];

		$left = $this->service(store: $store, config: self::configured())->release(caseId: 'case-1', taskId: ' t-1 ', fileId: ' f-1 ');

		self::assertSame([['task' => 't-1', 'file' => 'f-2']], $left);
		self::assertSame(
			[['task' => 't-1', 'file' => 'f-2'], ['task' => 't-2', 'file' => 'f-1']],
			$store->case[TaskAttachmentService::HOLD]
		);
	}//end testAReleaseRemovesOnlyThatTasksFile()

	/**
	 * A published file becomes a case document naming the case, file, title and task.
	 *
	 * @return void
	 */
	public function testAPublishedFileBecomesACaseDocument(): void {
		$store = new AttachmentCaseStore();
		$store->case[TaskAttachmentService::HOLD] = [['task' => 't-1', 'file' => ' f-1 ', 'title' => ' Besluit.pdf ']];

		self::assertSame(1, $this->service(store: $store, config: self::configured())->publish(caseId: 'case-1', taskId: 't-1'));

		self::assertCount(1, $store->saved);
		self::assertSame('doc', $store->saved[0]['schema']);
		$document = $store->saved[0]['object'];
		self::assertSame('case-1', $document['case']);
		self::assertSame('f-1', $document['document']);
		self::assertSame('Besluit.pdf', $document['title']);
		self::assertSame('t-1', $document['sourceTask']);
		self::assertNotFalse(strtotime($document['registrationDate']));
		self::assertSame([], $store->case[TaskAttachmentService::HOLD]);
	}//end testAPublishedFileBecomesACaseDocument()

	/**
	 * A failed document write is logged with the case and task, and the file stays held.
	 *
	 * @return void
	 */
	public function testAFailedDocumentWriteIsLoggedAndKeepsTheFile(): void {
		$store = new AttachmentCaseStore();
		$store->failSave = true;
		$store->case[TaskAttachmentService::HOLD] = [['task' => 't-1', 'file' => 'f-1']];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'Dossiq: a completed task could not publish its file to the case',
				['case' => 'case-1', 'task' => 't-1', 'error' => 'disk full']
			);

		self::assertSame(0, $this->service(store: $store, config: self::configured(), logger: $logger)->publish(caseId: 'case-1', taskId: 't-1'));
		self::assertSame([['task' => 't-1', 'file' => 'f-1']], $store->case[TaskAttachmentService::HOLD]);
	}//end testAFailedDocumentWriteIsLoggedAndKeepsTheFile()

	/**
	 * Without a case store, a publish logs that the files stay held and publishes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsLoggedAndPublishesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'Dossiq: the case of a completed task could not be read, so its files stay held',
				['case' => 'case-1', 'task' => 't-1', 'error' => 'storage_unavailable']
			);

		self::assertSame(0, $this->service(store: null, config: self::configured(), logger: $logger)->publish(caseId: 'case-1', taskId: 't-1'));
	}//end testAnUnreadableCaseIsLoggedAndPublishesNothing()

	/**
	 * Without a document schema the missing configuration is logged and the file stays held.
	 *
	 * @return void
	 */
	public function testNoDocumentSchemaIsLoggedAndKeepsTheFile(): void {
		$store = new AttachmentCaseStore();
		$store->case[TaskAttachmentService::HOLD] = [['task' => 't-1', 'file' => 'f-1']];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'Dossiq: no case document schema is configured, so a completed task cannot publish its file',
				['case' => 'case-1', 'task' => 't-1']
			);

		$service = $this->service(store: $store, config: ['register' => 'reg', 'case_schema' => 'case'], logger: $logger);

		self::assertSame(0, $service->publish(caseId: 'case-1', taskId: 't-1'));
		self::assertSame([], $store->saved);
		self::assertSame([['task' => 't-1', 'file' => 'f-1']], $store->case[TaskAttachmentService::HOLD]);
	}//end testNoDocumentSchemaIsLoggedAndKeepsTheFile()
}//end class
