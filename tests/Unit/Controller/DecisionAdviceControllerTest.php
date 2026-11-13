<?php

/**
 * Unit tests for DecisionAdviceController: who may ask for and decide advice
 * on a case, and how each refusal answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DecisionAdviceController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\DecisionAdviceController
 * @uses \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal
 */
class DecisionAdviceControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private DecisionAdviceService&MockObject $advice;

	private IUserSession&MockObject $session;

	private CaseAccessGuard&MockObject $guard;

	private DecisionAdviceController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->advice = $this->createMock(DecisionAdviceService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->controller = new DecisionAdviceController('dossiq', $this->request, $this->advice, $this->session, $this->guard);
	}//end setUp()

	/**
	 * Sign in as a handler.
	 *
	 * @return IUser The user.
	 */
	private function signedIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}//end signedIn()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
	}//end params()

	public function testRecommendAsksTheServiceForAGuardedCase(): void {
		$user = $this->signedIn();
		$this->params(['caseId' => 'case-1', 'inputs' => ['behaviour' => 'C', 'consequence' => '3']]);
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn(true);
		$this->advice->expects($this->once())->method('recommend')
			->with('case-1', ['behaviour' => 'C', 'consequence' => '3'], 'handler')
			->willReturn(['id' => 'advice-1', 'recommendation' => 'Bestuurlijke sanctie']);

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'advice-1', 'recommendation' => 'Bestuurlijke sanctie'], $response->getData());
	}

	public function testRecommendWithoutSessionAnswers401AndAsksNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->advice->expects($this->never())->method('recommend');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->recommend()->getStatus());
	}

	public function testRecommendWithoutCaseOrInputsAnswers400BeforeTheGuard(): void {
		$this->signedIn();
		$this->params(['caseId' => 'case-1', 'inputs' => 'behaviour=C']);
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId en inputs zijn verplicht'], $response->getData());
	}

	public function testRecommendOnACaseTheCallerMayNotChangeAnswers403(): void {
		$this->signedIn();
		$this->params(['caseId' => 'case-1', 'inputs' => []]);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->advice->expects($this->never())->method('recommend');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->recommend()->getStatus());
	}

	public function testACaseTypeWithoutATableAnswers409(): void {
		$this->signedIn();
		$this->params(['caseId' => 'case-1', 'inputs' => []]);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->advice->method('recommend')->willThrowException(
			new DecisionAdviceRefusal('Het zaaktype van deze zaak noemt geen beslistabel voor advies', Http::STATUS_CONFLICT)
		);

		$response = $this->controller->recommend();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Het zaaktype van deze zaak noemt geen beslistabel voor advies'], $response->getData());
	}

	public function testAcceptRecordsTheChoiceOnTheGuardedCase(): void {
		$user = $this->signedIn();
		$this->params(['adviceId' => 'advice-1', 'outcome' => 'Bestuurlijke sanctie']);
		$stored = ['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'Bestuurlijke sanctie'];
		$this->advice->method('find')->with('advice-1')->willReturn($stored);
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn(true);
		$this->advice->expects($this->once())->method('decide')
			->with($stored, 'Bestuurlijke sanctie', '', 'handler')
			->willReturn($stored + ['outcome' => 'Bestuurlijke sanctie', 'accepted' => true]);

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['accepted']);
	}

	public function testAnOverrideWithoutAReasonAnswers400(): void {
		$this->signedIn();
		$this->params(['adviceId' => 'advice-1', 'outcome' => 'Aanspreken']);
		$this->advice->method('find')->willReturn(['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'X']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->advice->method('decide')->willThrowException(new DecisionAdviceRefusal(DecisionAdviceService::REASON_REQUIRED, Http::STATUS_BAD_REQUEST));

		$response = $this->controller->override();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Geef een reden voor de afwijking'], $response->getData());
	}

	public function testAnOverrideOnACaseTheCallerMayNotChangeDecidesNothing(): void {
		$this->signedIn();
		$this->params(['adviceId' => 'advice-1', 'outcome' => 'X']);
		$this->advice->method('find')->willReturn(['id' => 'advice-1', 'case' => 'case-1', 'recommendation' => 'X']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->advice->expects($this->never())->method('decide');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->override()->getStatus());
	}

	public function testAnOverrideWithoutSessionOrAdviceIdOrOfUnknownAdviceIsRefused(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls(null, $this->createMock(IUser::class), $this->createMock(IUser::class));
		$this->params(['adviceId' => '']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->override()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->override()->getStatus());

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($key === 'adviceId' ? 'gone' : $default));
		$this->advice->method('find')->willThrowException(new DecisionAdviceRefusal('Dit advies bestaat niet', Http::STATUS_NOT_FOUND));
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');
		$controller = new DecisionAdviceController('dossiq', $request, $this->advice, $this->session, $this->guard);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->override()->getStatus());
	}
}
