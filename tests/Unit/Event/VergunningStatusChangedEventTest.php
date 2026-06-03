<?php

/**
 * VergunningStatusChangedEvent Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Event;

use OCA\Procest\Event\VergunningStatusChangedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VergunningStatusChangedEvent.
 *
 * @covers \OCA\Procest\Event\VergunningStatusChangedEvent
 */
class VergunningStatusChangedEventTest extends TestCase
{
    /**
     * Test that the event carries all expected values.
     *
     * @return void
     */
    public function testEventCarriesAllExpectedValues(): void
    {
        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: 'aanvraag-uuid-001',
            oldStatus: 'ingediend',
            newStatus: 'in_behandeling',
            besluitdatum: null,
            toelichting: null,
            userId: 'behandelaar'
        );

        $this->assertSame(expected: 'aanvraag-uuid-001', actual: $event->getVergunningaanvraagRef());
        $this->assertSame(expected: 'ingediend', actual: $event->getOldStatus());
        $this->assertSame(expected: 'in_behandeling', actual: $event->getNewStatus());
        $this->assertNull(actual: $event->getBesluitdatum());
        $this->assertNull(actual: $event->getToelichting());
        $this->assertSame(expected: 'behandelaar', actual: $event->getUserId());

    }//end testEventCarriesAllExpectedValues()

    /**
     * Test that optional besluitdatum and toelichting are accessible.
     *
     * @return void
     */
    public function testEventWithOptionalFields(): void
    {
        $event = new VergunningStatusChangedEvent(
            vergunningaanvraagRef: 'aanvraag-uuid-002',
            oldStatus: 'in_behandeling',
            newStatus: 'verleend',
            besluitdatum: '2026-06-15',
            toelichting: 'Vergunning verleend.',
            userId: 'admin'
        );

        $this->assertSame(expected: '2026-06-15', actual: $event->getBesluitdatum());
        $this->assertSame(expected: 'Vergunning verleend.', actual: $event->getToelichting());

    }//end testEventWithOptionalFields()
}//end class
