<?php

/**
 * Who may see and manage a substitution
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ownership, involvement and coordinator rights, and the visible listing.
 *
 * @covers \OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class SubstitutionAccessGuardTest extends TestCase {
	/**
	 * The store calls made, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * A guard over a store, a session user and a set of admins.
	 *
	 * @param array<int, array<string, mixed>>|RuntimeException $rows      The stored substitutions, or the error a search throws.
	 * @param string|null                                       $sessionUid The logged-in user, or null.
	 * @param array<int, string>                                $admins    The admin user ids.
	 * @param array<string, string>                             $config    Config overrides.
	 * @param bool                                              $noObjects Whether OpenRegister is absent.
	 *
	 * @return SubstitutionAccessGuard
	 */
	private function guard(
		array|RuntimeException $rows = [],
		?string $sessionUid = null,
		array $admins = [],
		array $config = [],
		bool $noObjects = false,
	): SubstitutionAccessGuard {
		$config += ['register' => 'dossiq', 'substitution_schema' => 'substitution'];
		$test = $this;
		$store = new class ($test, $rows) {
			/**
			 * Constructor.
			 *
			 * @param SubstitutionAccessGuardTest                         $test The test.
			 * @param array<int, array<string, mixed>>|RuntimeException $rows The answer.
			 */
			public function __construct(private SubstitutionAccessGuardTest $test, private array|RuntimeException $rows) {
			}

			/**
			 * The stored rows, or the configured error.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->calls[] = ['search', ['register' => $register, 'schema' => $schema, 'filters' => $filters]];
				if ($this->rows instanceof RuntimeException) {
					throw $this->rows;
				}

				return $this->rows;
			}

			/**
			 * One stored row by id.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, string $register, string $schema): array {
				$this->test->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if (is_array($this->rows) === true) {
					foreach ($this->rows as $row) {
						if (($row['id'] ?? '') === $id) {
							return $row;
						}
					}
				}

				throw new DoesNotExistException('not found');
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noObjects ? null : $store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? '');

		$session = $this->createMock(IUserSession::class);
		if ($sessionUid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));

		return new SubstitutionAccessGuard(settingsService: $settings, userSession: $session, groupManager: $groups);
	}//end guard()

	/**
	 * The current uid comes from the session; no session is the empty uid.
	 *
	 * @return void
	 */
	public function testCurrentUid(): void {
		$this->assertSame('anna', $this->guard([], 'anna')->currentUid());
		$this->assertSame('', $this->guard()->currentUid());
	}//end testCurrentUid()

	/**
	 * Only an admin is a coordinator, and the empty uid never asks the group manager.
	 *
	 * @return void
	 */
	public function testIsCoordinator(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('isAdmin')->with('root')->willReturn(true);
		$guard = new SubstitutionAccessGuard(
			settingsService: $this->createMock(SettingsService::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $groups
		);

		$this->assertFalse($guard->isCoordinator(''));
		$this->assertTrue($guard->isCoordinator('root'));
	}//end testIsCoordinator()

	/**
	 * The forbidden response is a 403 carrying the message.
	 *
	 * @return void
	 */
	public function testForbidden(): void {
		$guard = $this->guard();

		$default = $guard->forbidden();
		$this->assertSame(Http::STATUS_FORBIDDEN, $default->getStatus());
		$this->assertSame(['error' => 'Not authorised'], $default->getData());
		$this->assertSame(['error' => 'Only the absentee may release'], $guard->forbidden('Only the absentee may release')->getData());
	}//end testForbidden()

	/**
	 * The absentee, the creator and a coordinator may manage; the substitute and others may not.
	 *
	 * @return void
	 */
	public function testMayManage(): void {
		$row = ['absentee' => 'anna', 'substitute' => 'bram', 'createdBy' => 'carla'];
		$guard = $this->guard([], null, ['root']);

		$this->assertTrue($guard->mayManage($row, 'anna'));
		$this->assertTrue($guard->mayManage($row, 'carla'));
		$this->assertTrue($guard->mayManage($row, 'root'));
		$this->assertFalse($guard->mayManage($row, 'bram'));
		$this->assertFalse($guard->mayManage($row, 'dirk'));
	}//end testMayManage()

	/**
	 * Everyone involved, and a coordinator, may view; an outsider may not.
	 *
	 * @return void
	 */
	public function testMayView(): void {
		$row = ['absentee' => 'anna', 'substitute' => 'bram', 'createdBy' => 'carla'];
		$guard = $this->guard([], null, ['root']);

		$this->assertTrue($guard->mayView($row, 'anna'));
		$this->assertTrue($guard->mayView($row, 'bram'));
		$this->assertTrue($guard->mayView($row, 'carla'));
		$this->assertTrue($guard->mayView($row, 'root'));
		$this->assertFalse($guard->mayView($row, 'dirk'));
	}//end testMayView()

	/**
	 * A coordinator sees every row; a handler only those they are absentee or substitute on, reindexed.
	 *
	 * @return void
	 */
	public function testListVisibleTo(): void {
		$rows = [
			['id' => 's1', 'absentee' => 'anna', 'substitute' => 'bram', 'createdBy' => 'root'],
			['id' => 's2', 'absentee' => 'dirk', 'substitute' => 'emma', 'createdBy' => 'bram'],
			['id' => 's3', 'absentee' => 'emma', 'substitute' => 'bram', 'createdBy' => 'emma'],
		];
		$guard = $this->guard($rows, null, ['root']);

		$this->assertSame($rows, $guard->listVisibleTo('root'));
		$this->assertSame([$rows[0], $rows[2]], $guard->listVisibleTo('bram'));
		$this->assertSame([], $guard->listVisibleTo('carla'));
		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'substitution', 'filters' => ['_limit' => 200]],
			$this->calls[0][1]
		);
	}//end testListVisibleTo()

	/**
	 * A failing store, a missing service or missing config lists nothing.
	 *
	 * @return void
	 */
	public function testListingDegradesToEmpty(): void {
		$this->assertSame([], $this->guard(new RuntimeException('down'), null, ['root'])->listVisibleTo('root'));
		$this->assertSame([], $this->guard([['absentee' => 'anna']], null, [], [], true)->listVisibleTo('anna'));
		$this->assertSame([], $this->guard([['absentee' => 'anna']], null, [], ['substitution_schema' => ''])->listVisibleTo('anna'));
		$this->assertSame([], $this->guard([['absentee' => 'anna']], null, [], ['register' => ''])->listVisibleTo('anna'));
	}//end testListingDegradesToEmpty()

	/**
	 * Find returns the stored row, null when absent, and null without config.
	 *
	 * @return void
	 */
	public function testFind(): void {
		$guard = $this->guard([['id' => 's1', 'absentee' => 'anna']]);

		$this->assertSame(['id' => 's1', 'absentee' => 'anna'], $guard->find('s1'));
		$this->assertSame(['find', ['id' => 's1', 'register' => 'dossiq', 'schema' => 'substitution']], $this->calls[0]);
		$this->assertNull($guard->find('missing'));

		$this->calls = [];
		$this->assertNull($this->guard([['id' => 's1']], null, [], ['substitution_schema' => ''])->find('s1'));
		$this->assertNull($this->guard([['id' => 's1']], null, [], [], true)->find('s1'));
		$this->assertSame([], $this->calls);
	}//end testFind()
}//end class
