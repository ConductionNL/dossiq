<?php

/**
 * A file uploaded in a task form binds to the task until it is completed.
 *
 * The three states are a sequence, and only the middle one is visible in a
 * browser: an open task with a file that is NOT among the case's documents
 * looks exactly like a case with no document, which is also what a broken
 * upload looks like. So the hold, the release and the publication are pinned
 * here, on the store rather than on the screen.
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
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskAttachmentService
 */
class TaskAttachmentTest extends TestCase {

	/**
	 * The store every test in this file runs against.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * A case with no held files, and an object service that remembers writes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/**
			 * The stored case.
			 *
			 * @var array<string, mixed>
			 */
			public array $case = ['id' => 'case-9', 'title' => 'Bezwaar Jansen'];

			/**
			 * Every object created, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $created = [];

			/**
			 * @param string $objectId The case.
			 * @param array<string, mixed> $data The changes.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed> The stored case.
			 */
			public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
				$this->case = array_merge($this->case, $data);

				return $this->case;
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, mixed $register, mixed $schema, ?string $uuid = null): array {
				$this->created[] = $object;

				return $object;
			}

			/**
			 * @param string $id The object id.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function find(string $id, mixed $register, mixed $schema): array {
				return $this->case;
			}
		};
	}

	/**
	 * A held file waits with the task and is not a document on the case.
	 *
	 * @return void
	 */
	public function testAHeldFileIsNotYetACaseDocument(): void {
		$service = $this->service();

		$held = $service->bind(
			caseId: 'case-9',
			taskId: 'task-1',
			file: ['file' => 'file-7', 'title' => 'Verslag hoorzitting.pdf'],
			uploader: 'hbakker'
		);

		$this->assertCount(1, $held);
		$this->assertSame('file-7', $held[0]['file']);
		$this->assertSame('hbakker', $held[0]['uploadedBy']);
		// The whole point: no caseDocument was written, so the case's
		// documents do not contain it and no reader had to learn to filter.
		$this->assertSame([], $this->store->created);
	}

	/**
	 * A file is removed while the task is open.
	 *
	 * @return void
	 */
	public function testAFileIsRemovedWhileTheTaskIsOpen(): void {
		$service = $this->service();
		$service->bind(caseId: 'case-9', taskId: 'task-1', file: ['file' => 'file-7'], uploader: 'hbakker');
		$service->bind(caseId: 'case-9', taskId: 'task-1', file: ['file' => 'file-8'], uploader: 'hbakker');

		$left = $service->release(caseId: 'case-9', taskId: 'task-1', fileId: 'file-7');

		$this->assertCount(1, $left);
		$this->assertSame('file-8', $left[0]['file']);
	}

	/**
	 * Completing the task publishes its files to the case, naming the task.
	 *
	 * @return void
	 */
	public function testCompletingPublishesTheFileAndRecordsTheTask(): void {
		$service = $this->service();
		$service->bind(
			caseId: 'case-9',
			taskId: 'task-1',
			file: ['file' => 'file-7', 'title' => 'Verslag hoorzitting.pdf'],
			uploader: 'hbakker'
		);
		// A second task's file, which this completion must leave alone.
		$service->bind(caseId: 'case-9', taskId: 'task-2', file: ['file' => 'file-9'], uploader: 'hbakker');

		$published = $service->publish(caseId: 'case-9', taskId: 'task-1');

		$this->assertSame(1, $published);
		$this->assertCount(1, $this->store->created);
		$this->assertSame('case-9', $this->store->created[0]['case']);
		$this->assertSame('file-7', $this->store->created[0]['document']);
		// 🔑 WHICH TASK PRODUCED IT. Without this the document is on the case
		// and the trail back to the work that produced it is gone.
		$this->assertSame('task-1', $this->store->created[0]['sourceTask']);

		// The published file leaves the hold; the other task's does not.
		$this->assertSame(
			['file-9'],
			array_column($this->store->case[TaskAttachmentService::HOLD], 'file')
		);
	}

	/**
	 * Publishing a task that held nothing writes nothing.
	 *
	 * @return void
	 */
	public function testATaskThatHeldNothingPublishesNothing(): void {
		$this->assertSame(0, $this->service()->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame([], $this->store->created);
	}

	/**
	 * A hold that came back as a JSON string is still read.
	 *
	 * A store that round-trips an array property through a text column hands
	 * back the JSON, and reading only the array shape is how a file is lost.
	 *
	 * @return void
	 */
	public function testAHoldStoredAsJsonIsStillRead(): void {
		$this->store->case[TaskAttachmentService::HOLD] = json_encode(
			[['task' => 'task-1', 'file' => 'file-7', 'title' => 'Verslag.pdf']]
		);

		$this->assertSame(1, $this->service()->publish(caseId: 'case-9', taskId: 'task-1'));
		$this->assertSame('file-7', $this->store->created[0]['document']);
	}

	/**
	 * The service over the fake store.
	 *
	 * @return TaskAttachmentService The service.
	 */
	private function service(): TaskAttachmentService {
		$settings = $this->getMockBuilder(SettingsService::class)->disableOriginalConstructor()->getMock();
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturn('dossiq');

		return new TaskAttachmentService(settings: $settings, logger: new NullLogger());
	}
}//end class
