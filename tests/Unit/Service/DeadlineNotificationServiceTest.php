<?php

/**
 * Unit tests for DeadlineNotificationService.
 *
 * Asserts each template renders with the expected subject + body and
 * that the dispatcher attaches the recipient + instance metadata.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BerichtenboxRoutingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\DeadlineNotificationService;
use OCA\Procest\Service\DeadlineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\DeadlineNotificationService
 *
 * @uses \OCA\Procest\Service\BerichtenboxRoutingService
 * @uses \OCA\Procest\Service\DeadlineService
 */
class DeadlineNotificationServiceTest extends TestCase
{
    private DeadlineNotificationService $service;

    protected function setUp(): void
    {
        $objects   = new FakeTermijnStore();
        $settings  = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_instance_schema'    => 'termijnInstance',
                    default                      => '',
                };
            },
        );

        $objects->saveObject('procest', 'termijnInstance', [
            'id'                  => 'ti-1',
            'zaak'                => 'Z/2026/300',
            'einddatumActueel'    => '2026-07-27',
            'status'              => 'lopend',
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $this->service = new DeadlineNotificationService(
            new DeadlineService($settings, $logger),
            new BerichtenboxRoutingService($logger),
            $logger
        );
    }

    /**
     * @return void
     */
    public function testOntvangstbevestigingRendersZaakAndDeadline(): void
    {
        $payload = $this->service->sendTermijnNotification('ontvangstbevestiging', 'ti-1', 'burger-1');
        self::assertSame('Ontvangstbevestiging zaak Z/2026/300', $payload['subject']);
        self::assertStringContainsString('2026-07-27', $payload['body']);
        self::assertSame('burger-1', $payload['recipient']);
        self::assertSame('ti-1', $payload['termijnInstance']);
    }

    /**
     * @return void
     */
    public function testExtensionRendersNewDeadline(): void
    {
        $payload = $this->service->sendTermijnNotification(
            'extension',
            'ti-1',
            'burger-1',
            ['newEinddatum' => '2026-08-31']
        );
        self::assertStringContainsString('2026-08-31', $payload['body']);
    }

    /**
     * @return void
     */
    public function testIngebrekestellingReceiptRendersGraceEnd(): void
    {
        $payload = $this->service->sendTermijnNotification(
            'ingebrekestelling-receipt',
            'ti-1',
            'burger-1',
            ['graceEnd' => '2026-08-12']
        );
        self::assertStringContainsString('2026-08-12', $payload['body']);
        self::assertStringContainsString('AWB 4:17', $payload['body']);
    }

    /**
     * @return void
     */
    public function testDwangsomPaymentRendersEuroAmount(): void
    {
        $payload = $this->service->sendTermijnNotification(
            'dwangsom-payment',
            'ti-1',
            'burger-1',
            ['bedragCents' => 35700, 'betalingsreferentie' => 'ERP-XYZ-987']
        );
        self::assertStringContainsString('EUR 357,00', $payload['body']);
        self::assertStringContainsString('ERP-XYZ-987', $payload['body']);
    }

    /**
     * @return void
     */
    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->sendTermijnNotification('nope', 'ti-1', 'burger-1');
    }
}
