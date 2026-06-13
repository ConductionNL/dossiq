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
class CaseRelationControllerTest extends TestCase
{

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
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(CaseRelationService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->controller = new CaseRelationController(
            request: $this->request,
            caseRelationService: $this->service,
            userSession: $this->userSession,
        );
    }//end setUp()


    /**
     * Mark the session as authenticated.
     *
     * @return void
     */
    private function authenticate(): void
    {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
    }//end authenticate()


    /**
     * An unauthenticated create request is rejected with 401.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testCreateRequiresAuthentication(): void
    {
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
    public function testCreateMissingParamsIsBadRequest(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k, $d=null) => $d
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
    public function testCreateSuccessReturns201(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $k, $d=null) {
                return match ($k) {
                    'targetId' => 'b',
                    'aardRelatie' => 'onderwerp',
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
    public function testDuplicateMapsToConflict(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $k, $d=null) {
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
    public function testAccessDeniedMapsToForbidden(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $k, $d=null) {
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
    public function testSelfRelationMapsToBadRequest(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnCallback(
            static function (string $k, $d=null) {
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
    public function testDestroySuccess(): void
    {
        $this->authenticate();
        $this->service->expects($this->once())
            ->method('removeRelation')
            ->with(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg')
            ->willReturn(['ok' => true]);

        $response = $this->controller->destroy(caseId: 'a', targetId: 'b', aardRelatie: 'vervolg');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testDestroySuccess()


    /**
     * List delegates to listRelations and returns the results envelope.
     *
     * @return void
     *
     * @spec openspec/specs/related-case-linking/spec.md
     */
    public function testListReturnsResults(): void
    {
        $this->authenticate();
        $this->service->method('listRelations')->willReturn([
            ['caseId' => 'b', 'aardRelatie' => 'onderwerp'],
        ]);

        $response = $this->controller->list(caseId: 'a');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
    }//end testListReturnsResults()
}//end class
