<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Listener\CaseInheritedDeadlineListener;
use OCA\Dossiq\Listener\CaseTeamAccessListener;
use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Listener\CaseTypePortalWithdrawalListener;
use OCA\Dossiq\Listener\MilestoneDependencyCycleListener;
use OCA\Dossiq\Listener\Registrar\CaseTypeListenerRegistrar;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The case type guards run before both kinds of save, the inherited
 * deadline after them, the term-following deadline and the applicant note
 * after that, and team access after the save.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseTypeListenerRegistrar
 */
class CaseTypeListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testEveryListenerIsRegisteredWithItsPriority(): void {
		(new CaseTypeListenerRegistrar())->register(context: $this->recordingContext());

		$expected = [];
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$expected[] = [$event, CaseTypeParentCycleListener::class, 0];
			$expected[] = [$event, CaseTypePortalWithdrawalListener::class, 0];
			$expected[] = [$event, MilestoneDependencyCycleListener::class, 0];
			$expected[] = [$event, CaseInheritedDeadlineListener::class, -100];
		}

		$expected[] = [ObjectCreatedEvent::class, CaseTeamAccessListener::class, 0];
		$expected[] = [ObjectUpdatedEvent::class, CaseTeamAccessListener::class, 0];
		$expected[] = [ObjectUpdatingEvent::class, CaseDeadlineFollowsTermListener::class, -110];
		$expected[] = [ObjectUpdatingEvent::class, ApplicantTermNoteListener::class, -120];

		self::assertSame($expected, $this->listeners);
	}//end testEveryListenerIsRegisteredWithItsPriority()

	public function testTheDeadlineListenersRunInOrderAfterTheGuards(): void {
		// Lower runs later: the inherited deadline is set before the term moves it,
		// and the applicant's note is written last, about the deadline that stands.
		self::assertGreaterThan(CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY, CaseTypeListenerRegistrar::INHERITED_DEADLINE_PRIORITY);
		self::assertGreaterThan(CaseTypeListenerRegistrar::TERM_NOTE_PRIORITY, CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY);
		self::assertLessThan(0, CaseTypeListenerRegistrar::INHERITED_DEADLINE_PRIORITY);
	}//end testTheDeadlineListenersRunInOrderAfterTheGuards()
}//end class
