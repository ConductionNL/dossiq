<?php

/**
 * CaseAssigneeWriter Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\Dossiq\Service\Support\ReassignmentBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Moving one case to another handler: the case gets the new assignee and an
 * activity entry naming the batch; a failed write is logged with the case.
 */
#[CoversClass(CaseAssigneeWriter::class)]
#[UsesClass(ReassignmentBatch::class)]
class CaseAssigneeWriterTest extends TestCase {

	/**
	 * Logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The writer over an object service and the "zaak" case schema.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $register      The configured register.
	 *
	 * @return CaseAssigneeWriter
	 */
	private function writer(?object $objectService, string $register = '14'): CaseAssigneeWriter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => $register,
				'case_schema' => 'zaak',
				default => '',
			}
		);

		return new CaseAssigneeWriter(settingsService: $settings, logger: $this->logger);
	}//end writer()

	/**
	 * An object service recording updates, optionally failing them.
	 *
	 * @param bool $fail Whether updateObject throws.
	 *
	 * @return object
	 */
	private function objects(bool $fail = false): object {
		return new class($fail) {
			/**
			 * @var array<int, array{0: string, 1: array<string, mixed>}>
			 */
			public array $updates = [];

			/**
			 * @param bool $fail Whether updateObject throws.
			 */
			public function __construct(private bool $fail) {
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The data.
			 *
			 * @return array<string, mixed>
			 */
			public function updateObject(string $objectId, array $data): array {
				if ($this->fail === true) {
					throw new \RuntimeException('locked');
				}

				$this->updates[] = [$objectId, $data];
				return $data;
			}
		};
	}//end objects()

	/**
	 * The case gets the new assignee and one activity entry for the batch.
	 *
	 * @return void
	 */
	public function testTheCaseMovesAndTheMoveIsLogged(): void {
		$objects = $this->objects();
		$this->logger->expects($this->never())->method('warning');

		$written = $this->writer(objectService: $objects)->reassignOne(
			caseId: 'case-1',
			case: ['assignee' => ' alice ', 'title' => 'Bezwaar', 'activity' => json_encode([['type' => 'created']])],
			toUser: 'bob',
			actorId: 'manager',
			batchId: 'batch-1'
		);

		$this->assertTrue($written);
		$this->assertCount(1, $objects->updates);
		[$id, $data] = $objects->updates[0];
		$this->assertSame('case-1', $id);
		$this->assertSame('bob', $data['assignee']);
		$this->assertSame('Bezwaar', $data['title']);
		$activity = json_decode($data['activity'], true);
		$this->assertCount(2, $activity);
		$this->assertSame(['type' => 'created'], $activity[0]);
		$this->assertSame('reassignment', $activity[1]['type']);
		$this->assertSame('alice', $activity[1]['reassignedFrom']);
		$this->assertSame('bob', $activity[1]['reassignedTo']);
		$this->assertSame('manager', $activity[1]['reassignedBy']);
		$this->assertSame('batch-1', $activity[1]['batchId']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $activity[1]['timestamp']);
	}//end testTheCaseMovesAndTheMoveIsLogged()

	/**
	 * A failed write returns false and says which case and batch failed.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReportedWithTheCase(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$written = $this->writer(objectService: $this->objects(fail: true))->reassignOne(
			caseId: 'case-2',
			case: ['assignee' => 'alice'],
			toUser: 'bob',
			actorId: 'manager',
			batchId: 'batch-2'
		);

		$this->assertFalse($written);
		$this->assertSame(
			[
				['Reassignment item failed', ['id' => 'case-2', 'batchId' => 'batch-2', 'error' => 'locked']],
				['Dossiq: a case in a bulk redistribution was not written', ['case' => 'case-2', 'toUser' => 'bob', 'batchId' => 'batch-2']],
			],
			$warnings
		);
	}//end testAFailedWriteIsReportedWithTheCase()

	/**
	 * Without OpenRegister nothing is attempted.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAttempted(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->writer(objectService: null)->reassignOne(caseId: 'c', case: [], toUser: 'bob', actorId: 'm', batchId: 'b');
	}//end testWithoutOpenRegisterNothingIsAttempted()

	/**
	 * Each new batch id is fresh and carries the batch prefix.
	 *
	 * @return void
	 */
	public function testNewBatchIdsAreFresh(): void {
		$writer = $this->writer(objectService: $this->objects());

		$first = $writer->newBatchId();
		$second = $writer->newBatchId();

		$this->assertMatchesRegularExpression('/^batch-[0-9a-f]{16}$/', $first);
		$this->assertNotSame($first, $second);
	}//end testNewBatchIdsAreFresh()
}//end class
