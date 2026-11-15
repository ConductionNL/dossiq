<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\DerivedStatusListener;
use OCA\Dossiq\Listener\DerivedStatusTimelineListener;
use OCA\Dossiq\Listener\Registrar\DerivedStatusListenerRegistrar;
use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The derived status is worked out late in the pre-save chain and put on the
 * timeline after the save, through one journal.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\DerivedStatusListenerRegistrar
 * @uses \OCA\Dossiq\Service\Status\DerivedStatusJournal
 */
class DerivedStatusListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheDerivationRunsLateBeforeTheSaveAndTheTimelineAfter(): void {
		(new DerivedStatusListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectUpdatingEvent::class, DerivedStatusListener::class, -300],
				[ObjectUpdatedEvent::class, DerivedStatusTimelineListener::class, 0],
			],
			$this->listeners
		);
		self::assertSame(-300, DerivedStatusListenerRegistrar::DERIVATION_PRIORITY);
	}//end testTheDerivationRunsLateBeforeTheSaveAndTheTimelineAfter()

	public function testTheJournalIsRegisteredAsAService(): void {
		(new DerivedStatusListenerRegistrar())->register(context: $this->recordingContext());

		self::assertCount(1, $this->services);
		self::assertSame(DerivedStatusJournal::class, $this->services[0][0]);
		self::assertInstanceOf(DerivedStatusJournal::class, ($this->services[0][1])());
	}//end testTheJournalIsRegisteredAsAService()
}//end class
