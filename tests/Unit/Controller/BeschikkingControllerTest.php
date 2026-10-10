<?php

/**
 * BeschikkingController Unit Tests.
 *
 * Verifies authentication guards, request validation, and the mapping of
 * domain RuntimeExceptions to HTTP statuses (403 mandaat, 409 immutable /
 * invalid transition, 404 not found).
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
use OCA\Dossiq\Service\BeschikkingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for BeschikkingController.
 *
 * @covers \OCA\Dossiq\Controller\BeschikkingController
 */
class BeschikkingControllerTest extends TestCase {
	/**
	 * The beschikking service mock.
	 *
	 * @var BeschikkingService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private BeschikkingService $service;

	/**
	 * The request mock.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * The user session mock.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The per-case access guard double.
	 *
	 * @var \OCA\Dossiq\Service\CaseAccessGuard&\PHPUnit\Framework\MockObject\MockObject
	 */
	private \OCA\Dossiq\Service\CaseAccessGuard $guard;

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
		$this->service = $this->createMock(BeschikkingService::class);
		// The shipped OCP IRequest stub omits getContent(); add it explicitly so
		// the JSON-body path is mockable (the method exists on the real NC IRequest).
		$this->request = $this->getMockBuilder(IRequest::class)
			->disableOriginalConstructor()
			->addMethods(['getContent'])
			->getMockForAbstractClass();
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->guard = $this->createMock(\OCA\Dossiq\Service\CaseAccessGuard::class);

		$this->controller = new BeschikkingController(
			'dossiq',
			$this->request,
			$this->service,
			$this->userSession,
			$logger,
			$this->guard,
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
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * No number could be reserved: a 503 the client may retry, not a 500 (decision 167).
	 *
	 * @return void
	 */
	public function testCreateWithoutANumberIs503(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"caseId":"zaak-1"}');
		$this->service->method('compose')->willThrowException(
			\OCA\Dossiq\Exception\RefusedException::indeterminate(rule: 'document-number-unavailable', sentence: 'No number.')
		);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('document-number-unavailable', $response->getData()['error']);
	}//end testCreateWithoutANumberIs503()

	/**
	 * A field edit never writes the number or the chain pointers (REQ-BES-012).
	 *
	 * @return void
	 */
	public function testUpdateNeverWritesTheNumberOrThePointers(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn(
			'{"rationale":"x","reference":"B-1999-000001","supersedes":"a","supersededBy":"b"}'
		);
		$this->service->expects($this->once())->method('updateFields')
			->with('besch-1', ['rationale' => 'x'])
			->willReturn(['id' => 'besch-1']);

		$this->assertSame(Http::STATUS_OK, $this->controller->update('besch-1')->getStatus());
	}//end testUpdateNeverWritesTheNumberOrThePointers()

	/**
	 * A handler who may change the case issues the successor with exactly the content they sent.
	 *
	 * @return void
	 */
	public function testAHandlerWithMutationAccessIssuesTheSuccessor(): void {
		$this->authenticate();
		$this->service->method('find')->willReturn(['id' => 'besch-1', 'caseId' => 'case-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['id' => 'besch-1', 'decisionType' => 'amendment', 'rationale' => 'herzien', 'reference' => 'B-1999-000001']
		);
		$this->service->expects($this->once())->method('issueSuccessor')
			->with('besch-1', 'amendment', ['rationale' => 'herzien'], null)
			->willReturn(['id' => 'besch-2', 'reference' => 'B-2026-000124']);

		$response = $this->controller->successor('besch-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('B-2026-000124', $response->getData()['reference']);
	}//end testAHandlerWithMutationAccessIssuesTheSuccessor()

	/**
	 * Without mutation access on the case nothing is issued; an unknown beschikking is a 404; nobody signed in is a 401.
	 *
	 * @return void
	 */
	public function testSuccessorRefusals(): void {
		$this->service->expects($this->never())->method('issueSuccessor');
		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls(null, $this->createMock(IUser::class), $this->createMock(IUser::class));
		$this->service->method('find')->willReturnOnConsecutiveCalls(null, ['id' => 'besch-1', 'caseId' => 'case-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->successor('besch-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->successor('nope')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->successor('besch-1')->getStatus());
	}//end testSuccessorRefusals()

	/**
	 * A refusal answers with its own status and sentence; an unexpected failure is a generic 500.
	 *
	 * @return void
	 */
	public function testSuccessorRefusalCarriesItsStatusAndAFailureLeaksNothing(): void {
		$this->authenticate();
		$this->service->method('find')->willReturn(['id' => 'besch-1', 'caseId' => 'case-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(['decisionType' => 'amendment', 'templateId' => 'tpl-2']);
		$this->service->method('issueSuccessor')->willReturnOnConsecutiveCalls(
			$this->throwException(new \OCA\Dossiq\Exception\RefusedException(rule: 'already-superseded', sentence: 'Replaced by B-2026-000124.')),
			$this->throwException(new \RuntimeException('secret detail')),
		);

		$refused = $this->controller->successor('besch-1');
		$this->assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		$this->assertSame('already-superseded', $refused->getData()['error']);

		$failed = $this->controller->successor('besch-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertStringNotContainsString('secret', (string)json_encode($failed->getData()));
	}//end testSuccessorRefusalCarriesItsStatusAndAFailureLeaksNothing()

	/**
	 * An unauthenticated show request returns 401.
	 *
	 * @return void
	 */
	public function testShowRequiresAuth(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->show('besch-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testShowRequiresAuth()

	/**
	 * A missing beschikking returns 404.
	 *
	 * @return void
	 */
	public function testShowNotFound(): void {
		$this->authenticate();
		$this->service->method('find')->willReturn(null);

		$response = $this->controller->show('besch-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowNotFound()

	/**
	 * A successful read returns the beschikking.
	 *
	 * @return void
	 */
	public function testShowSuccess(): void {
		$this->authenticate();
		$this->service->method('find')->willReturn(['id' => 'besch-1', 'currentStatus' => 'draft']);

		$response = $this->controller->show('besch-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('besch-1', $response->getData()['id']);
	}//end testShowSuccess()

	/**
	 * Composing without zaakId returns 400.
	 *
	 * @return void
	 */
	public function testCreateRequiresZaakId(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{}');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateRequiresZaakId()

	/**
	 * A successful compose returns 201.
	 *
	 * @return void
	 */
	public function testCreateSuccess(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"caseId":"zaak-1"}');
		$this->service->method('compose')->willReturn(['id' => 'besch-1', 'currentStatus' => 'draft']);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateSuccess()

	/**
	 * Insufficient mandaat maps to 403.
	 *
	 * @return void
	 */
	public function testAkkoordMandaatForbidden(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"approvedBy":"consulent-1"}');
		$this->service->method('akkoord')->willThrowException(new RuntimeException('mandaat_insufficient'));

		$response = $this->controller->akkoord('besch-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAkkoordMandaatForbidden()

	/**
	 * Editing an immutable beschikking maps to 409.
	 *
	 * @return void
	 */
	public function testUpdateImmutableConflict(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{"rationale":"x"}');
		$this->service->method('updateFields')->willThrowException(new RuntimeException('immutable'));

		$response = $this->controller->update('besch-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testUpdateImmutableConflict()

	/**
	 * Signing without a tspProvider returns 400.
	 *
	 * @return void
	 */
	public function testOndertekenRequiresProvider(): void {
		$this->authenticate();
		$this->request->method('getContent')->willReturn('{}');

		$response = $this->controller->onderteken('besch-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testOndertekenRequiresProvider()

	/**
	 * An invalid transition on verzend maps to 409.
	 *
	 * @return void
	 */
	public function testVerzendInvalidTransitionConflict(): void {
		$this->authenticate();
		$this->service->method('verzend')->willThrowException(new RuntimeException('invalid_transition'));

		$response = $this->controller->verzend('besch-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testVerzendInvalidTransitionConflict()
}//end class
