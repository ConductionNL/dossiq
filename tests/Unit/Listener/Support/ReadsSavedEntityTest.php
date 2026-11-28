<?php

/**
 * ReadsSavedEntity — the object a created or updated event saved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\ReadsSavedEntity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Created, updated and any other event.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReadsSavedEntity
 */
final class ReadsSavedEntityTest extends TestCase {

	/**
	 * Created and updated events answer their saved object, others null.
	 *
	 * @return void
	 */
	public function testReadsTheSavedEntity(): void {
		$subject = new class() {
			use ReadsSavedEntity {
				savedEntity as public;
			}
		};
		$created = $this->createMock(ObjectEntity::class);
		$updated = $this->createMock(ObjectEntity::class);
		$createdEvent = $this->createMock(ObjectCreatedEvent::class);
		$createdEvent->method('getObject')->willReturn($created);
		$updatedEvent = $this->createMock(ObjectUpdatedEvent::class);
		$updatedEvent->method('getNewObject')->willReturn($updated);

		self::assertSame($created, $subject->savedEntity($createdEvent));
		self::assertSame($updated, $subject->savedEntity($updatedEvent));
		self::assertNull($subject->savedEntity(new Event()));
	}//end testReadsTheSavedEntity()
}//end class
