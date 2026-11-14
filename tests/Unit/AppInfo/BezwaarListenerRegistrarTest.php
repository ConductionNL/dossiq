<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\BezwaarAdviceRequestedListener;
use OCA\Dossiq\Listener\BezwaarDecisionListener;
use OCA\Dossiq\Listener\BezwaarHearingScheduledListener;
use OCA\Dossiq\Listener\DeliberationStartGuard;
use OCA\Dossiq\Listener\Registrar\BezwaarListenerRegistrar;
use OCA\Dossiq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The objection status listeners follow updates, the deliberation guard runs
 * before both kinds of save, and one internal write marker is shared.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\BezwaarListenerRegistrar
 * @uses \OCA\Dossiq\Service\InternalWriteMarker
 */
class BezwaarListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheStatusListenersAndTheGuardAreRegistered(): void {
		(new BezwaarListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectUpdatedEvent::class, BezwaarAdviceRequestedListener::class, 0],
				[ObjectUpdatedEvent::class, BezwaarHearingScheduledListener::class, 0],
				[ObjectUpdatedEvent::class, BezwaarDecisionListener::class, 0],
				[ObjectCreatingEvent::class, DeliberationStartGuard::class, 0],
				[ObjectUpdatingEvent::class, DeliberationStartGuard::class, 0],
			],
			$this->listeners
		);
	}//end testTheStatusListenersAndTheGuardAreRegistered()

	public function testOneSharedInternalWriteMarkerIsRegistered(): void {
		(new BezwaarListenerRegistrar())->register(context: $this->recordingContext());

		self::assertCount(1, $this->services);
		[$name, $factory, $shared] = $this->services[0];
		self::assertSame(InternalWriteMarker::class, $name);
		self::assertTrue($shared, 'The marker is one per request, so a write marked by one service is seen by the listener.');
		self::assertInstanceOf(InternalWriteMarker::class, $factory());
	}//end testOneSharedInternalWriteMarkerIsRegistered()
}//end class
