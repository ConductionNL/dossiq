<?php

/**
 * CaseAttentionController wire contract.
 *
 * The two writes are the subject, because the whole of REQ-MRK-01 is that
 * neither act happens without a reason and neither is performed by somebody
 * who may not touch the case. `#[NoAdminRequired]` on its own would let any
 * signed-in user raise a flag on any case id they can guess and clear one on a
 * case they cannot otherwise reach, so the per-case guard is asked and fails
 * closed. These tests drive the least privileged caller that should be
 * refused rather than the one that should succeed: an anonymous caller, then
 * a signed-in user the guard says no to.
 *
 * 🔴 THE REFUSAL SLUG IS PART OF THE WIRE. A surface acts on the `code` and a
 * person reads the sentence, so a refusal that lost its slug on the way out
 * would leave the browser unable to tell "you wrote nothing" from "somebody
 * else got there first" and it would show the same toast for both. Each of the
 * four rules is held to its own status here, because a `reason_required`
 * answering 409 reads to a caller as a conflict they can retry past.
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
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseAttentionController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAttentionFlagService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raise, clear and read, behind their guards and with their slugs intact.
 *
 * @covers \OCA\Dossiq\Controller\CaseAttentionController
 */
class CaseAttentionControllerContractTest extends TestCase {

	/**
	 * The flag.
	 *
	 * @var CaseAttentionFlagService&MockObject
	 */
	private CaseAttentionFlagService $flags;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Wire a controller with a signed-in handler by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flags = $this->createMock(originalClassName: CaseAttentionFlagService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseAttentionController The controller.
	 */
	private function controller(): CaseAttentionController {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseAttentionController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			flags: $this->flags,
			caseAccessGuard: $this->guard,
			userSession: $this->userSession,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * One flag answer, as the service shapes it.
	 *
	 * @return array<string, mixed> The flag.
	 */
	private function flagState(): array {
		return [
			'caseId' => 'case-1',
			'raised' => true,
			'flag' => [
				'reason' => 'A neighbour called twice',
				'raisedBy' => 'behandelaar',
				'raisedAt' => '2026-03-01T09:00:00+00:00',
			],
			'history' => [
				[
					'act' => 'raised',
					'reason' => 'A neighbour called twice',
					'actor' => 'behandelaar',
					'moment' => '2026-03-01T09:00:00+00:00',
				],
			],
			'raisings' => 1,
			'clearings' => 0,
		];
	}//end flagState()

	/**
	 * An anonymous caller reads nothing and the service is never asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testStateRefusesAnAnonymousReader(): void {
		$anonymous = $this->createMock(originalClassName: IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$this->userSession = $anonymous;

		$this->flags->expects($this->never())->method('state');

		$response = $this->controller()->state(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testStateRefusesAnAnonymousReader()

	/**
	 * A reader gets the flag, its history and both counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testStateAnswersTheFlagAndItsHistory(): void {
		$this->flags->expects($this->once())
			->method('state')
			->with('case-1')
			->willReturn($this->flagState());

		$response = $this->controller()->state(caseId: 'case-1');
		$body = (array)$response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertTrue(condition: $body['raised']);
		$this->assertSame(expected: 1, actual: $body['raisings']);
		$this->assertCount(expectedCount: 1, haystack: $body['history']);
	}//end testStateAnswersTheFlagAndItsHistory()

	/**
	 * An anonymous caller raises nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testRaiseRefusesAnAnonymousCaller(): void {
		$anonymous = $this->createMock(originalClassName: IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$this->userSession = $anonymous;

		$this->guard->expects($this->never())->method('hasCaseMutationAccess');
		$this->flags->expects($this->never())->method('raise');

		$response = $this->controller()->raise(caseId: 'case-1', reason: 'anything');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testRaiseRefusesAnAnonymousCaller()

	/**
	 * A signed-in user the guard refuses raises nothing on somebody else's
	 * case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testRaiseRefusesAUserWithoutMutationAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->flags->expects($this->never())->method('raise');

		$response = $this->controller()->raise(caseId: 'case-1', reason: 'A neighbour called twice');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testRaiseRefusesAUserWithoutMutationAccess()

	/**
	 * The clearing is guarded exactly as the raising is.
	 *
	 * Written out rather than inferred: the clearing is the act somebody
	 * performs to make a nuisance go away, so it is the one a guard is most
	 * costly to forget on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testClearIsGuardedTheSameWay(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->flags->expects($this->never())->method('clear');

		$response = $this->controller()->clear(caseId: 'case-1', reason: 'It is handled');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testClearIsGuardedTheSameWay()

	/**
	 * The raising reaches the service with the caller's own name, never one
	 * the caller sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testRaiseStampsTheSignedInUser(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->flags->expects($this->once())
			->method('raise')
			->with('case-1', 'behandelaar', 'A neighbour called twice')
			->willReturn($this->flagState());

		$response = $this->controller()->raise(caseId: 'case-1', reason: 'A neighbour called twice');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testRaiseStampsTheSignedInUser()

	/**
	 * Every refusal keeps its slug, and each one answers its own status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testEachRefusalKeepsItsSlugAndItsStatus(): void {
		$expected = [
			'reason_required' => Http::STATUS_BAD_REQUEST,
			'already_raised' => Http::STATUS_CONFLICT,
			'case_not_found' => Http::STATUS_NOT_FOUND,
		];

		foreach ($expected as $code => $status) {
			$this->setUp();
			$this->guard->method('hasCaseMutationAccess')->willReturn(true);
			$this->flags->method('raise')->willThrowException(new RuntimeException($code));

			$response = $this->controller()->raise(caseId: 'case-1', reason: '');
			$body = (array)$response->getData();

			$this->assertSame(
				expected: $status,
				actual: $response->getStatus(),
				message: sprintf('%s must answer its own status', $code)
			);
			$this->assertSame(expected: $code, actual: $body['code']);
			$this->assertNotSame(expected: '', actual: (string)$body['error']);
		}
	}//end testEachRefusalKeepsItsSlugAndItsStatus()

	/**
	 * A clearing refused for having no reason says so, and says it with the
	 * slug the browser acts on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testClearWithoutAReasonAnswersReasonRequired(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->flags->method('clear')->willThrowException(new RuntimeException('reason_required'));

		$response = $this->controller()->clear(caseId: 'case-1', reason: '   ');
		$body = (array)$response->getData();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'reason_required', actual: $body['code']);
	}//end testClearWithoutAReasonAnswersReasonRequired()

	/**
	 * An unexpected failure is a 500 and never a plausible-looking refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnexpectedFailureIsNotDressedAsARefusal(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->flags->method('raise')->willThrowException(new \LogicException('the store fell over'));

		$response = $this->controller()->raise(caseId: 'case-1', reason: 'A neighbour called twice');
		$body = (array)$response->getData();

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertArrayNotHasKey(key: 'code', array: $body);
	}//end testAnUnexpectedFailureIsNotDressedAsARefusal()
}//end class
