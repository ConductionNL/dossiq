<?php

/**
 * CaseCustodyController wire-contract tests.
 *
 * Contract coverage for the two custody reads that had none (gate-25):
 * `holder` (who held one case on one date) and `unit` (what one unit held
 * between two dates). `chain` is exercised alongside them because the three
 * share one refusal and a shared refusal that differs by endpoint is a
 * disclosure.
 *
 * The contract pinned here:
 *
 *  - every refusal is the SAME 403 with the same `case-access-denied` code, on
 *    an anonymous caller and on a case the guard refuses alike. A 404 on one
 *    and a 403 on the other would tell a caller which case ids exist;
 *  - a missing `on` / `from` / `to` is a 400 with its own error code and NOT a
 *    silent whole-history answer. These are the literal query parameter names
 *    the wire uses, so reading a differently-named key would make every real
 *    call answer 400;
 *  - `unit` is scoped by GROUP membership, not by case access: a caller asks
 *    about a unit they are in, or they are an admin. That is a different
 *    question from the other two and it is asserted separately, including the
 *    admin arm, which is the one a tidy-up would drop.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseCustodyController;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseCustodyQuery;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for CaseCustodyController.
 *
 * @covers \OCA\Dossiq\Controller\CaseCustodyController
 * @uses \OCA\Dossiq\Service\CaseAccessGuard
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyChain
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyQuery
 */
class CaseCustodyControllerContractTest extends TestCase {

	/** The case every test asks about. */
	private const CASE_ID = 'case-7f3a';

	/** The organisation unit every unit test asks about. */
	private const UNIT = 'team-handhaving';

	/**
	 * Build the controller with every collaborator mocked.
	 *
	 * @param bool                 $authenticated Whether a user is in session.
	 * @param bool                 $mayRead       What CaseAccessGuard answers.
	 * @param array<string, mixed> $params        Query parameters on the request.
	 * @param array<int, string>   $groups        The caller's groups.
	 * @param bool                 $admin         Whether the caller is an admin.
	 *
	 * @return CaseCustodyController The controller.
	 */
	private function controller(
		bool $authenticated = true,
		bool $mayRead = true,
		array $params = [],
		array $groups = [self::UNIT],
		bool $admin = false,
	): CaseCustodyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($authenticated === true ? $user : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($mayRead);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn($admin);

		$chain = $this->createMock(CaseCustodyChain::class);
		$chain->method('holdings')->willReturn([['unit' => self::UNIT, 'from' => '2026-01-01']]);
		$chain->method('openHoldingFor')->willReturn(['unit' => self::UNIT, 'from' => '2026-01-01']);

		$query = $this->createMock(CaseCustodyQuery::class);
		$query->method('holderOn')->willReturn(['unit' => self::UNIT]);
		$query->method('heldBy')->willReturn([['caseId' => self::CASE_ID]]);

		return new CaseCustodyController(
			appName: 'dossiq',
			request: $request,
			chain: $chain,
			query: $query,
			accessGuard: $guard,
			groups: $groupManager,
			userSession: $session,
		);
	}//end controller()

	/**
	 * An anonymous caller is refused on all three, identically.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedOnEveryCustodyRead(): void {
		$controller = $this->controller(authenticated: false);

		foreach (
			[
				'chain' => $controller->chain(caseId: self::CASE_ID),
				'holder' => $controller->holder(caseId: self::CASE_ID),
				'unit' => $controller->unit(unit: self::UNIT),
			] as $name => $response
		) {
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $name . ' status');
			self::assertSame('case-access-denied', $response->getData()['error'], $name . ' code');
		}
	}//end testAnAnonymousCallerIsRefusedOnEveryCustodyRead()

	/**
	 * A case the guard refuses gets the same 403, not a 404.
	 *
	 * @return void
	 */
	public function testACaseTheGuardRefusesIsTheSameRefusalAsNoSession(): void {
		$controller = $this->controller(mayRead: false, params: ['on' => '2026-03-01']);

		$response = $controller->holder(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testACaseTheGuardRefusesIsTheSameRefusalAsNoSession()

	/**
	 * `holder` without a date is a 400, not the whole history.
	 *
	 * @return void
	 */
	public function testHolderWithoutADateRefusesRatherThanAnsweringTheWholeChain(): void {
		$response = $this->controller()->holder(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('custody-date-missing', $response->getData()['error']);
	}//end testHolderWithoutADateRefusesRatherThanAnsweringTheWholeChain()

	/**
	 * `holder` answers the holding for the date it was given, echoing the date.
	 *
	 * @return void
	 */
	public function testHolderAnswersTheHoldingForTheDateAsked(): void {
		$response = $this->controller(params: ['on' => '2026-03-01'])
			->holder(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(self::CASE_ID, $data['caseId']);
		// ECHOED, not silently normalised: a client that asked about 1 March
		// and is answered about some other date cannot tell.
		self::assertSame('2026-03-01', $data['on']);
		self::assertSame(['unit' => self::UNIT], $data['holding']);
	}//end testHolderAnswersTheHoldingForTheDateAsked()

	/**
	 * `unit` refuses a unit the caller is not in and is not admin over.
	 *
	 * @return void
	 */
	public function testUnitRefusesAUnitTheCallerIsNotIn(): void {
		$response = $this->controller(
			params: ['from' => '2026-01-01', 'to' => '2026-06-30'],
			groups: ['team-vergunningen'],
		)->unit(unit: self::UNIT);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testUnitRefusesAUnitTheCallerIsNotIn()

	/**
	 * An admin reads a unit they are not a member of.
	 *
	 * @return void
	 */
	public function testAnAdminReadsAUnitTheyAreNotAMemberOf(): void {
		$response = $this->controller(
			params: ['from' => '2026-01-01', 'to' => '2026-06-30'],
			groups: ['team-vergunningen'],
			admin: true,
		)->unit(unit: self::UNIT);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()['total']);
	}//end testAnAdminReadsAUnitTheyAreNotAMemberOf()

	/**
	 * `unit` without a period is a 400, not every holding the unit ever had.
	 *
	 * @return void
	 */
	public function testUnitWithoutAPeriodRefusesRatherThanAnsweringEverything(): void {
		$response = $this->controller(params: ['from' => '2026-01-01'])
			->unit(unit: self::UNIT);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('custody-period-missing', $response->getData()['error']);
	}//end testUnitWithoutAPeriodRefusesRatherThanAnsweringEverything()

	/**
	 * `chain` answers the holdings with a count that matches them.
	 *
	 * @return void
	 */
	public function testChainAnswersItsHoldingsWithAMatchingTotal(): void {
		$response = $this->controller()->chain(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount($data['total'], $data['holdings']);
	}//end testChainAnswersItsHoldingsWithAMatchingTotal()
}//end class
