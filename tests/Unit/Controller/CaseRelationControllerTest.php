<?php

/**
 * CaseRelationController Unit Tests
 *
 * Covers the REST surface: authentication gate, body validation, guard-reason
 * to HTTP-status mapping (duplicate => 409, access_denied => 403, self => 400),
 * and the two-sided list/create/destroy delegation.
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\CaseRelationController;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\CaseRelationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseRelationController.
 *
 * @covers \OCA\Procest\Controller\CaseRelationController
 */
class CaseRelationControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var CaseRelationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseRelationService $service;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var CaseRelationController
	 */
	private CaseRelationController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(CaseRelationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		// Default: the caller works on both cases. The authorization tests at
		// the bottom of this class override it.
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->controller = new CaseRelationController(
			request: $this->request,
			caseRelationService: $this->service,
			userSession: $this->userSession,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Build a controller whose guard answers per case id.
	 *
	 * @param array<string, bool> $readable Case UUID => whether it is readable.
	 *
	 * @return CaseRelationController The controller under test.
	 */
	private function controllerWithReadableCases(array $readable): CaseRelationController {
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			static function (string $caseId) use ($readable): bool {
				return ($readable[$caseId] ?? false);
			}
		);

		return new CaseRelationController(
			request: $this->request,
			caseRelationService: $this->service,
			userSession: $this->userSession,
			caseAccessGuard: $guard,
		);
	}//end controllerWithReadableCases()

	/**
	 * Mark the session as authenticated.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end authenticate()

	/**
	 * An unauthenticated create request is rejected with 401.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testCreateRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testCreateRequiresAuthentication()

	/**
	 * A missing targetId/aardRelatie yields 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testCreateMissingParamsIsBadRequest(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => $d
		);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateMissingParamsIsBadRequest()

	/**
	 * A successful add returns 201.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testCreateSuccessReturns201(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $d = null) {
				return match ($k) {
					'targetId' => 'b',
					'aardRelatie' => 'subject',
					default => $d,
				};
			}
		);
		$this->service->method('addRelation')->willReturn(['ok' => true]);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateSuccessReturns201()

	/**
	 * A duplicate guard reason maps to 409.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testDuplicateMapsToConflict(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $d = null) {
				return match ($k) {
					'targetId' => 'b',
					'aardRelatie' => 'vervolg',
					default => $d,
				};
			}
		);
		$this->service->method('addRelation')->willReturn(['ok' => false, 'reason' => 'duplicate']);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testDuplicateMapsToConflict()

	/**
	 * An access_denied guard reason maps to 403 (IDOR fail-closed).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testAccessDeniedMapsToForbidden(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $d = null) {
				return match ($k) {
					'targetId' => 'b',
					'aardRelatie' => 'vervolg',
					default => $d,
				};
			}
		);
		$this->service->method('addRelation')->willReturn(['ok' => false, 'reason' => 'access_denied']);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAccessDeniedMapsToForbidden()

	/**
	 * A self-relation guard reason maps to 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testSelfRelationMapsToBadRequest(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnCallback(
			static function (string $k, $d = null) {
				return match ($k) {
					'targetId' => 'a',
					'aardRelatie' => 'vervolg',
					default => $d,
				};
			}
		);
		$this->service->method('addRelation')->willReturn(['ok' => false, 'reason' => 'self_relation']);

		$response = $this->controller->create(caseId: 'a');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSelfRelationMapsToBadRequest()

	/**
	 * Destroy delegates to removeRelation and returns 200 on success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testDestroySuccess(): void {
		$this->authenticate();
		$this->service->expects($this->once())
			->method('removeRelation')
			->with(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg')
			->willReturn(['ok' => true]);

		$response = $this->controller->destroy(caseId: 'a', targetId: 'b', natureRelationship: 'vervolg');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDestroySuccess()

	/**
	 * List delegates to listRelations and returns the results envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/related-case-linking/spec.md
	 */
	public function testListReturnsResults(): void {
		$this->authenticate();
		$this->service->method('listRelations')->willReturn([
			['caseId' => 'b', 'aardRelatie' => 'subject'],
		]);

		$response = $this->controller->list(caseId: 'a');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertCount(1, $data['results']);
	}//end testListReturnsResults()

	/**
	 * An authenticated user who does not work on the case cannot list its
	 * relations, and the service is never reached.
	 *
	 * Before this guard the service-level `access_denied` was unreachable: it
	 * keys off `find()` returning null, and OpenRegister returns every object
	 * to every authenticated user for a schema with no `authorization` block.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testListIsRefusedForUnrelatedUser(): void {
		$this->authenticate();
		$this->service->expects($this->never())->method('listRelations');

		$response = $this->controllerWithReadableCases(['a' => false])->list(caseId: 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testListIsRefusedForUnrelatedUser()

	/**
	 * Holding access to only the ORIGIN case is not enough to create a
	 * relation — a relation is written to both cases.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testCreateIsRefusedWhenOnlyTheOriginIsReadable(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnMap([
			['targetId', '', 'b'],
			['aardRelatie', '', 'subject'],
			['notes', null, null],
		]);
		$this->service->expects($this->never())->method('addRelation');

		$response = $this->controllerWithReadableCases(['a' => true, 'b' => false])
			->create(caseId: 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateIsRefusedWhenOnlyTheOriginIsReadable()

	/**
	 * Holding access to only the TARGET case is not enough either.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testCreateIsRefusedWhenOnlyTheTargetIsReadable(): void {
		$this->authenticate();
		$this->request->method('getParam')->willReturnMap([
			['targetId', '', 'b'],
			['aardRelatie', '', 'subject'],
			['notes', null, null],
		]);
		$this->service->expects($this->never())->method('addRelation');

		$response = $this->controllerWithReadableCases(['a' => false, 'b' => true])
			->create(caseId: 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateIsRefusedWhenOnlyTheTargetIsReadable()

	/**
	 * Removing a relation between two cases the caller does not work on is
	 * refused, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testDestroyIsRefusedForUnrelatedUser(): void {
		$this->authenticate();
		$this->service->expects($this->never())->method('removeRelation');

		$response = $this->controllerWithReadableCases(['a' => false, 'b' => false])
			->destroy(caseId: 'a', targetId: 'b', natureRelationship: 'subject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDestroyIsRefusedForUnrelatedUser()
}//end class
