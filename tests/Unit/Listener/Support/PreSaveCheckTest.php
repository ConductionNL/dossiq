<?php

/**
 * PreSaveCheck.
 *
 * The pre-save plumbing the case type, milestone and location listeners
 * share: which object a create or an update saves, and its payload, with an
 * unreadable payload logged at debug level as the listeners logged it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which object is inspected, and how its payload reads.
 *
 * @covers \OCA\Dossiq\Listener\Support\PreSaveCheck
 */
class PreSaveCheckTest extends TestCase {

	/**
	 * A create inspects the new object, an update the updated one, anything else nothing.
	 *
	 * @return void
	 */
	public function testTheSavedObjectIsInspected(): void {
		$check = new PreSaveCheck($this->createMock(LoggerInterface::class));
		$created = new ObjectEntity();
		$before = new ObjectEntity();
		$after = new ObjectEntity();
		$seen = [];
		$inspect = function (Event $event, ObjectEntity $entity) use (&$seen): void {
			$seen[] = [$event, $entity];
		};

		$creating = new ObjectCreatingEvent($created);
		$updating = new ObjectUpdatingEvent($after, $before);
		$check->inspect($creating, $inspect);
		$check->inspect($updating, $inspect);
		$check->inspect(new Event(), $inspect);

		$this->assertSame([[$creating, $created], [$updating, $after]], $seen);
	}//end testTheSavedObjectIsInspected()

	/**
	 * A readable payload is returned; an unreadable one is logged and reads as null.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadIsLoggedAndNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Dossiq: milestone cycle check could not read the payload: broken');
		$check = new PreSaveCheck($logger);

		$readable = new ObjectEntity();
		$readable->setObject(['title' => 'Mijlpaal']);
		$this->assertSame('Mijlpaal', $check->read($readable, 'milestone cycle check')['title']);

		$unreadable = new class extends ObjectEntity {
			/**
			 * A payload that cannot be read.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('broken');
			}//end jsonSerialize()
		};
		$this->assertNull($check->read($unreadable, 'milestone cycle check'));
	}//end testAnUnreadablePayloadIsLoggedAndNull()
}//end class
