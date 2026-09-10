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

			/** Whether the read asked for RBAC to be bypassed. */
			public bool $lastRbac = true;

			/** Whether the read asked for multitenancy to be bypassed. */
			public bool $lastMultitenancy = true;

			/** The register context set before the read. */
			public string $register = '';

			/** The schema context set before the read. */
			public string $schema = '';

			/**
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
				$this->register = $register;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->schema = $schema;
			}

			/**
			 * Mirrors the real signature: the two flags are POSITIONAL.
			 *
			 * @param array<string, mixed> $query          The query.
			 * @param boolean              $rbac           RBAC flag.
			 * @param boolean              $multitenancy   Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function findAll(array $query, bool $rbac = true, bool $multitenancy = true): array {
				$this->lastQuery = $query;
				$this->lastRbac = $rbac;
				$this->lastMultitenancy = $multitenancy;

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
		// The backfill writes through mirrorImport(), the engine's TRUSTED
		// path: most existing dossiq tasks are `completed` and create()
		// refuses a terminal state. Both are stubbed so a future caller
		// switching paths does not silently start asserting nothing.
		$gateway->method('mirrorCreate')->willReturn('engine-uuid');
		$gateway->method('mirrorImport')->willReturn('engine-uuid');
		$gateway->method('existingKeysFor')->willReturn([]);

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

		// POSITIONAL, not config keys. Passed inside the array they are
		// silently ignored and the read runs as Anonymous: the first cut did
		// exactly that and reported "Read 0 task(s)" over 34 real rows.
		$this->assertFalse($objects->lastRbac, 'occ runs as Anonymous; a scoped read returns nothing');
		$this->assertFalse($objects->lastMultitenancy);

		// And the register/schema context is set BEFORE the read, because
		// findAll overwrites it as a side effect.
		$this->assertSame('dossiq', $objects->register);
		$this->assertSame('caseTask', $objects->schema);
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
		$gateway->method('existingKeysFor')->willReturn([]);
		// BOTH write paths, or this assertion is vacuous: the backfill writes
		// through mirrorImport(), so asserting only that mirrorCreate() is
		// never called would pass no matter what the code did.
		$gateway->expects($this->never())->method('mirrorCreate');
		$gateway->expects($this->never())->method('mirrorImport');

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
	 * 🔴 findAll returns RENDERED ENTITIES, not arrays.
	 *
	 * The first cut filtered rows on `is_array()` and dropped every one,
	 * reporting "Read 0 task(s)" against an instance holding 34, with no
	 * exception and nothing in the log. An empty result is the one failure
	 * shape that reads exactly like success on a clean instance, and only
	 * running the command against real data found it.
	 *
	 * @return void
	 */
	public function testReadsEntitiesAndNotOnlyArrays(): void {
		$service = new TaskBackfillService($this->settings(null), $this->gateway(), new NullLogger());

		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['title' => 'from an entity', 'case' => 'c1'];
			}
		};

		$this->assertSame(['title' => 'from an entity', 'case' => 'c1'], $service->toArray(row: $entity));
		$this->assertSame(['title' => 'plain'], $service->toArray(row: ['title' => 'plain']));
		$this->assertSame([], $service->toArray(row: 'a string'));
		$this->assertSame([], $service->toArray(row: null));
	}//end testReadsEntitiesAndNotOnlyArrays()

	/**
	 * And the whole pipeline counts an entity row, not just the helper.
	 *
	 * @return void
	 */
	public function testCountsEntityRowsEndToEnd(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['title' => 'entity task', 'case' => 'c1'];
			}
		};

		$service = new TaskBackfillService(
			$this->settings($this->objectService([$entity])),
			$this->gateway(),
			new NullLogger()
		);

		$result = $service->run(dryRun: true);

		$this->assertSame(1, $result['read'], 'an entity row must be counted, not silently dropped');
	}//end testCountsEntityRowsEndToEnd()

	/**
	 * 🔴 Re-running must not double every task.
	 *
	 * The first cut claimed idempotency in its own docblock and had none.
	 * Measured against the live instance: a second run took the engine from
	 * 33 dossiq tasks to 66. The fix stamps the register row's uuid into the
	 * engine's `key` field and skips a key the engine already holds.
	 *
	 * @return void
	 */
	public function testATaskTheEngineAlreadyHoldsIsCountedPresentAndNotRewritten(): void {
		$gateway = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$gateway->method('unavailableReason')->willReturn('');
		$gateway->method('existingKeysFor')->willReturn(
			[EngineTaskGateway::sourceKey(registerTaskId: 'reg-1') => true]
		);
		// BOTH write paths, or this assertion is vacuous: the backfill writes
		// through mirrorImport(), so asserting only that mirrorCreate() is
		// never called would pass no matter what the code did.
		$gateway->expects($this->never())->method('mirrorCreate');
		$gateway->expects($this->never())->method('mirrorImport');

		$service = new TaskBackfillService(
			$this->settings($this->objectService([['id' => 'reg-1', 'title' => 'already there', 'case' => 'c1']])),
			$gateway,
			new NullLogger()
		);

		$result = $service->run(dryRun: false, actor: 'admin');

		$this->assertSame(1, $result['read']);
		$this->assertSame(1, $result['present']);
		$this->assertSame(0, $result['written']);
		// NOT counted as skipped: that means "could not be placed", and
		// reporting a clean re-run as skipped-no-case reads like a defect.
		$this->assertSame(0, $result['skipped']);
	}//end testATaskTheEngineAlreadyHoldsIsCountedPresentAndNotRewritten()

	/**
	 * The engine key is namespaced, so it cannot collide with another app's.
	 *
	 * @return void
	 */
	public function testTheSourceKeyIsNamespaced(): void {
		$key = EngineTaskGateway::sourceKey(registerTaskId: 'abc');

		$this->assertSame('dossiq:caseTask:abc', $key);
		$this->assertStringStartsWith('dossiq:', $key);
	}//end testTheSourceKeyIsNamespaced()

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
