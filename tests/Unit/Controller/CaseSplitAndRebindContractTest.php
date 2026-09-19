<?php

/**
 * Wire-contract tests for the two split and rebind reads that had none.
 *
 * Contract coverage (gate-25) for `caseSplit#divisible` and
 * `caseRebind#permission`. Both are the question a dialog asks BEFORE it
 * offers anything, which is what makes them worth pinning: an endpoint that
 * answers "yes" too easily does not fail, it opens a door.
 *
 * The contract pinned here:
 *
 *  - `divisible` is scoped by MUTATION access, not read access. A handler who
 *    may read a case but not write it must not be told what they could move
 *    out of it, and the refusal is a 403 with `case-access-denied`;
 *  - `permission` answers `{mayRebind: bool}` and answers it for the SESSION
 *    user. An anonymous caller gets `false` rather than a 401, because the
 *    dialog asks this to decide whether to draw a button and a 401 there would
 *    read as a broken page rather than a closed door. That is a deliberate
 *    asymmetry with the rest of the app and it is asserted so a tidy-up to 401
 *    is a visible change;
 *  - `permission` passes the caller's uid through to the service. A method
 *    that asked about the empty string for everyone would answer the same
 *    plausible `false` for every real user and nothing would notice.
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

use OCA\Dossiq\Controller\CaseRebindController;
use OCA\Dossiq\Controller\CaseSplitController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseRebindService;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for the split and rebind preflight reads.
 *
 * @covers \OCA\Dossiq\Controller\CaseSplitController
 * @covers \OCA\Dossiq\Controller\CaseRebindController
 */
class CaseSplitAndRebindContractTest extends TestCase {

	/** The case every split test asks about. */
	private const CASE_ID = 'case-4c81';

	/**
	 * A user session holding this uid, or holding nobody.
	 *
	 * @param string|null $uid The uid, or null for an anonymous caller.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The split controller over a guard with this verdict.
	 *
	 * @param bool                  $mayMutate What CaseAccessGuard answers.
	 * @param array<int, string>    $parts     What the executor says may be divided.
	 * @param IUserSession|null     $session   The session, or a default handler.
	 *
	 * @return CaseSplitController The controller.
	 */
	private function splitController(
		bool $mayMutate = true,
		array $parts = ['documents', 'parties'],
		?IUserSession $session = null,
	): CaseSplitController {
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayMutate);

		$executor = $this->createMock(CaseSplitExecutor::class);
		$executor->method('divisibleParts')->willReturn($parts);

		return new CaseSplitController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			splits: $executor,
			accessGuard: $guard,
			userSession: ($session ?? $this->session('behandelaar')),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end splitController()

	/**
	 * A caller who may not write the case is not told what it could be split into.
	 *
	 * @return void
	 */
	public function testDivisibleRefusesACallerWhoMayNotWriteTheCase(): void {
		$response = $this->splitController(mayMutate: false)->divisible(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testDivisibleRefusesACallerWhoMayNotWriteTheCase()

	/**
	 * An anonymous caller gets the same refusal, not a different one.
	 *
	 * @return void
	 */
	public function testDivisibleRefusesAnAnonymousCallerTheSameWay(): void {
		$response = $this->splitController(session: $this->session(null))
			->divisible(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testDivisibleRefusesAnAnonymousCallerTheSameWay()

	/**
	 * A writer is told which parts the case type allows, under `allowed`.
	 *
	 * @return void
	 */
	public function testDivisibleAnswersTheAllowedPartsToAWriter(): void {
		$response = $this->splitController()->divisible(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['documents', 'parties'], $response->getData()['allowed']);
	}//end testDivisibleAnswersTheAllowedPartsToAWriter()

	/**
	 * A case type that forbids everything answers an empty list, not an error.
	 *
	 * An empty list and a refusal are different facts: nothing may be divided
	 * here, versus you may not ask. The picker renders them differently.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDividesNothingAnswersAnEmptyListNotARefusal(): void {
		$response = $this->splitController(parts: [])->divisible(caseId: self::CASE_ID);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['allowed']);
	}//end testACaseTypeThatDividesNothingAnswersAnEmptyListNotARefusal()

	/**
	 * The rebind controller over a service with this verdict.
	 *
	 * @param bool         $mayRebind What CaseRebindService answers.
	 * @param string|null  $uid       The session uid, or null.
	 * @param list<string> &$asked    Collects the uid the service was asked about.
	 *
	 * @return CaseRebindController The controller.
	 */
	private function rebindController(bool $mayRebind, ?string $uid, array &$asked): CaseRebindController {
		$service = $this->createMock(CaseRebindService::class);
		$service->method('mayRebind')->willReturnCallback(
			static function (string $uid) use ($mayRebind, &$asked): bool {
				$asked[] = $uid;
				return $mayRebind;
			}
		);

		return new CaseRebindController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			rebind: $service,
			accessGuard: $this->createMock(CaseAccessGuard::class),
			userSession: $this->session($uid),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end rebindController()

	/**
	 * A coordinator is told they may rebind, and the service was asked about them.
	 *
	 * @return void
	 */
	public function testPermissionAsksTheServiceAboutTheSessionUser(): void {
		$asked = [];
		$response = $this->rebindController(mayRebind: true, uid: 'coordinator', asked: $asked)
			->permission();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['mayRebind']);
		// THE UID, not the empty string. A method that asked about nobody would
		// answer a plausible false for every real user and never be noticed.
		self::assertSame(['coordinator'], $asked);
	}//end testPermissionAsksTheServiceAboutTheSessionUser()

	/**
	 * A handler without the right is told so, rather than refused.
	 *
	 * @return void
	 */
	public function testPermissionAnswersFalseRatherThanRefusing(): void {
		$asked = [];
		$response = $this->rebindController(mayRebind: false, uid: 'behandelaar', asked: $asked)
			->permission();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['mayRebind']);
	}//end testPermissionAnswersFalseRatherThanRefusing()

	/**
	 * An anonymous caller is answered `false`, not 401.
	 *
	 * The dialog asks this to decide whether to draw a button. A 401 here reads
	 * as a broken page rather than a closed door, so the answer is a verdict.
	 *
	 * @return void
	 */
	public function testPermissionAnswersAnAnonymousCallerWithFalseAndNotAStatusCode(): void {
		$asked = [];
		$response = $this->rebindController(mayRebind: false, uid: null, asked: $asked)
			->permission();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['mayRebind']);
		self::assertSame([''], $asked, 'nobody in session is asked about as the empty uid');
	}//end testPermissionAnswersAnAnonymousCallerWithFalseAndNotAStatusCode()
}//end class
