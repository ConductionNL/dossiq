<?php

/**
 * DemoCaseloadReport Unit Tests
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\DemoCaseloadGateway;
use OCA\Dossiq\Service\DemoCaseloadReport;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The caseload buckets count closed and open cases, split the open ones by a
 * deadline in the past or within three days, and count the actor's open and
 * soon-due engine tasks.
 *
 * @covers \OCA\Dossiq\Service\DemoCaseloadReport
 */
class DemoCaseloadReportTest extends TestCase {

	/**
	 * The mocked gateway.
	 *
	 * @var DemoCaseloadGateway&MockObject
	 */
	private DemoCaseloadGateway $gateway;

	/**
	 * The mocked engine task inbox.
	 *
	 * @var EngineTaskInbox&MockObject
	 */
	private EngineTaskInbox $tasks;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The object service the gateway hands out.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new \stdClass();
		$this->gateway       = $this->createMock(DemoCaseloadGateway::class);
		$this->tasks         = $this->createMock(EngineTaskInbox::class);
		$this->session       = $this->createMock(IUserSession::class);
		$this->gateway->method('objectService')->willReturn($this->objectService);
		$this->gateway->method('schemaIds')->willReturn(['register' => '3', 'case' => '12']);
		$this->gateway->method('toArray')->willReturnArgument(0);
	}//end setUp()

	/**
	 * The report under test.
	 *
	 * @return DemoCaseloadReport The report.
	 */
	private function report(): DemoCaseloadReport {
		return new DemoCaseloadReport(gateway: $this->gateway, engineTasks: $this->tasks, userSession: $this->session);
	}//end report()

	/**
	 * Cases are bucketed against today and a three-day horizon, both inclusive.
	 *
	 * @return void
	 */
	public function testCasesAreBucketedByStatusAndDeadline(): void {
		$this->gateway->expects($this->once())->method('findMany')->with(
			$this->identicalTo($this->objectService),
			'3',
			'12',
			[]
		)->willReturn(
			[
				['isFinalStatus' => true, 'deadline' => '2026-01-01'],
				['deadline' => '2026-03-09T23:00:00+00:00'],
				['deadline' => '2026-03-10'],
				['deadline' => '2026-03-13T08:00:00+00:00'],
				['deadline' => '2026-03-14'],
				['deadline' => ''],
				['isFinalStatus' => 'yes'],
			]
		);
		$this->tasks->method('countOpenEverywhere')->willReturn(0);

		$buckets = $this->report()->buckets(now: new DateTimeImmutable('2026-03-10'));

		$this->assertSame(
			['open' => 6, 'overdue' => 1, 'dueSoon' => 2, 'closed' => 1, 'tasksOpen' => 0, 'tasksDue' => 0],
			$buckets
		);
	}//end testCasesAreBucketedByStatusAndDeadline()

	/**
	 * Tasks are counted for the signed-in user, the due ones up to the end of the horizon day.
	 *
	 * @return void
	 */
	public function testTasksAreCountedForTheSignedInUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->gateway->method('findMany')->willReturn([]);
		$this->tasks->expects($this->exactly(2))->method('countOpenEverywhere')->willReturnCallback(
			fn (string $actor, ?string $dueBefore = null): int => match ([$actor, $dueBefore]) {
				['alice', null] => 7,
				['alice', '2026-03-13T23:59:59+00:00'] => 2,
			}
		);

		$buckets = $this->report()->buckets(now: new DateTimeImmutable('2026-03-10'));

		$this->assertSame(7, $buckets['tasksOpen']);
		$this->assertSame(2, $buckets['tasksDue']);
		$this->assertSame(0, $buckets['open']);
	}//end testTasksAreCountedForTheSignedInUser()

	/**
	 * Without a signed-in user the tasks are counted for the empty actor.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheActorIsEmpty(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->gateway->method('findMany')->willReturn([]);
		$actors = [];
		$this->tasks->method('countOpenEverywhere')->willReturnCallback(
			function (string $actor) use (&$actors): int {
				$actors[] = $actor;
				return 1;
			}
		);

		$buckets = $this->report()->buckets(now: new DateTimeImmutable('2026-03-10'));

		$this->assertSame(['', ''], $actors);
		$this->assertSame(1, $buckets['tasksOpen']);
	}//end testWithoutAUserTheActorIsEmpty()

	/**
	 * Without an explicit moment the report measures against today.
	 *
	 * @return void
	 */
	public function testDefaultsToToday(): void {
		$today = new DateTimeImmutable('today');
		$this->gateway->method('findMany')->willReturn(
			[
				['deadline' => $today->modify('-1 day')->format('Y-m-d')],
				['deadline' => $today->format('Y-m-d')],
			]
		);
		$this->tasks->method('countOpenEverywhere')->willReturn(0);

		$buckets = $this->report()->buckets();

		$this->assertSame(1, $buckets['overdue']);
		$this->assertSame(1, $buckets['dueSoon']);
	}//end testDefaultsToToday()
}//end class
