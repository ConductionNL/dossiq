<?php

/**
 * Golden test for TaskAttachmentService::publish: which files become documents,
 * which leave the hold, and every log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class TaskAttachmentPublishGoldenTest extends TestCase {

	private object $store;

	/** @var array<int, array{0: string, 1: string, 2: array}> */
	private array $logged = [];

	protected function setUp(): void {
		$this->logged = [];
		$this->store  = new class {
			public array $case = ['id' => 'case-9'];

			public array $created = [];

			public array $patches = [];

			public array $failing = [];

			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				$this->patches[] = [$objectId, $data];
				$this->case      = array_merge($this->case, $data);
				return $this->case;
			}

			public function saveObject(array $object, mixed $register, mixed $schema, ?string $uuid = null): array {
				if (in_array($object['document'], $this->failing, true) === true) {
					throw new RuntimeException('disk full');
				}

				unset($object['registrationDate']);
				$this->created[] = $object;
				return $object;
			}

			public function find(string $id, mixed $register, mixed $schema): array {
				return $this->case;
			}
		};
	}

	public function testEveryPublishedFileLeavesTheHoldAndNothingElse(): void {
		$this->store->case[TaskAttachmentService::HOLD] = [
			['task' => ' task-1 ', 'file' => ' file-1 ', 'title' => ' Een.pdf '],
			['task' => 'task-2', 'file' => 'file-1'],
			'not-an-entry',
			['task' => 'task-1', 'file' => 'file-2'],
		];

		$this->assertSame(2, $this->service()->publish(caseId: ' case-9 ', taskId: 'task-1 '));
		$this->assertSame(
			[
				['case' => ' case-9 ', 'document' => 'file-1', 'title' => 'Een.pdf', 'sourceTask' => 'task-1 '],
				['case' => ' case-9 ', 'document' => 'file-2', 'title' => '', 'sourceTask' => 'task-1 '],
			],
			$this->store->created
		);
		$this->assertSame(
			[['case-9', [TaskAttachmentService::HOLD => [['task' => 'task-2', 'file' => 'file-1']]]]],
			$this->store->patches
		);
		$this->assertSame([], $this->logged);
	}//end testEveryPublishedFileLeavesTheHoldAndNothingElse()

	public function testAFailedWriteCountsFromTheFront(): void {
		$this->store->failing = ['file-1'];
		$this->store->case[TaskAttachmentService::HOLD] = [
			['task' => 'task-1', 'file' => 'file-1'],
			['task' => 'task-1', 'file' => 'file-2'],
			['task' => 'task-1', 'file' => 'file-3'],
		];

		$this->assertSame(2, $this->service()->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame(['file-2', 'file-3'], array_column($this->store->created, 'document'));
		// The count of successes is taken from the front of the list.
		$this->assertSame(
			[['case-9', [TaskAttachmentService::HOLD => [['task' => 'task-1', 'file' => 'file-3']]]]],
			$this->store->patches
		);
		$this->assertSame(
			[['error', 'Dossiq: a completed task could not publish its file to the case', ['case' => 'case-9', 'task' => 'task-1', 'error' => 'disk full']]],
			$this->logged
		);
	}//end testAFailedWriteCountsFromTheFront()

	public function testEveryWriteFailingStillWritesTheHoldBack(): void {
		$this->store->failing = ['file-1'];
		$this->store->case[TaskAttachmentService::HOLD] = json_encode([['task' => 'task-1', 'file' => 'file-1']]);

		$this->assertSame(0, $this->service()->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame(
			[['case-9', [TaskAttachmentService::HOLD => [['task' => 'task-1', 'file' => 'file-1']]]]],
			$this->store->patches
		);
		$this->assertCount(1, $this->logged);
	}//end testEveryWriteFailingStillWritesTheHoldBack()

	public function testNothingHeldWritesNothing(): void {
		$this->store->case[TaskAttachmentService::HOLD] = [['task' => 'task-2', 'file' => 'file-1']];

		$this->assertSame(0, $this->service()->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame([], $this->store->patches);
		$this->assertSame([], $this->store->created);
		$this->assertSame([], $this->logged);
	}//end testNothingHeldWritesNothing()

	public function testAnUnreadableCaseKeepsItsFilesHeld(): void {
		$this->store->case[TaskAttachmentService::HOLD] = [['task' => 'task-1', 'file' => 'file-1']];

		$this->assertSame(0, $this->service(caseSchema: '')->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame([], $this->store->patches);
		$this->assertSame(
			[['error', 'Dossiq: the case of a completed task could not be read, so its files stay held', ['case' => 'case-9', 'task' => 'task-1', 'error' => 'storage_unavailable']]],
			$this->logged
		);
	}//end testAnUnreadableCaseKeepsItsFilesHeld()

	public function testNoDocumentSchemaPublishesNothing(): void {
		$this->store->case[TaskAttachmentService::HOLD] = [['task' => 'task-1', 'file' => 'file-1']];

		$this->assertSame(0, $this->service(documentSchema: '')->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame([], $this->store->created);
		$this->assertCount(1, $this->store->patches);
		$this->assertSame(
			[['error', 'Dossiq: no case document schema is configured, so a completed task cannot publish its file', ['case' => 'case-9', 'task' => 'task-1']]],
			$this->logged
		);
	}//end testNoDocumentSchemaPublishesNothing()

	private function service(string $caseSchema = 'cases', string $documentSchema = 'documents'): TaskAttachmentService {
		$settings = $this->getMockBuilder(className: SettingsService::class)->disableOriginalConstructor()->getMock();
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'case_schema' => $caseSchema,
				'case_document_schema' => $documentSchema,
				default => 'dossiq',
			}
		);

		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new TaskAttachmentService(settings: $settings, logger: $logger);
	}//end service()
}//end class
