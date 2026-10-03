<?php

/**
 * Retiring a case type and bringing it back, both recorded.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState;
use OCA\Dossiq\Service\Starter\CaseTypeRetirementService;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaseTypeRetirementService.
 *
 * @covers \OCA\Dossiq\Service\Starter\CaseTypeRetirementService
 *
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class CaseTypeRetirementServiceTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test.
	 *
	 * @var CaseTypeRetirementService
	 */
	private CaseTypeRetirementService $retirement;

	/**
	 * One published case type with a running case, and one draft.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->retirement = new CaseTypeRetirementService(
			$this->harness->store,
			new CaseTypeLifecycleState(),
			$session,
			new NullLogger(),
		);

		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-regeling',
			row: ['id' => 'ct-regeling', 'title' => 'Subsidieregeling 2024', 'isDraft' => false],
		);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-draft',
			row: ['id' => 'ct-draft', 'title' => 'Nieuw zaaktype', 'isDraft' => true],
		);
	}//end setUp()

	/**
	 * A regeling that ended stops taking new cases, and the running ones stay
	 * exactly where they were.
	 *
	 * 🔑 THE CASES ARE THE POINT. Retirement exists because deleting was the
	 * only option and `case-delete-guard` refuses it, so the running cases must
	 * come out of this untouched.
	 *
	 * @return void
	 */
	public function testRetiringStopsNewCasesAndLeavesTheRunningOnes(): void {
		$this->harness->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['id' => 'case-1', 'caseType' => 'ct-regeling', 'title' => 'Aanvraag Jansen'],
		);

		$result = $this->retirement->retire(caseTypeId: 'ct-regeling');

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: CaseTypeLifecycleState::RETIRED, actual: $result['state']);

		$state = new CaseTypeLifecycleState();
		$caseType = $this->harness->register->row(schema: 'caseType', uuid: 'ct-regeling');

		self::assertFalse(condition: $state->acceptsNewCases(caseType: $caseType));
		self::assertSame(
			expected: 'Aanvraag Jansen',
			actual: $this->harness->register->row(schema: 'case', uuid: 'case-1')['title']
		);
	}//end testRetiringStopsNewCasesAndLeavesTheRunningOnes()

	/**
	 * Retirement names who did it and when.
	 *
	 * @return void
	 */
	public function testRetirementIsRecordedWithWhoAndWhen(): void {
		$this->retirement->retire(caseTypeId: 'ct-regeling');

		$caseType = $this->harness->register->row(schema: 'caseType', uuid: 'ct-regeling');

		self::assertSame(expected: 'retired', actual: $caseType['lifecycleAct']);
		self::assertSame(expected: 'noor', actual: $caseType['lifecycleActBy']);
		self::assertNotSame(expected: '', actual: (string)$caseType['lifecycleActAt']);
	}//end testRetirementIsRecordedWithWhoAndWhen()

	/**
	 * A retired case type comes back, and the restoration names who did it.
	 *
	 * @return void
	 */
	public function testARetiredCaseTypeComesBack(): void {
		$this->retirement->retire(caseTypeId: 'ct-regeling');

		$result = $this->retirement->restore(caseTypeId: 'ct-regeling');

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: CaseTypeLifecycleState::IN_USE, actual: $result['state']);

		$caseType = $this->harness->register->row(schema: 'caseType', uuid: 'ct-regeling');

		self::assertSame(expected: 'restored', actual: $caseType['lifecycleAct']);
		self::assertSame(expected: 'noor', actual: $caseType['lifecycleActBy']);
	}//end testARetiredCaseTypeComesBack()

	/**
	 * A start date in the future is cleared too, so "offer it again" means it.
	 *
	 * Clearing only the end date would leave the type exactly as retired as it
	 * was, and the button would appear to do nothing.
	 *
	 * @return void
	 */
	public function testRestoringClearsAStartDateThatHasNotArrived(): void {
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-future',
			row: ['id' => 'ct-future', 'title' => 'Regeling 2030', 'isDraft' => false, 'validFrom' => '2030-01-01'],
		);

		$result = $this->retirement->restore(caseTypeId: 'ct-future');

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: CaseTypeLifecycleState::IN_USE, actual: $result['state']);
	}//end testRestoringClearsAStartDateThatHasNotArrived()

	/**
	 * A draft is not retired, because it takes no cases already and the act
	 * would read as one that was performed.
	 *
	 * @return void
	 */
	public function testADraftIsNotRetired(): void {
		$result = $this->retirement->retire(caseTypeId: 'ct-draft');

		self::assertFalse(condition: $result['ok']);
		self::assertSame(expected: 'is_draft', actual: $result['reason']);
	}//end testADraftIsNotRetired()

	/**
	 * Retiring twice is refused rather than recorded twice.
	 *
	 * @return void
	 */
	public function testRetiringARetiredTypeIsRefused(): void {
		$this->retirement->retire(caseTypeId: 'ct-regeling');

		$again = $this->retirement->retire(caseTypeId: 'ct-regeling');

		self::assertFalse(condition: $again['ok']);
		self::assertSame(expected: 'already_retired', actual: $again['reason']);
	}//end testRetiringARetiredTypeIsRefused()

	/**
	 * A case type that is not there is a 404, not a silent success.
	 *
	 * @return void
	 */
	public function testRetiringACaseTypeThatIsNotThereIsRefused(): void {
		$result = $this->retirement->retire(caseTypeId: 'ct-nowhere');

		self::assertFalse(condition: $result['ok']);
		self::assertSame(expected: 'not_found', actual: $result['reason']);
	}//end testRetiringACaseTypeThatIsNotThereIsRefused()
}//end class
