<?php

/**
 * ObjectAuditEvents unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ObjectAuditEvents;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\ObjectAuditEvents
 */
class ObjectAuditEventsTest extends TestCase {

	/**
	 * The event carries the object, action, context and the named actor.
	 *
	 * @return void
	 */
	public function testTheEventCarriesEverythingItWasGiven(): void {
		$object = new ObjectEntity();

		$event = ObjectAuditEvents::of(
			object: $object,
			action: 'dossiq.reassigned',
			context: ['from' => 'alice', 'to' => 'bob'],
			actorId: 'carol',
			actorName: 'Carol',
		);

		self::assertSame($object, $event->object);
		self::assertSame('dossiq.reassigned', $event->action);
		self::assertSame(['from' => 'alice', 'to' => 'bob'], $event->context);
		self::assertSame('carol', $event->options->actor->id);
		self::assertSame('Carol', $event->options->actor->name);
		self::assertNull($event->options->ipAddress);
	}//end testTheEventCarriesEverythingItWasGiven()

	/**
	 * Without an actor the event names nobody and carries no context.
	 *
	 * @return void
	 */
	public function testWithoutAnActorTheEventNamesNobody(): void {
		$event = ObjectAuditEvents::of(object: new ObjectEntity(), action: 'dossiq.viewed');

		self::assertSame([], $event->context);
		self::assertNull($event->options->actor->id);
		self::assertNull($event->options->actor->name);
	}//end testWithoutAnActorTheEventNamesNobody()
}//end class
