<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\ContactMomentPipelinqListener;
use OCA\Dossiq\Listener\ContactMomentTimelineListener;
use OCA\Dossiq\Listener\PortalMessageTimelineListener;
use OCA\Dossiq\Listener\Registrar\ContactListenerRegistrar;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * A logged contact moment and a resident's portal message both cross one
 * create event: the timeline, pipelinq's record and the portal message
 * writer each listen to it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\ContactListenerRegistrar
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class ContactListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testAllThreeWritersListenToTheCreateEvent(): void {
		(new ContactListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatedEvent::class, ContactMomentTimelineListener::class, 0],
				[ObjectCreatedEvent::class, ContactMomentPipelinqListener::class, 0],
				[ObjectCreatedEvent::class, PortalMessageTimelineListener::class, 0],
			],
			$this->listeners
		);
		self::assertSame([], $this->services);
	}//end testAllThreeWritersListenToTheCreateEvent()
}//end class
