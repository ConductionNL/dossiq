<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Listener\Registrar\CaseTermListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseTypeListenerRegistrar;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The statutory term's deadline and the applicant's note run on updates only,
 * the note after the deadline it reads.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseTermListenerRegistrar
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class CaseTermListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheTermListenersRunOnUpdatesAtTheirPriorities(): void {
		(new CaseTermListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectUpdatingEvent::class, CaseDeadlineFollowsTermListener::class, CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY],
				[ObjectUpdatingEvent::class, ApplicantTermNoteListener::class, CaseTypeListenerRegistrar::TERM_NOTE_PRIORITY],
			],
			$this->listeners
		);
	}//end testTheTermListenersRunOnUpdatesAtTheirPriorities()

	public function testNothingElseIsRegistered(): void {
		(new CaseTermListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame([], $this->services);
		self::assertCount(2, $this->listeners);
	}//end testNothingElseIsRegistered()
}//end class
