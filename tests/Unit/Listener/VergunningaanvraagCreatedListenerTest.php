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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
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
 * Unit tests for VergunningaanvraagCreatedListener.
 *
 * @covers \OCA\Procest\Listener\VergunningaanvraagCreatedListener
 */
class VergunningaanvraagCreatedListenerTest extends TestCase
{

    /**
     * Mocked DSO case service.
     *
     * @var DsoCaseService|\PHPUnit\Framework\MockObject\MockObject
     */
    private DsoCaseService $dsoCaseService;

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The listener under test.
     *
     * @var VergunningaanvraagCreatedListener
     */
    private VergunningaanvraagCreatedListener $listener;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->dsoCaseService  = $this->createMock(DsoCaseService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters

        $this->listener = new VergunningaanvraagCreatedListener(
            dsoCaseService: $this->dsoCaseService,
            settingsService: $this->settingsService,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that a non-ObjectCreatedEvent is ignored.
     *
     * @return void
     */
    public function testNonObjectCreatedEventIsIgnored(): void
    {
        $this->dsoCaseService
            ->expects($this->never())
            ->method('createZaakFromVergunningaanvraag');

        $this->listener->handle(event: new Event());

    }//end testNonObjectCreatedEventIsIgnored()

    /**
     * Test that an ObjectCreatedEvent for a non-vergunningaanvraag schema is ignored.
     *
     * @return void
     */
    public function testNonVergunningaanvraagSchemaIsIgnored(): void
    {
        if (class_exists(ObjectCreatedEvent::class) === false) {
            // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $this->markTestSkipped('OCA\OpenRegister\Event\ObjectCreatedEvent not available.');
            // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        }

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $event = $this->createMock(ObjectCreatedEvent::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $event->method('getObject')->willReturn(
            [
                '@self' => ['schema' => 'case', 'id' => 'test-id'],
            ]
        );

        $this->dsoCaseService
            ->expects($this->never())
            ->method('createZaakFromVergunningaanvraag');

        $this->listener->handle(event: $event);

    }//end testNonVergunningaanvraagSchemaIsIgnored()

    /**
     * Test that a vergunningaanvraag ObjectCreatedEvent triggers zaak creation.
     *
     * @return void
     */
    public function testVergunningaanvraagEventTriggersZaakCreation(): void
    {
        if (class_exists(ObjectCreatedEvent::class) === false) {
            // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
            $this->markTestSkipped('OCA\OpenRegister\Event\ObjectCreatedEvent not available.');
            // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        }

        // phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $event = $this->createMock(ObjectCreatedEvent::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $event->method('getObject')->willReturn(
            [
                '@self' => ['schema' => 'vergunningaanvraag', 'id' => 'aanvraag-uuid-999'],
            ]
        );

        $this->dsoCaseService
            ->expects($this->once())
            ->method('createZaakFromVergunningaanvraag')
            ->with('aanvraag-uuid-999');

        $this->listener->handle(event: $event);

    }//end testVergunningaanvraagEventTriggersZaakCreation()
}//end class
