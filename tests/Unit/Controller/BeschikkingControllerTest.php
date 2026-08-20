<?php

/**
 * BeschikkingController Unit Tests.
 *
 * Verifies authentication guards, request validation, and the mapping of
 * domain RuntimeExceptions to HTTP statuses (403 mandaat, 409 immutable /
 * invalid transition, 404 not found).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\BeschikkingController;
use OCA\Procest\Service\BeschikkingService;
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
 * @covers \OCA\Procest\Controller\BeschikkingController
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

		$this->controller = new BeschikkingController(
			'procest',
			$this->request,
			$this->service,
			$this->userSession,
			$logger,
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
