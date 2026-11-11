<?php

/**
 * CaseReassignmentService preview golden tests.
 *
 * Pins what a reassignment preview reads and answers: the refusal of an empty
 * handler, a missing store, the final statuses in every spelling, a case type
 * narrowing cases and the tasks of those cases, cases and tasks keyed by id or
 * uuid, no case schema, and a status read that fails.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for CaseReassignmentService::preview().
 *
 * @covers \OCA\Dossiq\Service\CaseReassignmentService
 */
class CaseReassignmentPreviewGoldenTest extends TestCase {

	/**
	 * The searches the store saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $searches = [];

	/**
	 * The service over a store answering these rows per schema.
	 *
	 * @param array<string, mixed>             $rows   Schema => rows, or a Throwable.
	 * @param array<int, array<string, mixed>> $tasks  The engine's open tasks.
	 * @param array<string, string>            $config The configuration.
	 * @param bool                             $store  Whether OpenRegister is there.
	 *
	 * @return CaseReassignmentService
	 */
	private function service(array $rows, array $tasks = [], array $config = [], bool $store = true): CaseReassignmentService {
		$objectService = $this->createMock(SubstitutionObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters = []) use ($rows): array {
				$this->searches[] = [$reg, $schema, $filters];
				$answer = $rows[$schema] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$config += ['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		$inbox = $this->getMockBuilder(EngineTaskInbox::class)->disableOriginalConstructor()->getMock();
		$inbox->method('openForAssignee')->willReturnCallback(
			function (string $actor) use ($tasks): array {
				$this->searches[] = ['engine', $actor];
				return $tasks;
			}
		);

		return new CaseReassignmentService(
			$settings,
			$this->createMock(BulkJobHandoff::class),
			$inbox,
			$this->getMockBuilder(EngineTaskGateway::class)->disableOriginalConstructor()->getMock(),
			$this->createMock(IManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * An empty handler is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmptyHandlerIsRefused(): void {
		try {
			$this->service([])->preview('  ');
			$this->fail('An empty handler must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('fromUser is required', $e->getMessage());
		}

		$this->assertSame([], $this->searches);
	}//end testAnEmptyHandlerIsRefused()

	/**
	 * Without OpenRegister the preview fails as the context does.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePreviewFails(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service([], store: false)->preview('jan');
	}//end testWithoutOpenRegisterThePreviewFails()

	/**
	 * Final statuses in every spelling close a case; the case type narrows cases and their tasks.
	 *
	 * @return void
	 */
	public function testFinalStatusesAndTheCaseTypeNarrow(): void {
		$rows = [
			'statusType' => [['id' => 's-a', 'isFinal' => true], ['uuid' => 's-b', 'isFinal' => 'true'], ['id' => 's-c', 'isFinal' => 1], ['id' => 's-d', 'isFinal' => '1'], ['isFinal' => true]],
			'case' => [
				['id' => 'c1', 'status' => 's-a', 'caseType' => 'vth'],
				['id' => 'c2', 'status' => 's-b', 'caseType' => 'vth'],
				['id' => 'c3', 'status' => 's-c', 'caseType' => 'vth'],
				['uuid' => 'c4', 'status' => 's-d', 'caseType' => 'vth'],
				['id' => 'c5', 'status' => 'open', 'caseType' => 'wmo'],
				['id' => 'c6', 'caseType' => 'vth'],
			],
		];
		$tasks = [['id' => 't1', 'case' => 'c4'], ['id' => 't2', 'case' => 'c5'], ['id' => 't3', 'case' => 'c6'], ['id' => 't4']];

		$preview = $this->service($rows, $tasks)->preview(' jan ', ['caseType' => 'vth']);

		$this->assertSame(['c4', 'c6'], array_map(static fn (array $c): string => $c['id'] ?? $c['uuid'], $preview['cases']));
		$this->assertSame(['t1', 't3'], array_column($preview['tasks'], 'id'));
		$this->assertSame([['dossiq', 'statusType', []], ['dossiq', 'case', ['assignee' => 'jan']], ['engine', 'jan']], $this->searches);
	}//end testFinalStatusesAndTheCaseTypeNarrow()

	/**
	 * Without a case type every open case and every open task is previewed.
	 *
	 * @return void
	 */
	public function testWithoutACaseTypeEverythingOpenIsPreviewed(): void {
		$preview = $this->service(['case' => [['id' => 'c1', 'caseType' => 'vth']]], [['id' => 't1'], ['id' => 't2', 'case' => 'x']])->preview('jan', []);

		$this->assertSame(['c1'], array_column($preview['cases'], 'id'));
		$this->assertSame(['t1', 't2'], array_column($preview['tasks'], 'id'));
	}//end testWithoutACaseTypeEverythingOpenIsPreviewed()

	/**
	 * Without a case schema no case is read, and a case type then keeps no task.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchema(): void {
		$preview = $this->service([], [['id' => 't1', 'case' => 'c1']], ['case_schema' => ''])->preview('jan', ['caseType' => 'vth']);

		$this->assertSame(['cases' => [], 'tasks' => []], $preview);
		$this->assertSame([['dossiq', 'statusType', []], ['engine', 'jan']], $this->searches);
	}//end testWithoutACaseSchema()

	/**
	 * A status read that fails closes nothing, and no status schema reads nothing.
	 *
	 * @return void
	 */
	public function testStatusReadsThatGiveNothing(): void {
		$preview = $this->service(['statusType' => new RuntimeException('down'), 'case' => [['id' => 'c1', 'status' => 's-a']]])->preview('jan');
		$this->assertSame(['c1'], array_column($preview['cases'], 'id'));

		$this->searches = [];
		$this->service(['case' => []], [], ['status_type_schema' => ''])->preview('jan');
		$this->assertSame([['dossiq', 'case', ['assignee' => 'jan']], ['engine', 'jan']], $this->searches);
	}//end testStatusReadsThatGiveNothing()
}//end class
