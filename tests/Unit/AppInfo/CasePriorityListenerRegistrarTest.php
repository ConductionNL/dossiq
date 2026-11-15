<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The priority and marker derivations run pre-persist on create and update,
 * the marker derivation below the priority one, so a case type that reads its
 * impact from the risk assessment has the priority derived in the same save.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CasePriorityListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar
 */
class CasePriorityListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testPriorityThenMarkerOnBothPrePersistEvents(): void {
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
	}//end testPriorityThenMarkerOnBothPrePersistEvents()

	public function testTheMarkerDerivationSitsBelowThePriorityDerivation(): void {
		self::assertLessThan(CasePriorityListenerRegistrar::DERIVATION_PRIORITY, CaseMarkerListenerRegistrar::DERIVATION_PRIORITY);

		(new CaseMarkerListenerRegistrar())->register(context: $this->recordingContext());
		self::assertSame(
			[
				[ObjectCreatingEvent::class, CaseMarkerDerivationListener::class, -300],
				[ObjectUpdatingEvent::class, CaseMarkerDerivationListener::class, -300],
			],
			$this->listeners
		);
	}//end testTheMarkerDerivationSitsBelowThePriorityDerivation()
}//end class
