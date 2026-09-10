<?php

/**
 * TaskBackfillService Unit Tests
 *
 * The backfill's three load-bearing behaviours:
 *
 *  1. The read MUST pass `_rbac: false` and `_multitenancy: false`. `occ`
 *     carries no session, so every ObjectService call runs as Anonymous and a
 *     scoped read returns nothing. The command would then report a clean run
 *     over zero tasks, which is indistinguishable from success on an empty
 *     instance. This is asserted on the ARGUMENTS, because the symptom is an
 *     empty result rather than an error.
 *  2. An unreachable engine is an ERROR, not a quiet zero. A backfill that
 *     wrote nothing and said nothing is the failure the command exists to
 *     prevent.
 *  3. A `case` $ref arrives as a bare id OR as an expanded object, and a
 *     (string) cast on the expanded shape yields the literal "Array".
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
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskBackfillService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskBackfillService
 */
class TaskBackfillServiceTest extends TestCase {

	/**
	 * An object service that records the query it was handed.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to return.
	 *
	 * @return object The recording double.
	 */
	private function objectService(array $rows): object {
		return new class ($rows) {
			/** @var array<string, mixed> */
			public array $lastQuery = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed>
			 */
			public function findAll(array $query): array {
				$this->lastQuery = $query;

				// One page only: the caller stops when a page is short.
				return ['results' => $this->rows];
			}
		};
	}//end objectService()

	/**
	 * Settings wired to a given object service.
	 *
	 * @param object|null $objectService The object service double.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settings(?object $objectService): SettingsService {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->getMock();
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'caseTask')
		);

		return $settings;
	}//end settings()

	/**
	 * A gateway double that is reachable and records what it was asked to write.
	 *
	 * @param string $reason The unavailable reason ('' means reachable).
	 *
	 * @return EngineTaskGateway&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function gateway(string $reason = ''): EngineTaskGateway {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('unavailableReason')->willReturn($reason);
		$gateway->method('mirrorCreate')->willReturn('engine-uuid');

		return $gateway;
	}//end gateway()

	/**
	 * 🔴 The read must disable RBAC and multitenancy.
	 *
	 * Asserted on the ARGUMENTS, not on the result: `occ` runs as Anonymous,
	 * so without these the query returns an empty set and the backfill
	 * reports a clean run over zero tasks. That looks exactly like success.
	 *
	 * @return void
	 */
	public function testTheReadDisablesRbacAndMultitenancyBecauseOccHasNoSession(): void {
		$objects = $this->objectService([['title' => 'T', 'case' => 'case-1']]);
		$service = new TaskBackfillService($this->settings($objects), $this->gateway(), new NullLogger());

		$service->run(dryRun: true);

		$this->assertArrayHasKey('_rbac', $objects->lastQuery);
		$this->assertFalse($objects->lastQuery['_rbac'], 'occ runs as Anonymous; a scoped read returns nothing');
		$this->assertArrayHasKey('_multitenancy', $objects->lastQuery);
		$this->assertFalse($objects->lastQuery['_multitenancy']);
	}//end testTheReadDisablesRbacAndMultitenancyBecauseOccHasNoSession()

	/**
	 * An unreachable engine is an error, not a quiet zero.
	 *
	 * @return void
	 */
	public function testAnUnreachableEngineIsReportedRatherThanCountedAsZero(): void {
		$service = new TaskBackfillService(
			$this->settings($this->objectService([])),
			$this->gateway('the namespace moved'),
			new NullLogger()
		);

		$result = $service->run(dryRun: false);

		$this->assertSame('the namespace moved', $result['error']);
		$this->assertSame(0, $result['read']);
	}//end testAnUnreachableEngineIsReportedRatherThanCountedAsZero()

	/**
	 * A dry run counts and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunCountsWithoutWriting(): void {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('unavailableReason')->willReturn('');
		$gateway->expects($this->never())->method('mirrorCreate');

		$service = new TaskBackfillService(
			$this->settings($this->objectService([['title' => 'A', 'case' => 'c1'], ['title' => 'B', 'case' => 'c2']])),
			$gateway,
			new NullLogger()
		);

		$result = $service->run(dryRun: true);

		$this->assertSame(2, $result['read']);
		$this->assertSame(2, $result['written']);
		$this->assertSame(0, $result['failed']);
	}//end testADryRunCountsWithoutWriting()

	/**
	 * A task with no case is skipped, not failed.
	 *
	 * The engine keys everything on the object triple, so a task with no case
	 * cannot be placed. The row is not broken, it is simply not migratable on
	 * its own, and counting it as a failure would make a healthy backfill
	 * exit non-zero.
	 *
	 * @return void
	 */
	public function testATaskWithNoCaseIsSkippedRatherThanFailed(): void {
		$service = new TaskBackfillService(
			$this->settings($this->objectService([['title' => 'orphan', 'case' => ''], ['title' => 'ok', 'case' => 'c1']])),
			$this->gateway(),
			new NullLogger()
		);

		$result = $service->run(dryRun: false);

		$this->assertSame(2, $result['read']);
		$this->assertSame(1, $result['written']);
		$this->assertSame(1, $result['skipped']);
		$this->assertSame(0, $result['failed'], 'an unplaceable task must not fail the run');
	}//end testATaskWithNoCaseIsSkippedRatherThanFailed()

	/**
	 * A `case` $ref is read in both shapes the store returns.
	 *
	 * A (string) cast on the expanded shape yields the literal "Array", which
	 * would write every task against an object uuid resolving to nothing.
	 *
	 * @return void
	 */
	public function testReadsTheCaseReferenceInBothShapes(): void {
		$service = new TaskBackfillService($this->settings(null), $this->gateway(), new NullLogger());

		$this->assertSame('c1', $service->caseIdOf(task: ['case' => 'c1']));
		$this->assertSame('c1', $service->caseIdOf(task: ['case' => ['id' => 'c1']]));
		$this->assertSame('c1', $service->caseIdOf(task: ['case' => ['uuid' => 'c1']]));
		$this->assertSame('', $service->caseIdOf(task: ['case' => '']));
		$this->assertSame('', $service->caseIdOf(task: []));

		// The bug this guards: never the literal "Array".
		$this->assertNotSame('Array', $service->caseIdOf(task: ['case' => ['id' => 'c1']]));
	}//end testReadsTheCaseReferenceInBothShapes()

	/**
	 * A missing ObjectService is an error rather than a zero.
	 *
	 * @return void
	 */
	public function testAMissingObjectServiceIsReported(): void {
		$service = new TaskBackfillService($this->settings(null), $this->gateway(), new NullLogger());

		$result = $service->run(dryRun: false);

		$this->assertStringContainsString('ObjectService', $result['error']);
	}//end testAMissingObjectServiceIsReported()
}//end class
