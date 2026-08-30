<?php

/**
 * TenantSessionService Unit Tests
 *
 * This class is the tenancy boundary now: it decides which tenant a request
 * acts as, and it is the only place membership is checked. A regression here
 * does not throw — it returns another tenant's rows, correctly formatted, with
 * HTTP 200.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantSessionService;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\TenantSessionService
 */
class TenantSessionServiceTest extends TestCase {
	/**
	 * A session backed by a real array, so a write is observable by a read.
	 *
	 * A mock returning a fixed value would let `switchTo()` appear to work
	 * without storing anything — the store has to be real for these tests to
	 * mean what they say.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Build the service over a real store and a controllable membership list.
	 *
	 * @param array<string>|null $memberships Memberships, or null to throw.
	 * @param string|null        $uid         The signed-in uid, or null.
	 *
	 * @return TenantSessionService The service.
	 */
	private function newService(?array $memberships, ?string $uid = 'alice'): TenantSessionService {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(
			fn (string $key): mixed => ($this->store[$key] ?? null)
		);
		$session->method('set')->willReturnCallback(
			function (string $key, mixed $value): void {
				$this->store[$key] = $value;
			}
		);
		$session->method('remove')->willReturnCallback(
			function (string $key): void {
				unset($this->store[$key]);
			}
		);

		$users = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$users->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users->method('getUser')->willReturn($user);
		}

		$auth = $this->createMock(TenantAuthenticationService::class);
		if ($memberships === null) {
			$auth->method('listTenantsForUser')->willThrowException(new RuntimeException('backend down'));
			$auth->method('isMemberOf')->willThrowException(new RuntimeException('backend down'));
		} else {
			$auth->method('listTenantsForUser')->willReturn($memberships);
			$auth->method('isMemberOf')->willReturnCallback(
				static fn (string $tenantId, string $userId): bool => in_array($tenantId, $memberships, true)
			);
		}

		return new TenantSessionService(
			session: $session,
			users: $users,
			auth: $auth,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * THE ONE THAT MATTERS: a switch to a tenant you do not belong to is refused.
	 *
	 * @return void
	 */
	public function testSwitchingToATenantYouDoNotBelongToIsRefused(): void {
		$service = $this->newService(memberships: ['tenant-a']);

		$this->assertFalse($service->switchTo('tenant-b'));

		// The refusal has to be a refusal to BIND, not only a false return.
		// `tenant-a` still resolves here because it is the sole membership —
		// what must not have happened is `tenant-b` being stored anyway.
		$this->assertSame('tenant-a', $service->activeTenantId());
	}

	/**
	 * A switch to a tenant you do belong to is applied and readable back.
	 *
	 * @return void
	 */
	public function testSwitchingToAMembershipIsApplied(): void {
		$service = $this->newService(memberships: ['tenant-a', 'tenant-b']);

		$this->assertTrue($service->switchTo('tenant-b'));
		$this->assertSame('tenant-b', $service->activeTenantId());
	}

	/**
	 * Revoking a membership takes effect on the next request, not the next login.
	 *
	 * The stored choice is re-verified on every read rather than trusted. A
	 * session outlives the membership that justified it, so a service that
	 * trusted its own stored value would keep serving a tenant the user has
	 * been removed from — for as long as they stay logged in.
	 *
	 * @return void
	 */
	public function testARevokedMembershipStopsResolvingImmediately(): void {
		$service = $this->newService(memberships: ['tenant-a', 'tenant-b']);
		$this->assertTrue($service->switchTo('tenant-b'));
		$this->assertSame('tenant-b', $service->activeTenantId());

		// Same stored session, but the membership is gone.
		$revoked = $this->newService(memberships: ['tenant-a']);

		$this->assertNotSame('tenant-b', $revoked->activeTenantId());
	}

	/**
	 * A sole membership resolves without an explicit switch.
	 *
	 * Ordinary single-tenant use must not require a switch, or every such
	 * deployment would resolve to nothing.
	 *
	 * @return void
	 */
	public function testASoleMembershipResolvesWithoutASwitch(): void {
		$service = $this->newService(memberships: ['tenant-a']);

		$this->assertSame('tenant-a', $service->activeTenantId());
	}

	/**
	 * Several memberships and no choice resolves to NOTHING, not to the first.
	 *
	 * Picking one would be choosing a tenant on the user's behalf, which is the
	 * thing this class exists to stop. Returning `$memberships[0]` would pass a
	 * sole-membership test and quietly guess everywhere else.
	 *
	 * @return void
	 */
	public function testSeveralMembershipsWithNoChoiceResolveToNothing(): void {
		$service = $this->newService(memberships: ['tenant-a', 'tenant-b']);

		$this->assertNull($service->activeTenantId());
	}

	/**
	 * An anonymous caller resolves to nothing and cannot switch.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerHasNoTenant(): void {
		$service = $this->newService(memberships: ['tenant-a'], uid: null);

		$this->assertNull($service->activeTenantId());
		$this->assertFalse($service->switchTo('tenant-a'));
	}

	/**
	 * A membership lookup that fails binds nothing rather than falling open.
	 *
	 * An unreadable membership list is not "no restrictions". This is the
	 * failure mode where falling open is invisible: the backend blips, and
	 * every request in that window acts as whatever was stored.
	 *
	 * @return void
	 */
	public function testAFailedMembershipLookupResolvesToNothing(): void {
		$service = $this->newService(memberships: null);

		$this->assertNull($service->activeTenantId());
		$this->assertFalse($service->switchTo('tenant-a'));
	}

	/**
	 * An empty tenant id is not a switch.
	 *
	 * @return void
	 */
	public function testAnEmptyTenantIdIsRefused(): void {
		$service = $this->newService(memberships: ['tenant-a']);

		$this->assertFalse($service->switchTo('   '));
	}
}
