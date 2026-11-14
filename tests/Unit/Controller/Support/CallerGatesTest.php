<?php

/**
 * Caller Gates Test
 *
 * Pins the controller support gates that decide who may call: the
 * signed-in check, the case reader and writer checks, the complaint mutation
 * gate and the reporting audience guard. Each one refuses with its own
 * status and body, and lets the caller through only when every check passes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\CaseCaller;
use OCA\Dossiq\Controller\Support\ComplaintMutationGate;
use OCA\Dossiq\Controller\Support\ReportingAudienceGuard;
use OCA\Dossiq\Controller\Support\SignedIn;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\ComplaintService;
use OCA\Dossiq\Service\Reporting\ReportingAudience;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the caller gates.
 */
#[CoversClass(SignedIn::class)]
#[CoversClass(CaseCaller::class)]
#[CoversClass(ComplaintMutationGate::class)]
#[UsesClass(\OCA\Dossiq\Controller\DeadlineNoticesController::class)]
final class CallerGatesTest extends TestCase {
	/**
	 * A session with the given user, or none.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return IUserSession
	 */
	private function session(?IUser $user): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Without a user the signed-in check refuses with 401; with one it passes.
	 *
	 * @return void
	 */
	public function testSignedInRefusesAnonymousCallers(): void {
		$refusal = SignedIn::refusal(session: $this->session(user: null));

		self::assertInstanceOf(expected: JSONResponse::class, actual: $refusal);
		self::assertSame(expected: 401, actual: $refusal->getStatus());
		self::assertSame(expected: ['error' => 'Authentication required'], actual: $refusal->getData());
		self::assertNull(actual: SignedIn::refusal(session: $this->session(user: $this->createMock(IUser::class))));
	}//end testSignedInRefusesAnonymousCallers()

	/**
	 * A reader is the signed-in user with read access; the mutation check is not used for it.
	 *
	 * @return void
	 */
	public function testReaderNeedsReadAccess(): void {
		$user  = $this->createMock(IUser::class);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->expects(self::exactly(2))
			->method('hasCaseReadAccess')
			->willReturnCallback(static fn (string $caseId, IUser $who): bool => ($caseId === 'case-1' && $who === $user));
		$guard->expects(self::never())->method('hasCaseMutationAccess');
		$caller = new CaseCaller(userSession: $this->session(user: $user), accessGuard: $guard);

		self::assertSame(expected: $user, actual: $caller->reader(caseId: 'case-1'));
		self::assertNull(actual: $caller->reader(caseId: 'case-2'));
	}//end testReaderNeedsReadAccess()

	/**
	 * A writer is the signed-in user with mutation access; read access alone is not enough.
	 *
	 * @return void
	 */
	public function testWriterNeedsMutationAccess(): void {
		$user  = $this->createMock(IUser::class);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn(true);
		$guard->expects(self::exactly(2))
			->method('hasCaseMutationAccess')
			->willReturnCallback(static fn (string $caseId, IUser $who): bool => ($caseId === 'case-1'));
		$caller = new CaseCaller(userSession: $this->session(user: $user), accessGuard: $guard);

		self::assertSame(expected: $user, actual: $caller->writer(caseId: 'case-1'));
		self::assertNull(actual: $caller->writer(caseId: 'case-2'));
	}//end testWriterNeedsMutationAccess()

	/**
	 * Without a signed-in user there is no reader or writer, and the guard is not asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsNeitherReaderNorWriter(): void {
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->expects(self::never())->method('hasCaseReadAccess');
		$guard->expects(self::never())->method('hasCaseMutationAccess');
		$caller = new CaseCaller(userSession: $this->session(user: null), accessGuard: $guard);

		self::assertNull(actual: $caller->reader(caseId: 'case-1'));
		self::assertNull(actual: $caller->writer(caseId: 'case-1'));
	}//end testAnonymousCallerIsNeitherReaderNorWriter()

	/**
	 * The refusal for a caller without access is a 403 naming the case access rule.
	 *
	 * @return void
	 */
	public function testNotYoursIsAForbiddenNamingTheRule(): void {
		$caller  = new CaseCaller(userSession: $this->session(user: null), accessGuard: $this->createMock(CaseAccessGuard::class));
		$refusal = $caller->notYours();

		self::assertSame(expected: 403, actual: $refusal->getStatus());
		self::assertSame(expected: ['message' => 'You cannot do that on this case.', 'error' => 'case-access-denied'], actual: $refusal->getData());
	}//end testNotYoursIsAForbiddenNamingTheRule()

	/**
	 * An anonymous complaint mutation is refused with the guard's own response, before any lookup.
	 *
	 * @return void
	 */
	public function testComplaintMutationRefusesAnonymousCallers(): void {
		$notAuthenticated = new JSONResponse(['error' => 'Not authenticated'], 401);
		$guard            = $this->createMock(ComplaintAccessGuard::class);
		$guard->method('currentUid')->willReturn('');
		$guard->method('notAuthenticated')->willReturn($notAuthenticated);
		$guard->expects(self::never())->method('authorizeMutation');
		$complaints = $this->createMock(ComplaintService::class);
		$complaints->expects(self::never())->method('getComplaint');

		$gate = new ComplaintMutationGate(accessGuard: $guard, complaintService: $complaints);

		self::assertSame(expected: $notAuthenticated, actual: $gate->refusal(id: 'c-1'));
	}//end testComplaintMutationRefusesAnonymousCallers()

	/**
	 * A mutation on a complaint that does not exist is a 404, and nothing is authorised.
	 *
	 * @return void
	 */
	public function testComplaintMutationOnAMissingComplaintIsNotFound(): void {
		$guard = $this->createMock(ComplaintAccessGuard::class);
		$guard->method('currentUid')->willReturn('alice');
		$guard->expects(self::never())->method('authorizeMutation');
		$complaints = $this->createMock(ComplaintService::class);
		$complaints->expects(self::once())->method('getComplaint')->with('c-404')->willReturn(null);

		$refusal = (new ComplaintMutationGate(accessGuard: $guard, complaintService: $complaints))->refusal(id: 'c-404');

		self::assertSame(expected: 404, actual: $refusal->getStatus());
		self::assertSame(expected: ['error' => 'Complaint not found'], actual: $refusal->getData());
	}//end testComplaintMutationOnAMissingComplaintIsNotFound()

	/**
	 * An existing complaint is authorised for the caller, and an authorised mutation passes.
	 *
	 * @return void
	 */
	public function testComplaintMutationIsAuthorisedForTheCaller(): void {
		$complaint = ['id' => 'c-1', 'handler' => 'alice'];
		$guard     = $this->createMock(ComplaintAccessGuard::class);
		$guard->method('currentUid')->willReturn('alice');
		$guard->expects(self::once())->method('authorizeMutation')->with($complaint, 'alice');
		$complaints = $this->createMock(ComplaintService::class);
		$complaints->method('getComplaint')->willReturn($complaint);

		self::assertNull(actual: (new ComplaintMutationGate(accessGuard: $guard, complaintService: $complaints))->refusal(id: 'c-1'));
	}//end testComplaintMutationIsAuthorisedForTheCaller()

	/**
	 * A refused authorisation is not swallowed: it reaches the controller.
	 *
	 * @return void
	 */
	public function testRefusedComplaintAuthorisationPropagates(): void {
		$refused = new RuntimeException(message: 'not your complaint');
		$guard   = $this->createMock(ComplaintAccessGuard::class);
		$guard->method('currentUid')->willReturn('bob');
		$guard->method('authorizeMutation')->willThrowException($refused);
		$complaints = $this->createMock(ComplaintService::class);
		$complaints->method('getComplaint')->willReturn(['id' => 'c-1']);

		$this->expectExceptionObject($refused);
		(new ComplaintMutationGate(accessGuard: $guard, complaintService: $complaints))->refusal(id: 'c-1');
	}//end testRefusedComplaintAuthorisationPropagates()

	/**
	 * The reporting audience guard refuses anonymous callers and callers outside the audience with 403.
	 *
	 * @return void
	 */
	public function testReportingAudienceGuard(): void {
		$insider  = $this->createMock(IUser::class);
		$outsider = $this->createMock(IUser::class);
		$audience = $this->createMock(ReportingAudience::class);
		$audience->method('isInAudience')->willReturnCallback(static fn (?IUser $user): bool => ($user === $insider));
		$guarded = new class {
			use ReportingAudienceGuard;

			/**
			 * Expose the guard.
			 *
			 * @param IUserSession      $session  The session.
			 * @param ReportingAudience $audience The audience.
			 *
			 * @return JSONResponse|null
			 */
			public function check(IUserSession $session, ReportingAudience $audience): ?JSONResponse {
				return $this->refuseOutsideReportingAudience(userSession: $session, audience: $audience);
			}
		};

		$anonymous = $guarded->check(session: $this->session(user: null), audience: $audience);
		$outside   = $guarded->check(session: $this->session(user: $outsider), audience: $audience);

		self::assertSame(expected: [403, ['message' => 'Not authenticated']], actual: [$anonymous->getStatus(), $anonymous->getData()]);
		self::assertSame(expected: [403, ['message' => ReportingAudience::REFUSAL]], actual: [$outside->getStatus(), $outside->getData()]);
		self::assertNull(actual: $guarded->check(session: $this->session(user: $insider), audience: $audience));
	}//end testReportingAudienceGuard()
}//end class
