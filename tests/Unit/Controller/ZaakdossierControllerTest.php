<?php

/**
 * ZaakdossierController Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ZaakdossierController;
use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\ZipManifestBuilder;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ZaakdossierController.
 *
 * @covers \OCA\Procest\Controller\ZaakdossierController
 */
class ZaakdossierControllerTest extends TestCase
{

    private ZaakdossierService $zaakdossierService;
    private InformatieobjectAccessGuard $accessGuard;
    private ZipManifestBuilder $zipBuilder;
    private SettingsService $settingsService;
    private IRequest $request;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private ZaakdossierController $controller;

    protected function setUp(): void
    {
        $this->zaakdossierService = $this->createMock(ZaakdossierService::class);
        $this->accessGuard        = $this->createMock(InformatieobjectAccessGuard::class);
        $this->zipBuilder         = $this->createMock(ZipManifestBuilder::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->request            = $this->createMock(IRequest::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->controller = new ZaakdossierController(
            appName: 'procest',
            request: $this->request,
            zaakdossierService: $this->zaakdossierService,
            accessGuard: $this->accessGuard,
            zipBuilder: $this->zipBuilder,
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test listDossier returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testListDossierReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->listDossier(caseId: 'case-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testListDossierReturns401WhenUnauthenticated()

    /**
     * Test listDossier returns grouped dossier for authenticated user.
     *
     * @return void
     */
    public function testListDossierReturnsGroupedDossier(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->zaakdossierService
            ->method('getDossierForCase')
            ->with(caseId: 'case-1')
            ->willReturn(['Aanvraag' => [['id' => 'doc-1', 'titel' => 'T1']]]);

        $response = $this->controller->listDossier(caseId: 'case-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('case-1', $data['caseId']);
        $this->assertArrayHasKey('dossier', $data);
        $this->assertSame(1, $data['count']);
    }//end testListDossierReturnsGroupedDossier()

    /**
     * Test transitionStatus returns 400 when status is missing.
     *
     * The controller's readJsonBody() calls $request->getContent() which is
     * not on the IRequest interface. The mock returns '' (empty content) so
     * the decoded body is [], status defaults to '', triggering the guard.
     *
     * @return void
     */
    public function testTransitionStatusReturnsBadRequestWhenStatusMissing(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        // getParams() returns empty array so status defaults to '' → 400.
        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->request->method('getParams')->willReturn([]);
        // phpcs:enable

        $response = $this->controller->transitionStatus(infoObjectId: 'doc-1');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionStatusReturnsBadRequestWhenStatusMissing()

    /**
     * Test transitionStatus returns 400 on invalid transition.
     *
     * @return void
     */
    public function testTransitionStatusReturnsBadRequestOnInvalidTransition(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->zaakdossierService
            ->method('transitionStatus')
            ->willThrowException(new \RuntimeException('Transition from definitief to concept is not allowed.'));

        // Pass status via params so the controller sees a non-empty value.
        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->request->method('getParams')->willReturn(['status' => 'concept']);
        // phpcs:enable

        $response = $this->controller->transitionStatus(infoObjectId: 'doc-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testTransitionStatusReturnsBadRequestOnInvalidTransition()

    /**
     * Test uploadDocument returns 400 when required field is missing.
     *
     * @return void
     */
    public function testUploadDocumentReturnsBadRequestWithoutMetadata(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->request->method('getUploadedFile')->willReturn(['name' => 'test.pdf', 'tmp_name' => '/tmp/test', 'size' => 100, 'type' => 'application/pdf']);
        $this->request->method('getParams')->willReturn([]);
        // phpcs:enable
        // No metadata params set → required fields (titel etc.) will be empty → 400.

        $response = $this->controller->uploadDocument(caseId: 'case-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testUploadDocumentReturnsBadRequestWithoutMetadata()

}//end class
