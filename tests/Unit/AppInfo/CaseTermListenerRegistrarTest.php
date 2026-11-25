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

	/**
	 * Both listeners run on updates, the note after the deadline.
	 *
	 * @return void
	 */
	public function testTheTermListenersRunOnUpdatesAtTheirPriorities(): void {
		(new CaseTermListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			expected: [
				[ObjectUpdatingEvent::class, CaseDeadlineFollowsTermListener::class, CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY],
				[ObjectUpdatingEvent::class, ApplicantTermNoteListener::class, CaseTypeListenerRegistrar::TERM_NOTE_PRIORITY],
			],
			actual: $this->listeners
		);
	}//end testTheTermListenersRunOnUpdatesAtTheirPriorities()

	/**
	 * The registrar registers no services and no other listeners.
	 *
	 * @return void
	 */
	public function testNothingElseIsRegistered(): void {
		(new CaseTermListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(expected: [], actual: $this->services);
		self::assertCount(expectedCount: 2, haystack: $this->listeners);
	}//end testNothingElseIsRegistered()
}//end class
