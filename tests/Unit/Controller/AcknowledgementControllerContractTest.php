<?php

/**
 * AcknowledgementController wire contract.
 *
 * Both endpoints answer a statutory question, so the guards matter more than
 * the payload. `#[NoAdminRequired]` on its own would let any signed-in user
 * read the Awb 4:3a duty on any case id they can guess, and clear it on any
 * case they cannot otherwise touch. The guard is asked per case and fails
 * closed, and these tests hold it to that by driving the least privileged
 * caller that should be refused: a signed-in user the guard says no to.
 *
 * The refusal path is the second subject. A rule slug is what a surface acts
 * on and a sentence is what a person reads, so neither may be replaced by an
 * exception message on its way out.
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AcknowledgementController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\CaseAccessGuard;
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
 * The duty read and the manual confirmation, behind their guards.
 *
 * @covers \OCA\Dossiq\Controller\AcknowledgementController
 */
class AcknowledgementControllerContractTest extends TestCase {

	/**
	 * The acknowledgement of receipt.
	 *
	 * @var AcknowledgementService&MockObject
	 */
	private AcknowledgementService $acknowledgement;

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
	 * The request the `how` parameter is read from.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Wire a controller with a signed-in handler by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acknowledgement = $this->createMock(originalClassName: AcknowledgementService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return AcknowledgementController
	 */
	private function controller(): AcknowledgementController {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new AcknowledgementController(
			appName: 'dossiq',
			request: $this->request,
			acknowledgement: $this->acknowledgement,
			caseAccessGuard: $this->guard,
			userSession: $this->userSession,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * One duty answer, as the service shapes it.
	 *
	 * @return array<string, mixed>
	 */
	private function dutyState(): array {
		return [
			'caseId' => 'case-1',
			'owed' => true,
			'met' => true,
			'sentAt' => '2026-09-14T09:00:00+00:00',
			'channel' => 'email',
			'recipient' => 'aanvrager@example.org',
		];
	}//end dutyState()

	/**
	 * A reader who is not signed in gets 401, and the service is never asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testDutyRefusesAnAnonymousReader(): void {
		$anonymous = $this->createMock(originalClassName: IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$this->userSession = $anonymous;

		$this->guard->expects($this->never())->method('hasCaseReadAccess');
		$this->acknowledgement->expects($this->never())->method('dutyFor');

		$response = $this->controller()->duty(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testDutyRefusesAnAnonymousReader()

	/**
	 * A signed-in user the guard refuses gets 403, not somebody else's duty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testDutyRefusesAUserWithoutReadAccess(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->acknowledgement->expects($this->never())->method('dutyFor');

		$response = $this->controller()->duty(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testDutyRefusesAUserWithoutReadAccess()

	/**
	 * With read access, the answer carries the moment, the channel and the
	 * recipient, under a `duty` key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testDutyAnswersTheRecordedAcknowledgement(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->acknowledgement->expects($this->once())
			->method('dutyFor')
			->with('case-1')
			->willReturn($this->dutyState());

		$response = $this->controller()->duty(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['duty' => $this->dutyState()], actual: $response->getData());
	}//end testDutyAnswersTheRecordedAcknowledgement()

	/**
	 * Clearing the duty needs mutation access, not merely read access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testRecordMetRefusesAUserWithoutMutationAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->acknowledgement->expects($this->never())->method('recordMetAnotherWay');

		$response = $this->controller()->recordMet(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testRecordMetRefusesAUserWithoutMutationAccess()

	/**
	 * The signed-in user is the one recorded as having said so, and the
	 * reason comes off the request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testRecordMetNamesWhoSaidSo(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->with('how', '')->willReturn('Confirmed by post on 14 September');
		$this->acknowledgement->expects($this->once())
			->method('recordMetAnotherWay')
			->with('case-1', 'Confirmed by post on 14 September', 'behandelaar')
			->willReturn($this->dutyState());

		$response = $this->controller()->recordMet(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['duty' => $this->dutyState()], actual: $response->getData());
	}//end testRecordMetNamesWhoSaidSo()

	/**
	 * A refusal answers with its own rule, sentence and status, and never
	 * with the exception message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testRecordMetAnswersARefusalWithItsRuleAndSentence(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		$this->acknowledgement->method('recordMetAnotherWay')
			->willThrowException(
				new RefusedException(
					rule: 'acknowledgement-needs-a-reason',
					sentence: 'Say how receipt was confirmed.',
					status: RefusedException::STATUS_UNPROCESSABLE,
				)
			);

		$response = $this->controller()->recordMet(caseId: 'case-1');

		$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'error' => 'acknowledgement-needs-a-reason',
				'message' => 'Say how receipt was confirmed.',
			],
			actual: $response->getData()
		);
	}//end testRecordMetAnswersARefusalWithItsRuleAndSentence()

	/**
	 * Anything else is a 500 carrying a sentence a person can read, never the
	 * underlying failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function testRecordMetHidesAnUnexpectedFailure(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('At the counter');
		$this->acknowledgement->method('recordMetAnotherWay')
			->willThrowException(new RuntimeException('SQLSTATE[HY000] connection refused on db-1'));

		$response = $this->controller()->recordMet(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertIsArray(actual: $data);
		$this->assertStringNotContainsStringIgnoringCase(needle: 'SQLSTATE', haystack: (string)($data['error'] ?? ''));
	}//end testRecordMetHidesAnUnexpectedFailure()
}//end class
