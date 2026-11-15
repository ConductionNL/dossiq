<?php

/**
 * Who may read or change a family plan, its goals and its records: the case decides.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\SociaalDomein\FamilyPlanAccess
 */
class FamilyPlanAccessTest extends TestCase {

	/**
	 * Rows per "schema/id".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Case ids the caller may read.
	 *
	 * @var array<int, string>
	 */
	private array $readable = [];

	/**
	 * Case ids the caller may change.
	 *
	 * @var array<int, string>
	 */
	private array $writable = [];

	/**
	 * Whether someone is signed in.
	 *
	 * @var bool
	 */
	private bool $signedIn = true;

	/**
	 * The access check over the rows and grants of this test.
	 *
	 * @return FamilyPlanAccess The service.
	 */
	private function access(): FamilyPlanAccess {
		$store = $this->createMock(originalClassName: SociaalDomeinStore::class);
		$store->method('read')->willReturnCallback(
			fn (string $schema, string $id): ?array => ($this->rows[$schema . '/' . $id] ?? null)
		);

		$user = null;
		if ($this->signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		/** @var CaseAccessGuard&MockObject $guard */
		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			fn (string $caseId, IUser $user): bool => in_array($caseId, $this->readable, true)
		);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			fn (string $caseId, IUser $user): bool => in_array($caseId, $this->writable, true)
		);

		return new FamilyPlanAccess(store: $store, caseAccessGuard: $guard, userSession: $session);
	}//end access()

	/**
	 * Plan `p1` on case `c1`, goal `g1` on that plan, and a record `r1` on case `c2`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rows = [
			'gezinsplan/p1' => ['caseId' => ' c1 '],
			'gezinsplan/orphan' => ['caseId' => ''],
			'casePlanGoal/g1' => ['plan' => 'p1'],
			'casePlanGoal/g-direct' => ['case' => 'c2', 'plan' => 'p1'],
			'intervention/r1' => ['case' => 'c2'],
			'intervention/r-plan' => ['plan' => 'p1'],
		];
	}//end setUp()

	/**
	 * The signed-in uid, or '' without a session.
	 *
	 * @return void
	 */
	public function testCurrentUid(): void {
		$this->assertSame('alice', $this->access()->currentUid());

		$this->signedIn = false;
		$this->assertSame('', $this->access()->currentUid());
	}//end testCurrentUid()

	/**
	 * Reading a plan asks for read access on its case.
	 *
	 * @return void
	 */
	public function testPlanReadFollowsTheCase(): void {
		$this->assertSame(403, $this->access()->planRead(planId: 'p1'));

		$this->readable = ['c1'];
		$this->assertSame(200, $this->access()->planRead(planId: 'p1'));
		$this->assertSame(404, $this->access()->planRead(planId: 'missing'));
		$this->assertSame(403, $this->access()->planRead(planId: 'orphan'));
	}//end testPlanReadFollowsTheCase()

	/**
	 * Read access alone does not let anyone change a plan.
	 *
	 * @return void
	 */
	public function testPlanWriteNeedsMutationAccess(): void {
		$this->readable = ['c1'];
		$this->assertSame(403, $this->access()->planWrite(planId: 'p1'));

		$this->writable = ['c1'];
		$this->assertSame(200, $this->access()->planWrite(planId: 'p1'));
		$this->assertSame(404, $this->access()->planWrite(planId: 'missing'));
	}//end testPlanWriteNeedsMutationAccess()

	/**
	 * A record named with the plan must also be writable on its own case.
	 *
	 * @return void
	 */
	public function testARecordOnAnotherCaseIsCheckedToo(): void {
		$this->writable = ['c1'];
		$access = $this->access();

		$this->assertSame(403, $access->planWrite(planId: 'p1', recordSchema: 'intervention', recordId: 'r1'));
		$this->assertSame(200, $access->planWrite(planId: 'p1', recordSchema: 'intervention', recordId: 'r-plan'));
		$this->assertSame(200, $access->planWrite(planId: 'p1', recordSchema: 'intervention', recordId: 'gone'));
		$this->assertSame(200, $access->planWrite(planId: 'p1', recordSchema: 'intervention'));

		$this->writable = ['c1', 'c2'];
		$this->assertSame(200, $this->access()->planWrite(planId: 'p1', recordSchema: 'intervention', recordId: 'r1'));
	}//end testARecordOnAnotherCaseIsCheckedToo()

	/**
	 * A refused plan never reaches the record check.
	 *
	 * @return void
	 */
	public function testARefusedPlanStopsBeforeTheRecord(): void {
		$this->writable = ['c2'];

		$this->assertSame(403, $this->access()->planWrite(planId: 'p1', recordSchema: 'intervention', recordId: 'r1'));
	}//end testARefusedPlanStopsBeforeTheRecord()

	/**
	 * A goal is written under its own case, else its plan's case.
	 *
	 * @return void
	 */
	public function testGoalWriteFollowsTheGoalsCase(): void {
		$this->writable = ['c1'];
		$this->assertSame(200, $this->access()->goalWrite(goalId: 'g1'));
		$this->assertSame(403, $this->access()->goalWrite(goalId: 'g-direct'));
		$this->assertSame(404, $this->access()->goalWrite(goalId: 'missing'));
	}//end testGoalWriteFollowsTheGoalsCase()

	/**
	 * A goal must belong to the plan it is named with.
	 *
	 * @return void
	 */
	public function testGoalOnPlan(): void {
		$access = $this->access();

		$this->assertSame(200, $access->goalOnPlan(planId: 'p1', goalId: 'g1'));
		$this->assertSame(200, $access->goalOnPlan(planId: 'p1', goalId: ''));
		$this->assertSame(404, $access->goalOnPlan(planId: 'other', goalId: 'g1'));
		$this->assertSame(404, $access->goalOnPlan(planId: 'p1', goalId: 'missing'));
	}//end testGoalOnPlan()

	/**
	 * Reading a case trims its id; an empty id is refused; no session is 401.
	 *
	 * @return void
	 */
	public function testCaseRead(): void {
		$this->readable = ['c1'];
		$this->assertSame(200, $this->access()->caseRead(caseId: ' c1 '));
		$this->assertSame(403, $this->access()->caseRead(caseId: 'c9'));
		$this->assertSame(403, $this->access()->caseRead(caseId: '  '));

		$this->signedIn = false;
		$this->assertSame(401, $this->access()->caseRead(caseId: 'c1'));
		$this->assertSame(401, $this->access()->planWrite(planId: 'p1'));
	}//end testCaseRead()
}//end class
