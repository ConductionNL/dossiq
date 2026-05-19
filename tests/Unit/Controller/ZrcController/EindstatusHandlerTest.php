<?php

/**
 * EindstatusHandler Unit Tests
 *
 * Tests for the EindstatusHandler extracted from ZrcController.
 *
 * @category Tests
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

use OCA\Procest\Controller\ZrcController\EindstatusHandler;
use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EindstatusHandler.
 *
 * @covers \OCA\Procest\Controller\ZrcController\EindstatusHandler
 */
class EindstatusHandlerTest extends TestCase
{

    /**
     * The mocked ZGW service.
     *
     * @var ZgwService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ZgwService $zgwService;

    /**
     * The mocked localization service.
     *
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private IL10N $l10n;

    /**
     * The mocked request.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The handler under test.
     *
     * @var EindstatusHandler
     */
    private EindstatusHandler $handler;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->zgwService = $this->createMock(ZgwService::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->request    = $this->createMock(IRequest::class);

        $this->handler = new EindstatusHandler(
            zgwService: $this->zgwService,
            l10n: $this->l10n,
        );

    }//end setUp()


    /**
     * Test that checkReopenScope returns null when zaak URL is empty.
     *
     * @return void
     */
    public function testCheckReopenScopeReturnsNullWhenZaakUrlIsEmpty(): void
    {
        $body = ['zaak' => '', 'statustype' => 'http://example.com/statustype/1'];

        $result = $this->handler->checkReopenScope(body: $body, request: $this->request);

        $this->assertNull($result);

    }//end testCheckReopenScopeReturnsNullWhenZaakUrlIsEmpty()


    /**
     * Test that checkReopenScope returns null when statustype URL is empty.
     *
     * @return void
     */
    public function testCheckReopenScopeReturnsNullWhenStatustypeUrlIsEmpty(): void
    {
        $body = ['zaak' => 'http://example.com/zaken/1', 'statustype' => ''];

        $result = $this->handler->checkReopenScope(body: $body, request: $this->request);

        $this->assertNull($result);

    }//end testCheckReopenScopeReturnsNullWhenStatustypeUrlIsEmpty()


    /**
     * Test that checkReopenScope returns null when both URLs are empty.
     *
     * @return void
     */
    public function testCheckReopenScopeReturnsNullWhenBothUrlsAreEmpty(): void
    {
        $body = ['zaak' => '', 'statustype' => ''];

        $result = $this->handler->checkReopenScope(body: $body, request: $this->request);

        $this->assertNull($result);

    }//end testCheckReopenScopeReturnsNullWhenBothUrlsAreEmpty()


    /**
     * Test that checkIndicatieGebruiksrechtBeforeClose returns null when statustype URL is empty.
     *
     * @return void
     */
    public function testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenStatustypeUrlIsEmpty(): void
    {
        $body = ['zaak' => 'http://example.com/zaken/1', 'statustype' => ''];

        $result = $this->handler->checkIndicatieGebruiksrechtBeforeClose(body: $body);

        $this->assertNull($result);

    }//end testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenStatustypeUrlIsEmpty()


    /**
     * Test that checkIndicatieGebruiksrechtBeforeClose returns null when zaak URL is empty.
     *
     * @return void
     */
    public function testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenZaakUrlIsEmpty(): void
    {
        $body = ['zaak' => '', 'statustype' => 'http://example.com/statustype/1'];

        $result = $this->handler->checkIndicatieGebruiksrechtBeforeClose(body: $body);

        $this->assertNull($result);

    }//end testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenZaakUrlIsEmpty()


    /**
     * Test that checkIndicatieGebruiksrechtBeforeClose returns null when body is empty.
     *
     * @return void
     */
    public function testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenBodyIsEmpty(): void
    {
        $body = [];

        $result = $this->handler->checkIndicatieGebruiksrechtBeforeClose(body: $body);

        $this->assertNull($result);

    }//end testCheckIndicatieGebruiksrechtBeforeCloseReturnsNullWhenBodyIsEmpty()


    /**
     * Test that handleEindstatusEffect returns early when statustype is empty.
     *
     * @return void
     */
    public function testHandleEindstatusEffectReturnsEarlyWhenStatustypeEmpty(): void
    {
        $body       = ['statustype' => ''];
        $objectData = [];

        // No ZGW service calls expected when statustype is empty.
        $this->zgwService->expects($this->never())
            ->method('getZgwMappingService');

        $this->handler->handleEindstatusEffect(body: $body, objectData: $objectData);

        // Reaching here without exception is the assertion.
        $this->assertTrue(true);

    }//end testHandleEindstatusEffectReturnsEarlyWhenStatustypeEmpty()


    /**
     * Test that handleResultaatCreated returns early when zaak URL is empty.
     *
     * @return void
     */
    public function testHandleResultaatCreatedReturnsEarlyWhenZaakUrlIsEmpty(): void
    {
        $body       = ['zaak' => ''];
        $objectData = [];

        // No ZGW service calls expected when zaak URL is empty.
        $this->zgwService->expects($this->never())
            ->method('getZgwMappingService');

        $this->handler->handleResultaatCreated(body: $body, objectData: $objectData);

        // Reaching here without exception is the assertion.
        $this->assertTrue(true);

    }//end testHandleResultaatCreatedReturnsEarlyWhenZaakUrlIsEmpty()


}//end class
