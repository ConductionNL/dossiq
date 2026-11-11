<?php

/**
 * Golden record of the KPI cache invalidation on object events.
 *
 * Records, per world, what the listener reads and writes in the cache and
 * every log line, so a refactor cannot change which events move a user's KPI
 * cache version, or to what.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\KpiCacheInvalidationListener;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for KpiCacheInvalidationListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\KpiCacheInvalidationListener
 */
class KpiCacheInvalidationGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the listener distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testInvalidationAcrossWorlds(): void {
		$entity = new ObjectEntity();
		$worlds = [
			'an unrelated event, signed in' => [new Event(), true, null],
			'a created object, nobody signed in' => [new ObjectCreatedEvent($entity), false, null],
			'a created object, first time' => [new ObjectCreatedEvent($entity), true, null],
			'an updated object' => [new ObjectUpdatedEvent($entity), true, 3],
			'a deleted object, version stored as text' => [new ObjectDeletedEvent($entity), true, '7'],
			'the cache fails' => [new ObjectCreatedEvent($entity), true, 'throw'],
		];

		$record = [];
		foreach ($worlds as $name => [$event, $signedIn, $stored]) {
			$this->events = [];
			$this->listenerFor(signedIn: $signedIn, stored: $stored)->handle($event);
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'kpi-cache-invalidation', value: $record);
	}//end testInvalidationAcrossWorlds()

	/**
	 * The listener of one world.
	 *
	 * @param bool  $signedIn Whether a user is signed in.
	 * @param mixed $stored   The stored version, or 'throw'.
	 *
	 * @return KpiCacheInvalidationListener
	 */
	private function listenerFor(bool $signedIn, mixed $stored): KpiCacheInvalidationListener {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use ($stored): mixed {
				$this->events[] = 'get ' . $key;
				if ($stored === 'throw') {
					throw new RuntimeException('cache down');
				}

				return $stored;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->events[] = 'set ' . $key . ' = ' . json_encode($value);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->events[] = 'debug: ' . $message . ' ' . json_encode($context);
			}
		);

		return new KpiCacheInvalidationListener($factory, $session, $logger);
	}//end listenerFor()
}//end class
