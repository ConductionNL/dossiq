<?php

/**
 * MandaatValidationService Unit Tests
 *
 * Tests the mandaatregister validator's fail-safe behaviour: when the register
 * is unconfigured it must require manual confirmation rather than silently
 * passing.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\MandaatValidationService;
use OCA\Procest\Service\SettingsService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MandaatValidationService.
 *
 * @covers \OCA\Procest\Service\MandaatValidationService
 */
class MandaatValidationServiceTest extends TestCase
{
    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked HTTP client service.
     *
     * @var IClientService|\PHPUnit\Framework\MockObject\MockObject
     */
    private IClientService $clientService;

    /**
     * The service under test.
     *
     * @var MandaatValidationService
     */
    private MandaatValidationService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->clientService   = $this->createMock(IClientService::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->service = new MandaatValidationService($this->settingsService, $this->clientService, $logger);
    }//end setUp()

    /**
     * An unconfigured mandaatregister requires manual confirmation, never silent pass.
     *
     * @return void
     */
    public function testUnconfiguredRegisterRequiresManualConfirmation(): void
    {
        $this->settingsService->method('getConfigValue')->willReturn('');

        // The HTTP client must never be used when there is no endpoint.
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service->validate('c1', 'piet');

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['requiresManualConfirmation']);
    }//end testUnconfiguredRegisterRequiresManualConfirmation()

    /**
     * A non-https endpoint is treated as unconfigured (manual confirmation).
     *
     * @return void
     */
    public function testInvalidEndpointSchemeRequiresManualConfirmation(): void
    {
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static fn (string $key): string => $key === 'mandaatregister_endpoint' ? 'ftp://bad' : '',
        );

        $result = $this->service->validate('c1', 'piet');
        $this->assertTrue($result['requiresManualConfirmation']);
    }//end testInvalidEndpointSchemeRequiresManualConfirmation()
}//end class
