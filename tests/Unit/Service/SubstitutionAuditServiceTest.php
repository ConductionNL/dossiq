<?php

/**
 * SubstitutionAuditService Unit Tests.
 *
 * Covers capacity stamping (substituted action -> stamped; own action -> not
 * stamped) and the per-substitution action query.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\SubstitutionAuditService;
use OCA\Procest\Service\SubstitutionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (interface_exists(SubstitutionObjectServiceStub::class) === false) {
	/**
	 * Mockable ObjectService surface used by the substitution services.
	 */
	interface SubstitutionObjectServiceStub {
		/** @param int|string $id @param mixed ...$args @return mixed */
		public function find(int|string $id, ...$args): mixed;

		/** @param array<string,mixed> $query @return array<int,mixed>|int */
		public function searchObjects(array $query = []): array|int;

		/** @param string $r @param string $s @param array<string,mixed> $f @return array<int,mixed>|int */
		public function searchObjectsBySlug(string $r, string $s, array $f = []): array|int;

		/** @param mixed ...$args @return mixed */
		public function saveObject(...$args): mixed;

		/** @param mixed ...$args @return mixed */
		public function updateObject(...$args): mixed;
	}//end interface
}//end if

/**
 * Unit tests for SubstitutionAuditService.
 *
 * @covers \OCA\Procest\Service\SubstitutionAuditService
 */
class SubstitutionAuditServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var SubstitutionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $substitutionService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->substitutionService = $this->createMock(SubstitutionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'procest',
					'case_schema' => 'case',
					'substitution_schema' => 'substitution',
				];
				return ($map[$key] ?? $default);
			}
		);
	}//end setUp()

	/**
	 * Build a slug-aware ObjectService mock.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function objectServiceMock() {
		return $this->createMock(SubstitutionObjectServiceStub::class);
	}//end objectServiceMock()

	/**
	 * A substitute's action on the absentee's case is capacity-stamped.
	 *
	 * @return void
	 */
	public function testStampsSubstitutedAction(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturn(['id' => 'case-1', 'assignee' => 'jan', 'caseType' => 'objection', 'activity' => '[]']);
		$captured = null;
		$os->expects($this->once())->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) use (&$captured) {
				$captured = $payload;
				return $payload;
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($os);
		$this->substitutionService->method('resolveActingCapacity')->willReturn(['id' => 'sub-1']);

		$service = new SubstitutionAuditService($this->settingsService, $this->substitutionService, $this->logger);
		$entry = $service->stampIfSubstituted('case-1', 'marieke', 'task-completed');

		$this->assertNotNull($entry);
		$this->assertSame('jan', $entry['actedOnBehalfOf']);
		$this->assertSame('sub-1', $entry['substitutionId']);
		$activity = json_decode($captured['activity'], true);
		$this->assertSame('substitution-action', $activity[0]['type']);
	}//end testStampsSubstitutedAction()

	/**
	 * An action on the actor's own case is NOT stamped.
	 *
	 * @return void
	 */
	public function testOwnWorkNotStamped(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturn(['id' => 'case-2', 'assignee' => 'marieke', 'activity' => '[]']);
		$os->expects($this->never())->method('updateObject');
		$this->settingsService->method('getObjectService')->willReturn($os);

		$service = new SubstitutionAuditService($this->settingsService, $this->substitutionService, $this->logger);
		$this->assertNull($service->stampIfSubstituted('case-2', 'marieke', 'edit'));
	}//end testOwnWorkNotStamped()

	/**
	 * No covering substitution -> not stamped even on a third-party case.
	 *
	 * @return void
	 */
	public function testNoCapacityNotStamped(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturn(['id' => 'case-3', 'assignee' => 'jan', 'activity' => '[]']);
		$os->expects($this->never())->method('updateObject');
		$this->settingsService->method('getObjectService')->willReturn($os);
		$this->substitutionService->method('resolveActingCapacity')->willReturn(null);

		$service = new SubstitutionAuditService($this->settingsService, $this->substitutionService, $this->logger);
		$this->assertNull($service->stampIfSubstituted('case-3', 'marieke', 'edit'));
	}//end testNoCapacityNotStamped()

	/**
	 * Actions for a substitution are collected from the absentee's cases and
	 * sorted chronologically.
	 *
	 * @return void
	 */
	public function testGetActionsForSubstitution(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturn(['id' => 'sub-7', 'absentee' => 'jan']);
		$os->method('searchObjectsBySlug')->willReturn(
			[
				[
					'id' => 'case-a',
					'title' => 'Case A',
					'activity' => json_encode(
						[
							['type' => 'substitution-action', 'substitutionId' => 'sub-7', 'timestamp' => '2026-07-10T09:00:00+00:00', 'action' => 'a'],
							['type' => 'substitution-action', 'substitutionId' => 'other', 'timestamp' => '2026-07-11T09:00:00+00:00', 'action' => 'x'],
							['type' => 'substitution-action', 'substitutionId' => 'sub-7', 'timestamp' => '2026-07-05T09:00:00+00:00', 'action' => 'b'],
						]
					),
				],
			]
		);
		$this->settingsService->method('getObjectService')->willReturn($os);

		$service = new SubstitutionAuditService($this->settingsService, $this->substitutionService, $this->logger);
		$actions = $service->getActionsForSubstitution('sub-7');

		// Two matching entries, chronologically sorted (b before a).
		$this->assertCount(2, $actions);
		$this->assertSame('b', $actions[0]['action']);
		$this->assertSame('a', $actions[1]['action']);
		$this->assertSame('case-a', $actions[0]['caseId']);
	}//end testGetActionsForSubstitution()
}//end class
