<?php

/**
 * VergunningaanvraagCreatedListener Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Procest\Listener\VergunningaanvraagCreatedListener;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Listener\VergunningaanvraagCreatedListener
 */
class VergunningaanvraagCreatedListenerTest extends TestCase
{

    private DsoCaseService $dsoCaseService;

    private SettingsService $settingsService;

    private LoggerInterface $logger;

    private VergunningaanvraagCreatedListener $listener;

    protected function setUp(): void
    {
        $this->dsoCaseService  = $this->createMock(DsoCaseService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->listener = new VergunningaanvraagCreatedListener(
            dsoCaseService: $this->dsoCaseService,
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Listener ignores non-ObjectCreatedEvent events.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testListenerIgnoresNonObjectCreatedEvent(): void
    {
        $event = new class extends Event {
        };

        $this->dsoCaseService
            ->expects($this->never())
            ->method('createZaakFromVergunningaanvraag');

        $this->listener->handle($event);
    }//end testListenerIgnoresNonObjectCreatedEvent()

    /**
     * Listener ignores ObjectCreatedEvent with wrong schema slug.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testListenerIgnoresWrongSchemaSlug(): void
    {
        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn(
                [
                    'uuid'  => 'test-id',
                    '@self' => ['schemaSlug' => 'complaint'],
                ]
                );

        $this->dsoCaseService
            ->expects($this->never())
            ->method('createZaakFromVergunningaanvraag');

        $this->listener->handle($event);
    }//end testListenerIgnoresWrongSchemaSlug()

    /**
     * Listener calls createZaakFromVergunningaanvraag for vergunningaanvraag events.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#V02
     */
    public function testListenerCreatesZaakForVergunningaanvraagEvent(): void
    {
        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn(
                [
                    'uuid'  => 'aanvraag-uuid-001',
                    '@self' => ['schemaSlug' => 'vergunningaanvraag'],
                ]
                );

        $this->dsoCaseService
            ->expects($this->once())
            ->method('createZaakFromVergunningaanvraag')
            ->with(vergunningaanvraagId: 'aanvraag-uuid-001')
            ->willReturn(['uuid' => 'zaak-uuid-001', 'title' => 'Omgevingsvergunning']);

        $this->listener->handle($event);
    }//end testListenerCreatesZaakForVergunningaanvraagEvent()
}//end class
