<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The marker derivation runs before both kinds of save, at its own priority.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar
 */
class CaseMarkerListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheDerivationRunsBeforeCreatesAndUpdates(): void {
		(new CaseMarkerListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatingEvent::class, CaseMarkerDerivationListener::class, -300],
				[ObjectUpdatingEvent::class, CaseMarkerDerivationListener::class, -300],
			],
			$this->listeners
		);
		self::assertSame([], $this->services);
	}//end testTheDerivationRunsBeforeCreatesAndUpdates()
}//end class
