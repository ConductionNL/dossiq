<?php

/**
 * ZaakValidationHandlerTest
 *
 * Unit tests for the ZaakValidationHandler class.
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

use OCA\Procest\Controller\ZrcController\ZaakValidationHandler;
use OCA\Procest\Service\ZgwService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZaakValidationHandler.
 */
class ZaakValidationHandlerTest extends TestCase
{

    private ZaakValidationHandler $handler;

    /**
     * Set up the test fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $zgwService = $this->createMock(ZgwService::class);
        $l10n       = $this->createMock(IL10N::class);
        $zgwService->method('getRequestBody')->willReturn([]);
        $this->handler = new ZaakValidationHandler(
            zgwService: $zgwService,
            l10n: $l10n
        );
    }//end setUp()

    /**
     * Test that preValidateZaakBody returns null when the request body is empty.
     *
     * @return void
     */
    public function testPreValidateZaakBodyReturnsNullWhenBodyIsEmpty(): void
    {
        $request = $this->createMock(IRequest::class);
        $result  = $this->handler->preValidateZaakBody(isPatch: false, request: $request);
        $this->assertNull($result);
    }//end testPreValidateZaakBodyReturnsNullWhenBodyIsEmpty()

}//end class
