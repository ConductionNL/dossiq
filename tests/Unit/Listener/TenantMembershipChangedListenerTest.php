<?php

/**
 * TenantMembershipChangedListener unit tests
 *
 * A create, update or delete of a tenantUser row drops the kept memberships of
 * the users it names, before and after the change; any other object or event
 * leaves them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TenantMembershipChangedListener;
use OCA\Dossiq\Service\Tenant\TenantMembershipCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for dropping kept memberships.
 *
 * @covers \OCA\Dossiq\Listener\TenantMembershipChangedListener
 */
class TenantMembershipChangedListenerTest extends TestCase {

	/**
	 * An object carrying the given data.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);

		return $object;
	}//end objectWith()

	/**
	 * Joining, moving and leaving a tenant drop the users the rows name.
	 *
	 * @return void
	 */
	public function testATenantUserChangeDropsTheUsersItNames(): void {
		$forgotten = [];
		$cache = $this->createMock(originalClassName: TenantMembershipCache::class);
		$cache->method('forget')->willReturnCallback(static function (string $userId) use (&$forgotten): void {
			$forgotten[] = $userId;
		});
		$listener = new TenantMembershipChangedListener(memberships: $cache);

		$listener->handle(new ObjectCreatedEvent($this->objectWith(data: ['tenantRef' => 't-1', 'userRef' => 'alice'])));
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->objectWith(data: ['tenantRef' => 't-1', 'userRef' => 'carol']),
				$this->objectWith(data: ['tenantRef' => 't-1', 'userRef' => 'bob'])
			)
		);
		$listener->handle(new ObjectDeletedEvent($this->objectWith(data: ['tenantRef' => 't-2', 'userRef' => ' dave '])));

		$this->assertSame(expected: ['alice', 'carol', 'bob', 'dave'], actual: $forgotten);
	}//end testATenantUserChangeDropsTheUsersItNames()

	/**
	 * A row without a tenant reference, without a user, or another event drops nothing.
	 *
	 * @return void
	 */
	public function testAnyOtherChangeDropsNothing(): void {
		$cache = $this->createMock(originalClassName: TenantMembershipCache::class);
		$cache->expects($this->never())->method('forget');
		$listener = new TenantMembershipChangedListener(memberships: $cache);

		$listener->handle(new ObjectCreatedEvent($this->objectWith(data: ['userRef' => 'alice', 'supplier' => 's-1'])));
		$listener->handle(new ObjectCreatedEvent($this->objectWith(data: ['tenantRef' => 't-1', 'userRef' => ''])));
		$listener->handle(new Event());
	}//end testAnyOtherChangeDropsNothing()
}//end class
