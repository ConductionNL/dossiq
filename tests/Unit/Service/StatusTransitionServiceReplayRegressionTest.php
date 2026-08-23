<?php

/**
 * StatusTransitionService::replay() Phase-0 Regression Tests
 *
 * Locks the Phase-0 fix where replay() queries OpenRegister via the real
 * `searchObjects(['@self' => [...], 'case' => ...])` API instead of the
 * non-existent `findObjects()` method, and orders the history chronologically.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenRegister ObjectService shape used by StatusTransitionService.
 *
 * The lack of a `findObjects()` method here is intentional — it documents the
 * Phase-0 root cause (that method never existed on the real ObjectService).
 */
interface ReplayObjectServiceStub {
	public function searchObjects(array $query): array;
}//end interface

/**
 * Regression tests for StatusTransitionService::replay().
 *
 * @covers \OCA\Dossiq\Service\StatusTransitionService
 *
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 */
class StatusTransitionServiceReplayRegressionTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var StatusTransitionService
	 */
	private StatusTransitionService $service;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new StatusTransitionService(
			$this->createMock(WorkflowTemplateLoader::class),
			$this->createMock(GuardRegistry::class),
			$this->createMock(SideEffectDispatcher::class),
			new CaseStatusStore($this->settingsService, $this->logger),
			new TransitionAuthorizer($this->createMock(IGroupManager::class), $this->logger),
			new TransitionSpecReader(),
			$this->createMock(IUserSession::class),
			$this->logger,
		);

	}//end setUp()

	/**
	 * Configure the SettingsService mock with register + status_record_schema IDs.
	 *
	 * @param object $objectService The ObjectService mock to return.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '7'],
				['status_record_schema', '', '99'],
			]
		);

	}//end withObjectService()

	/**
	 * replay() must call searchObjects() with the @self register/schema context
	 * and a top-level `case` equality filter — the Phase-0 query shape.
	 *
	 * @return void
	 */
	public function testReplayUsesSearchObjectsWithSelfBlock(): void {
		$objectService = $this->createMock(ReplayObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->expects($this->once())
			->method('searchObjects')
			->with(
				$this->callback(
					static function (array $query): bool {
						return ($query['@self']['register'] ?? null) === 7
							&& ($query['@self']['schema'] ?? null) === 99
							&& ($query['case'] ?? null) === 'case-1';
					}
				)
			)
			->willReturn([]);

		$result = $this->service->replay('case-1');

		$this->assertSame([], $result['history']);
		$this->assertTrue($result['replayable']);

	}//end testReplayUsesSearchObjectsWithSelfBlock()

	/**
	 * replay() orders records chronologically by createdAt.
	 *
	 * @return void
	 */
	public function testReplaySortsHistoryChronologically(): void {
		$objectService = $this->createMock(ReplayObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->method('searchObjects')->willReturn(
			[
				['id' => 'later', 'createdAt' => '2026-06-02T10:00:00+00:00'],
				['id' => 'earlier', 'createdAt' => '2026-06-01T10:00:00+00:00'],
			]
		);

		$result = $this->service->replay('case-2');

		$this->assertTrue($result['replayable']);
		$this->assertSame('earlier', $result['history'][0]['id']);
		$this->assertSame('later', $result['history'][1]['id']);

	}//end testReplaySortsHistoryChronologically()

	/**
	 * A throwing searchObjects() is caught and yields a non-replayable empty set.
	 *
	 * @return void
	 */
	public function testReplaySwallowsSearchObjectsFailure(): void {
		$objectService = $this->createMock(ReplayObjectServiceStub::class);
		$this->withObjectService($objectService);

		$objectService->method('searchObjects')
			->willThrowException(new \RuntimeException('boom'));

		$result = $this->service->replay('case-err');

		$this->assertSame([], $result['history']);
		$this->assertFalse($result['replayable']);

	}//end testReplaySwallowsSearchObjectsFailure()

	/**
	 * No ObjectService (OpenRegister absent) returns a non-replayable empty set.
	 *
	 * @return void
	 */
	public function testReplayReturnsEmptyWithoutObjectService(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->replay('case-x');

		$this->assertSame([], $result['history']);
		$this->assertFalse($result['replayable']);

	}//end testReplayReturnsEmptyWithoutObjectService()
}//end class
