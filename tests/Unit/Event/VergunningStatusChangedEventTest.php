<?php

/**
 * VergunningStatusChangedEvent Unit Tests
 *
 * Tests for the VergunningStatusChangedEvent domain event, covering
 * constructor injection and all getter methods.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T15
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Event;

use OCA\Procest\Event\VergunningStatusChangedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VergunningStatusChangedEvent.
 *
 * @covers \OCA\Procest\Event\VergunningStatusChangedEvent
 */
class VergunningStatusChangedEventTest extends TestCase
{
    /**
     * Test that the event can be constructed and extends Event.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T15
     */
    public function testEventExtendsOcpEvent(): void
    {
        $event = new VergunningStatusChangedEvent(
            aanvraagRef: 'ref-001',
            oldStatus: 'ingediend',
            newStatus: 'in_behandeling',
            besluitdatum: null,
            toelichting: null,
            userId: 'user1',
        );

        $this->assertInstanceOf(Event::class, $event);
    }//end testEventExtendsOcpEvent()

    /**
     * Test that all getters return the values provided to the constructor.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T15
     */
    public function testGettersReturnConstructorValues(): void
    {
        $event = new VergunningStatusChangedEvent(
            aanvraagRef: 'aanvraag-abc-123',
            oldStatus: 'ingediend',
            newStatus: 'verleend',
            besluitdatum: '2026-06-01',
            toelichting: 'Voldoet aan alle eisen.',
            userId: 'behandelaar1',
        );

        $this->assertSame('aanvraag-abc-123', $event->getVergunningaanvraagRef());
        $this->assertSame('ingediend', $event->getOldStatus());
        $this->assertSame('verleend', $event->getNewStatus());
        $this->assertSame('2026-06-01', $event->getBesluitdatum());
        $this->assertSame('Voldoet aan alle eisen.', $event->getToelichting());
        $this->assertSame('behandelaar1', $event->getUserId());
    }//end testGettersReturnConstructorValues()

    /**
     * Test that nullable fields accept null values.
     *
     * besluitdatum and toelichting are optional and must be nullable.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T15
     */
    public function testNullableFieldsAcceptNull(): void
    {
        $event = new VergunningStatusChangedEvent(
            aanvraagRef: 'ref-002',
            oldStatus: 'in_behandeling',
            newStatus: 'geweigerd',
            besluitdatum: null,
            toelichting: null,
            userId: 'user2',
        );

        $this->assertNull($event->getBesluitdatum());
        $this->assertNull($event->getToelichting());
    }//end testNullableFieldsAcceptNull()
}//end class
