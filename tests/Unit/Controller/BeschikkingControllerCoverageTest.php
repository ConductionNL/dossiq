<?php

/**
 * BeschikkingController coverage tests.
 *
 * Covers the branches the lifecycle tests leave out: the 401 guard of every
 * endpoint, the compose field mapping, the generic-500 catch-alls, and the
 * refusal and runtime mappings of akkoord, onderteken and verzend.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BeschikkingController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\BeschikkingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Coverage tests for BeschikkingController.
 *
 * @covers \OCA\Dossiq\Controller\BeschikkingController
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Controller\Support\JsonRequestBody
 * @uses \OCA\Dossiq\Controller\Support\ComposeRequestBody
 */
class BeschikkingControllerCoverageTest extends TestCase {
	/**
	 * The beschikking service double.
	 *
	 * @var BeschikkingServiceInterface&MockObject
	 */
	private BeschikkingServiceInterface $service;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The user session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The controller under test.
	 *
	 * @var BeschikkingController
	 */
	private BeschikkingController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(originalClassName: BeschikkingServiceInterface::class);
		// The shipped OCP IRequest stub omits getContent(); add it so the JSON body is mockable.
		$this->request = $this->getMockBuilder(className: IRequest::class)
			->disableOriginalConstructor()
			->addMethods(['getContent'])
			->getMockForAbstractClass();
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->controller = new BeschikkingController(
			appName: 'dossiq',
			request: $this->request,
			decisionService: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
			accessGuard: $this->createMock(originalClassName: CaseAccessGuardInterface::class),
		);
	}//end setUp()

	/**
	 * Make the session report an authenticated user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function authenticate(string $uid = 'tester'): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Assert a response is the generic 500 that leaks nothing.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return void
	 */
	private function assertGeneric500(JSONResponse $response): void {
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'Could not complete the request'], actual: $response->getData());
	}//end assertGeneric500()

	/**
	 * Every endpoint answers 401 without a signed-in user and never reaches the service.
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('compose');
		$this->service->expects($this->never())->method('updateFields');
		$this->service->expects($this->never())->method('akkoord');
		$this->service->expects($this->never())->method('onderteken');
		$this->service->expects($this->never())->method('verzend');

		$responses = [
			$this->controller->create(),
			$this->controller->update('besch-1'),
			$this->controller->akkoord('besch-1'),
			$this->controller->onderteken('besch-1'),
			$this->controller->verzend('besch-1'),
		];

		foreach ($responses as $response) {
			$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
			$this->assertSame(expected: ['error' => 'Not authenticated'], actual: $response->getData());
		}
	}//end testEveryEndpointRefusesAnAnonymousCaller()

	/**
	 * Compose passes the template, the addressee and the decision fields, and nothing else.
	 *
	 * @return void
	 */
	public function testCreatePassesTemplateAddresseeAndDecisionFields(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn((string)json_encode([
			'caseId' => 'zaak-1',
			'templateId' => 42,
			'addressee' => ['name' => 'J. Jansen'],
			'decisionType' => 'toekenning',
			'rationale' => 'Voldoet',
			'reference' => 'B-1999-000001',
		]));
		$this->service->expects($this->once())->method('compose')
			->with(
				'zaak-1',
				'42',
				['addressee' => ['name' => 'J. Jansen'], 'decisionType' => 'toekenning', 'rationale' => 'Voldoet'],
			)
			->willReturn(['id' => 'besch-9']);

		$response = $this->controller->create();

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: ['id' => 'besch-9'], actual: $response->getData());
	}//end testCreatePassesTemplateAddresseeAndDecisionFields()

	/**
	 * An unexpected compose failure is a generic 500 that is logged with the operation.
	 *
	 * @return void
	 */
	public function testCreateFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"caseId":"zaak-1"}');
		$this->service->method('compose')->willThrowException(new \LogicException('secret detail'));
		$this->logger->expects($this->once())->method('error')
			->with('BeschikkingController: compose failed', ['exception' => 'secret detail']);

		$this->assertGeneric500(response: $this->controller->create());
	}//end testCreateFailureIsAGeneric500()

	/**
	 * A show that throws is a generic 500.
	 *
	 * @return void
	 */
	public function testShowFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->service->method('find')->willThrowException(new \Error('boom'));
		$this->logger->expects($this->once())->method('error')
			->with('BeschikkingController: show failed', ['exception' => 'boom']);

		$this->assertGeneric500(response: $this->controller->show('besch-1'));
	}//end testShowFailureIsAGeneric500()

	/**
	 * A non-runtime failure while updating is a generic 500.
	 *
	 * @return void
	 */
	public function testUpdateFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"rationale":"x"}');
		$this->service->method('updateFields')->willThrowException(new \TypeError('bad'));

		$this->assertGeneric500(response: $this->controller->update('besch-1'));
	}//end testUpdateFailureIsAGeneric500()

	/**
	 * The update maps the zaakId_required code to a 400 with its message.
	 *
	 * @return void
	 */
	public function testUpdateMapsZaakIdRequiredToA400(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"caseId":""}');
		$this->service->method('updateFields')->willThrowException(new RuntimeException('zaakId_required'));
		$this->logger->expects($this->once())->method('info')
			->with('BeschikkingController: update rejected', ['code' => 'zaakId_required']);

		$response = $this->controller->update('besch-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'zaakId is required'], actual: $response->getData());
	}//end testUpdateMapsZaakIdRequiredToA400()

	/**
	 * Akkoord records the signed-in user as approver and answers the result.
	 *
	 * @return void
	 */
	public function testAkkoordApprovesAsTheSignedInUser(): void {
		$this->authenticate(uid: 'mandaathouder');
		$this->service->expects($this->once())->method('akkoord')
			->with('besch-1', 'mandaathouder')
			->willReturn(['id' => 'besch-1', 'currentStatus' => 'akkoord']);

		$response = $this->controller->akkoord('besch-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'akkoord', actual: $response->getData()['currentStatus']);
	}//end testAkkoordApprovesAsTheSignedInUser()

	/**
	 * A refused akkoord answers the refusal's own status and rule.
	 *
	 * @return void
	 */
	public function testAkkoordRefusalCarriesItsStatus(): void {
		$this->authenticate();
		$this->service->method('akkoord')->willThrowException(
			new RefusedException(rule: 'four-eyes', sentence: '', status: RefusedException::STATUS_FORBIDDEN)
		);

		$response = $this->controller->akkoord('besch-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => '', 'error' => 'four-eyes', 'code' => 'four_eyes'], actual: $response->getData());
	}//end testAkkoordRefusalCarriesItsStatus()

	/**
	 * A non-runtime akkoord failure is a generic 500.
	 *
	 * @return void
	 */
	public function testAkkoordFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->service->method('akkoord')->willThrowException(new \Error('x'));

		$this->assertGeneric500(response: $this->controller->akkoord('besch-1'));
	}//end testAkkoordFailureIsAGeneric500()

	/**
	 * A refused onderteken answers the refusal's own status, not the runtime 500.
	 *
	 * @return void
	 */
	public function testOndertekenRefusalIsNotMappedAsRuntime(): void {
		$this->authenticate(uid: 'signer');
		$this->request->method('getContent')->willReturn('{"tspProvider":"tsp-a"}');
		$this->service->expects($this->once())->method('onderteken')
			->with('besch-1', 'tsp-a', 'signer')
			->willThrowException(new RefusedException(rule: 'coordinator-seat', sentence: '', status: RefusedException::STATUS_FORBIDDEN));

		$response = $this->controller->onderteken('besch-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 'coordinator-seat', actual: $response->getData()['error']);
	}//end testOndertekenRefusalIsNotMappedAsRuntime()

	/**
	 * A non-runtime onderteken failure is a generic 500.
	 *
	 * @return void
	 */
	public function testOndertekenFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"tspProvider":"tsp-a"}');
		$this->service->method('onderteken')->willThrowException(new \Error('x'));

		$this->assertGeneric500(response: $this->controller->onderteken('besch-1'));
	}//end testOndertekenFailureIsAGeneric500()

	/**
	 * Verzend sends as the signed-in user and answers the result.
	 *
	 * @return void
	 */
	public function testVerzendSendsAsTheSignedInUser(): void {
		$this->authenticate(uid: 'sender');
		$this->service->expects($this->once())->method('verzend')
			->with('besch-1', 'sender')
			->willReturn(['id' => 'besch-1', 'currentStatus' => 'verzonden']);

		$response = $this->controller->verzend('besch-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'verzonden', actual: $response->getData()['currentStatus']);
	}//end testVerzendSendsAsTheSignedInUser()

	/**
	 * An unknown runtime code from verzend falls to the generic 500 message.
	 *
	 * @return void
	 */
	public function testVerzendUnknownRuntimeCodeIsA500(): void {
		$this->authenticate();
		$this->service->method('verzend')->willThrowException(new RuntimeException('something_else'));

		$this->assertGeneric500(response: $this->controller->verzend('besch-1'));
	}//end testVerzendUnknownRuntimeCodeIsA500()

	/**
	 * A non-runtime verzend failure is a generic 500, logged as a failure.
	 *
	 * @return void
	 */
	public function testVerzendFailureIsAGeneric500(): void {
		$this->authenticate();
		$this->service->method('verzend')->willThrowException(new \Error('kaput'));
		$this->logger->expects($this->once())->method('error')
			->with('BeschikkingController: verzend failed', ['exception' => 'kaput']);

		$this->assertGeneric500(response: $this->controller->verzend('besch-1'));
	}//end testVerzendFailureIsAGeneric500()
}//end class
