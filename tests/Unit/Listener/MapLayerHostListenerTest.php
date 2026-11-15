<?php

/**
 * Unit tests for the map layer host listener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/gis-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\MapLayerHostListener;
use OCA\Dossiq\Service\Map\MapLayerHosts;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Which saves hand a layer's URL on.
 *
 * @covers \OCA\Dossiq\Listener\MapLayerHostListener
 */
class MapLayerHostListenerTest extends TestCase {

	/**
	 * The URLs handed to the hosts.
	 *
	 * @var array<int, string>
	 */
	private array $remembered = [];

	/**
	 * The listener as this user (null: the system), who is or is not an administrator.
	 *
	 * @param string|null $uid   The session user.
	 * @param bool        $admin Whether they are an administrator.
	 *
	 * @return MapLayerHostListener
	 */
	private function listener(?string $uid = 'admin', bool $admin = true): MapLayerHostListener {
		$this->remembered = [];
		$hosts = $this->createMock(MapLayerHosts::class);
		$hosts->method('remember')->willReturnCallback(
			function (string $url): bool {
				$this->remembered[] = $url;
				return true;
			}
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $admin && $id === $uid);

		return new MapLayerHostListener($hosts, $resolver, $session, $groups);
	}

	private function entity(array $record, string $schema = 'wmsLayer'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(array_merge(['@self' => ['id' => 'l-1', 'schema' => $schema]], $record));

		return $entity;
	}

	public function testAnAdministratorsNewOrChangedLayerHandsItsUrlOn(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity(['type' => 'ArcGIS', 'url' => 'https://gis.example.nl/MapServer'])));
		$layer = $this->entity(['url' => 'https://other.example.nl/wms', 'active' => true]);
		$listener->handle(new ObjectUpdatedEvent($layer, $layer));

		$this->assertSame(['https://gis.example.nl/MapServer', 'https://other.example.nl/wms'], $this->remembered);
	}

	public function testASystemSaveHandsItsUrlOn(): void {
		$listener = $this->listener(uid: null);
		$listener->handle(new ObjectCreatedEvent($this->entity(['url' => 'https://gis.example.nl/wms'])));

		$this->assertSame(['https://gis.example.nl/wms'], $this->remembered);
	}

	public function testANonAdministratorsSaveIsNotHandedOn(): void {
		$listener = $this->listener(uid: 'handler', admin: false);
		$listener->handle(new ObjectCreatedEvent($this->entity(['url' => 'https://gis.example.nl/wms'])));

		$this->assertSame([], $this->remembered);
	}

	public function testAnInactiveLayerAnotherSchemaOrADeleteIsNotHandedOn(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity(['url' => 'https://gis.example.nl/wms', 'active' => false])));
		$listener->handle(new ObjectCreatedEvent($this->entity(['url' => 'https://gis.example.nl/wms'], 'case')));
		$listener->handle(new ObjectDeletedEvent($this->entity(['url' => 'https://gis.example.nl/wms'])));

		$this->assertSame([], $this->remembered);
	}
}//end class
