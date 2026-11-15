<?php

/**
 * Unit tests for the DSO notifiers: forwarding a permit application raises the
 * named generic event with the case and the forwarding details, and a status
 * change raises the typed status-changed event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Event\VergunningStatusChangedEvent;
use OCA\Dossiq\Service\Dso\DsoDoorsturenNotifier;
use OCA\Dossiq\Service\Dso\DsoStatusChangeNotifier;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Dso\DsoDoorsturenNotifier
 * @covers \OCA\Dossiq\Service\Dso\DsoStatusChangeNotifier
 *
 * @uses \OCA\Dossiq\Event\VergunningStatusChangedEvent
 */
class DsoNotifiersTest extends TestCase {

	public function testForwardingRaisesTheNamedEventWithTheCaseAndDetails(): void {
		$case = ['id' => 'case-1', 'title' => 'Dakkapel'];
		$dispatched = [];
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->once())->method('dispatch')->willReturnCallback(
			static function (string $eventName, GenericEvent $event) use (&$dispatched): void {
				$dispatched = [$eventName, $event];
			}
		);

		(new DsoDoorsturenNotifier($events))->dispatchDoorgestuurd($case, 'case-1', 'provincie-utrecht', 'Provinciaal belang', 'alice');

		$this->assertSame('OCA\\Dossiq\\Event\\VergunningDoorgestuurd', $dispatched[0]);
		$this->assertSame($case, $dispatched[1]->getSubject());
		$this->assertSame(
			['caseId' => 'case-1', 'targetBevoegdGezag' => 'provincie-utrecht', 'reason' => 'Provinciaal belang', 'userId' => 'alice'],
			$dispatched[1]->getArguments()
		);
	}//end testForwardingRaisesTheNamedEventWithTheCaseAndDetails()

	public function testAStatusChangeRaisesTheTypedEvent(): void {
		$dispatched = null;
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (VergunningStatusChangedEvent $event) use (&$dispatched): void {
				$dispatched = $event;
			}
		);

		(new DsoStatusChangeNotifier($events))->dispatchStatusChanged('DSO-123', 'ontvangen', 'besloten', '2026-11-12', 'Verleend', 'alice');

		$this->assertSame(
			['DSO-123', 'ontvangen', 'besloten', '2026-11-12', 'Verleend', 'alice'],
			[$dispatched->getVergunningaanvraagRef(), $dispatched->getOldStatus(), $dispatched->getNewStatus(), $dispatched->getBesluitdatum(), $dispatched->getToelichting(), $dispatched->getUserId()]
		);
	}//end testAStatusChangeRaisesTheTypedEvent()

	public function testAStatusChangeWithoutDecisionDateOrNotesKeepsThemNull(): void {
		$dispatched = null;
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (VergunningStatusChangedEvent $event) use (&$dispatched): void {
				$dispatched = $event;
			}
		);

		(new DsoStatusChangeNotifier($events))->dispatchStatusChanged('DSO-123', 'ontvangen', 'in_behandeling', null, null, 'alice');

		$this->assertNull($dispatched->getBesluitdatum());
		$this->assertNull($dispatched->getToelichting());
	}//end testAStatusChangeWithoutDecisionDateOrNotesKeepsThemNull()
}//end class
