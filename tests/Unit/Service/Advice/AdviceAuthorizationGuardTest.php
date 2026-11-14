<?php

/**
 * Who may move an advice request, and who may send its reminder.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard
 */
class AdviceAuthorizationGuardTest extends TestCase {
	/**
	 * The object service lookups made, as [id, register, schema].
	 *
	 * @var list<array{0: string, 1: int|string, 2: int|string}>
	 */
	private array $lookups = [];

	/**
	 * A guard for the given user, with the linked case answering as given.
	 *
	 * @param string|null          $uid     The logged-in user, or null.
	 * @param bool                 $admin   Whether that user is an admin.
	 * @param array<string, mixed>|null|false $case The case found; null = not found, false = no object service.
	 * @param array<string, string> $config The register and case schema config.
	 *
	 * @return AdviceAuthorizationGuard
	 */
	private function guard(
		?string $uid,
		bool $admin = false,
		array|null|false $case = null,
		array $config = ['register' => '5', 'case_schema' => 'case'],
	): AdviceAuthorizationGuard {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => ($admin === true && $id === $uid));

		$objectService = null;
		if ($case !== false) {
			$lookups = &$this->lookups;
			$objectService = new class($case, $lookups) {
				/**
				 * Constructor.
				 *
				 * @param array<string, mixed>|null $case    The case, or null when not found.
				 * @param array                     $lookups The lookups made.
				 */
				public function __construct(private ?array $case, private array &$lookups) {
				}//end __construct()

				/**
				 * Find one object.
				 *
				 * @param string     $id       The id.
				 * @param int|string $register The register.
				 * @param int|string $schema   The schema.
				 *
				 * @return array<string, mixed>
				 *
				 * @throws DoesNotExistException When the case is not there.
				 */
				public function find(string $id, int|string $register, int|string $schema): array {
					$this->lookups[] = [$id, $register, $schema];
					if ($this->case === null) {
						throw new DoesNotExistException('gone');
					}

					return $this->case;
				}//end find()
			};
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new AdviceAuthorizationGuard(settingsService: $settings, userSession: $session, groupManager: $groups);
	}//end guard()

	/**
	 * Whether the call is refused with "Advice request not accessible".
	 *
	 * @param callable $call The guarded call.
	 *
	 * @return bool True when refused, false when allowed.
	 */
	private function refused(callable $call): bool {
		try {
			$call();
		} catch (RuntimeException $e) {
			$this->assertSame('Advice request not accessible', $e->getMessage());
			return true;
		}

		return false;
	}//end refused()

	/**
	 * Without a logged-in user both checks refuse as unauthenticated.
	 *
	 * @return void
	 */
	public function testWithoutAUserBothChecksRefuse(): void {
		foreach ([
			fn () => $this->guard(uid: null)->assertTransitionAuthorized(advice: ['advisor' => 'a'], to: 'received'),
			fn () => $this->guard(uid: null)->assertReminderAuthorized(advice: ['advisor' => 'a']),
		] as $call) {
			try {
				$call();
				$this->fail('an anonymous call was allowed');
			} catch (RuntimeException $e) {
				$this->assertSame('Not authenticated', $e->getMessage());
			}
		}
	}//end testWithoutAUserBothChecksRefuse()

	/**
	 * An admin may do anything without the case being looked up.
	 *
	 * @return void
	 */
	public function testAnAdminIsAlwaysAllowed(): void {
		$guard = $this->guard(uid: 'root', admin: true, case: ['assignee' => 'someone']);

		$this->assertFalse($this->refused(fn () => $guard->assertTransitionAuthorized(advice: ['advisor' => 'x', 'case' => 'c1'], to: 'withdrawn')));
		$this->assertFalse($this->refused(fn () => $guard->assertReminderAuthorized(advice: ['case' => 'c1'])));
		$this->assertSame([], $this->lookups);
	}//end testAnAdminIsAlwaysAllowed()

	/**
	 * Only the advisor may mark advice received; the case handler may not.
	 *
	 * @return void
	 */
	public function testOnlyTheAdvisorMarksAdviceReceived(): void {
		$advice = ['advisor' => 'adv', 'case' => 'c1'];

		$this->assertFalse($this->refused(fn () => $this->guard(uid: 'adv')->assertTransitionAuthorized(advice: $advice, to: 'received')));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'])->assertTransitionAuthorized(advice: $advice, to: 'received')));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: '')->assertTransitionAuthorized(advice: ['advisor' => ''], to: 'received')));
	}//end testOnlyTheAdvisorMarksAdviceReceived()

	/**
	 * The advisor or the linked case's handler may move it back to requested;
	 * anyone else may not, and no other target is open to them.
	 *
	 * @return void
	 */
	public function testRequestedIsOpenToAdvisorAndHandlerOnly(): void {
		$advice = ['advisor' => 'adv', 'case' => 'c1'];

		$this->assertFalse($this->refused(fn () => $this->guard(uid: 'adv')->assertTransitionAuthorized(advice: $advice, to: 'requested')));
		$this->assertFalse($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'])->assertTransitionAuthorized(advice: $advice, to: 'requested')));
		$this->assertSame([['c1', '5', 'case']], $this->lookups);
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'other', case: ['assignee' => 'handler'])->assertTransitionAuthorized(advice: $advice, to: 'requested')));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'adv')->assertTransitionAuthorized(advice: $advice, to: 'withdrawn')));
	}//end testRequestedIsOpenToAdvisorAndHandlerOnly()

	/**
	 * The advisor or the linked case's handler may send a reminder.
	 *
	 * @return void
	 */
	public function testReminderIsOpenToAdvisorAndHandler(): void {
		$advice = ['advisor' => 'adv', 'case' => 'c1'];

		$this->assertFalse($this->refused(fn () => $this->guard(uid: 'adv')->assertReminderAuthorized(advice: $advice)));
		$this->assertFalse($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'])->assertReminderAuthorized(advice: $advice)));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'other', case: ['assignee' => 'handler'])->assertReminderAuthorized(advice: $advice)));
	}//end testReminderIsOpenToAdvisorAndHandler()

	/**
	 * The handler check fails closed: no linked case, no object service, no
	 * register or schema, a case that is gone, or a case without an assignee.
	 *
	 * @return void
	 */
	public function testTheHandlerCheckFailsClosed(): void {
		$withCase = ['advisor' => 'adv', 'case' => 'c1'];

		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'])->assertReminderAuthorized(advice: ['advisor' => 'adv'])));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: false)->assertReminderAuthorized(advice: $withCase)));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'], config: ['register' => '', 'case_schema' => 'case'])->assertReminderAuthorized(advice: $withCase)));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: ['assignee' => 'handler'], config: ['register' => '5', 'case_schema' => ''])->assertReminderAuthorized(advice: $withCase)));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: 'handler', case: null)->assertReminderAuthorized(advice: $withCase)));
		$this->assertTrue($this->refused(fn () => $this->guard(uid: '', case: ['assignee' => ''])->assertReminderAuthorized(advice: ['advisor' => 'adv', 'case' => 'c1'])));
		$this->assertSame([['c1', '5', 'case'], ['c1', '5', 'case']], $this->lookups);
	}//end testTheHandlerCheckFailsClosed()
}//end class
