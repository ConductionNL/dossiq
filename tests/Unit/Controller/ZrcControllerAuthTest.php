<?php

/**
 * ZrcController Authentication Guard Tests
 *
 * Verifies that index(), show(), audittrailIndex(), and audittrailShow()
 * return 401 for unauthenticated requests (security fix for issue #634).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ZrcController;
use OCA\Procest\Service\CaseRelationService;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Auth guard tests for ZrcController (issue #634 fix).
 *
 * Ensures every read endpoint that was previously @PublicPage without JWT
 * validation now enforces authentication.
 *
 * @covers \OCA\Procest\Controller\ZrcController
 */
class ZrcControllerAuthTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked ZGW service.
	 *
	 * @var ZgwService|MockObject
	 */
	private ZgwService $zgwService;

	/**
	 * The mocked l10n service.
	 *
	 * @var IL10N|MockObject
	 */
	private IL10N $l10n;

	/**
	 * The mocked case-relation service.
	 *
	 * @var CaseRelationService|MockObject
	 */
	private CaseRelationService $caseRelationService;

	/**
	 * The controller under test.
	 *
	 * @var ZrcController
	 */
	private ZrcController $controller;

	/**
	 * A pre-built 401 JSONResponse returned by validateJwtAuth when no token is present.
	 *
	 * @var JSONResponse
	 */
	private JSONResponse $unauthResponse;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->zgwService = $this->createMock(originalClassName: ZgwService::class);
		$this->l10n = $this->createMock(originalClassName: IL10N::class);
		$this->caseRelationService = $this->createMock(originalClassName: CaseRelationService::class);

		$this->unauthResponse = new JSONResponse(
			data: [
				'type' => 'NotAuthenticated',
				'code' => 'not_authenticated',
				'title' => 'Authenticatiegegevens zijn niet opgegeven.',
				'status' => 401,
				'detail' => 'Authenticatiegegevens zijn niet opgegeven.',
			],
			statusCode: Http::STATUS_UNAUTHORIZED
		);

		$this->controller = new ZrcController(
			appName: 'procest',
			request: $this->request,
			zgwService: $this->zgwService,
			l10n: $this->l10n,
			caseRelationService: $this->caseRelationService,
		);
	}//end setUp()

	/**
	 * Test that index() returns 401 when no Authorization header is present.
	 *
	 * @return void
	 */
	public function testIndexReturns401WhenUnauthenticated(): void {
		$this->zgwService
			->expects($this->once())
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn($this->unauthResponse);

		// HandleIndex must NOT be called — auth should short-circuit first.
		$this->zgwService->expects($this->never())->method('handleIndex');

		$response = $this->controller->index(resource: 'zaken');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testIndexReturns401WhenUnauthenticated()

	/**
	 * Test that show() returns 401 for all resources when unauthenticated.
	 *
	 * @return void
	 */
	public function testShowReturns401WhenUnauthenticated(): void {
		$this->zgwService
			->expects($this->once())
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn($this->unauthResponse);

		$this->zgwService->expects($this->never())->method('handleShow');

		$response = $this->controller->show(resource: 'zaken', uuid: 'some-uuid');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testShowReturns401WhenUnauthenticated()

	/**
	 * Test that show() for non-zaken resources also returns 401 when unauthenticated.
	 *
	 * Previously the auth check was only applied when $resource === 'zaken', leaving
	 * other resources (statussen, resultaten, rollen, etc.) publicly accessible.
	 *
	 * @return void
	 */
	public function testShowNonZaakResourceReturns401WhenUnauthenticated(): void {
		$this->zgwService
			->expects($this->once())
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn($this->unauthResponse);

		$this->zgwService->expects($this->never())->method('handleShow');

		$response = $this->controller->show(resource: 'statussen', uuid: 'some-uuid');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testShowNonZaakResourceReturns401WhenUnauthenticated()

	/**
	 * Test that audittrailIndex() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testAudittrailIndexReturns401WhenUnauthenticated(): void {
		$this->zgwService
			->expects($this->once())
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn($this->unauthResponse);

		$this->zgwService->expects($this->never())->method('handleAudittrailIndex');

		$response = $this->controller->audittrailIndex(resource: 'zaken', uuid: 'some-uuid');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAudittrailIndexReturns401WhenUnauthenticated()

	/**
	 * Test that audittrailShow() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testAudittrailShowReturns401WhenUnauthenticated(): void {
		$this->zgwService
			->expects($this->once())
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn($this->unauthResponse);

		$this->zgwService->expects($this->never())->method('handleAudittrailShow');

		$response = $this->controller->audittrailShow(
			resource: 'zaken',
			uuid: 'some-uuid',
			auditUuid: 'audit-uuid'
		);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAudittrailShowReturns401WhenUnauthenticated()

	/**
	 * Test that index() proceeds past auth when validateJwtAuth returns null (valid token).
	 *
	 * @return void
	 */
	public function testIndexProceedsWhenAuthenticated(): void {
		$this->zgwService
			->method('validateJwtAuth')
			->with($this->request)
			->willReturn(null);

		$expectedResponse = new JSONResponse(
			data: ['count' => 0, 'next' => null, 'previous' => null, 'results' => []],
			statusCode: Http::STATUS_OK
		);

		$this->zgwService
			->expects($this->once())
			->method('handleIndex')
			->willReturn($expectedResponse);

		// GetConsumerAuthorisaties is called by filterZakenByAuthorisation (returns null = superuser).
		$this->zgwService
			->method('getConsumerAuthorisaties')
			->willReturn(null);

		$response = $this->controller->index(resource: 'zaken');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testIndexProceedsWhenAuthenticated()

}//end class
