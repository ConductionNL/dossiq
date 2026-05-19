<?php

/**
 * ZaakAuthorizationHandlerTest
 *
 * Unit tests for the ZaakAuthorizationHandler class.
 *
 * @category Controller
 * @package  OCA\Procest\Tests\Unit\Controller\ZrcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller\ZrcController;

use OCA\Procest\Controller\ZrcController\ZaakAuthorizationHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZaakAuthorizationHandler.
 */
class ZaakAuthorizationHandlerTest extends TestCase
{

    private ZaakAuthorizationHandler $handler;

    private ZgwService $zgwService;

    private IL10N $l10n;

    /**
     * Set up the test fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->handler    = new ZaakAuthorizationHandler(
            zgwService: $this->zgwService,
            l10n: $this->l10n
        );
    }//end setUp()

    /**
     * Test that permissionDeniedResponse returns a 403 Forbidden response.
     *
     * @return void
     */
    public function testPermissionDeniedResponseReturnsForbidden(): void
    {
        $this->l10n->method('t')->willReturn('You do not have the correct permissions for this action.');
        $response = $this->handler->permissionDeniedResponse();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testPermissionDeniedResponseReturnsForbidden()

    /**
     * Test that filterZakenByAuthorisation returns the response unchanged when no restrictions apply.
     *
     * @return void
     */
    public function testFilterZakenByAuthorisationReturnsUnchangedWhenNoRestrictions(): void
    {
        $request = $this->createMock(IRequest::class);
        $this->zgwService->method('getConsumerAuthorisaties')->willReturn(null);
        $response = new JSONResponse(data: ['count' => 0, 'results' => []]);
        $result   = $this->handler->filterZakenByAuthorisation(response: $response, request: $request);
        $this->assertSame($response, $result);
    }//end testFilterZakenByAuthorisationReturnsUnchangedWhenNoRestrictions()

}//end class
