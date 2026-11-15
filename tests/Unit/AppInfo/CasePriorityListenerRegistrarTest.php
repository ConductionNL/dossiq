<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The priority derivation runs before both kinds of save, and the marker
 * derivation is registered with it, below it, so a priority read from the
 * risk assessment is derived in the same save.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar
 * @uses   \OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar
 */
class CasePriorityListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testThePriorityThenTheMarkerDerivationRunBeforeEverySave(): void {
		(new CasePriorityListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatingEvent::class, CasePriorityDerivationListener::class, -200],
				[ObjectUpdatingEvent::class, CasePriorityDerivationListener::class, -200],
				[ObjectCreatingEvent::class, CaseMarkerDerivationListener::class, -300],
				[ObjectUpdatingEvent::class, CaseMarkerDerivationListener::class, -300],
			],
			$this->listeners
		);
		self::assertSame([], $this->services);
	}//end testThePriorityThenTheMarkerDerivationRunBeforeEverySave()
}//end class
