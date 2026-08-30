<?php

/**
 * StatusTransitionService OR-RBAC group-authorization tests.
 *
 * Verifies the role-routing enforcement that consumes the OR-RBAC group ids
 * frozen onto a transition's `authorization` list at publish time
 * (migrate-role-routing-to-or-rbac): an unauthorized group is rejected, an
 * authorized group passes, an empty/absent list is open, and admins bypass —
 * all via OR's single trusted IGroupManager membership check rather than a
 * bespoke role-resolution scheme.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TransitionAuthorizer::isTransitionGroupAuthorized(), the gate
 * StatusTransitionService delegates its role routing to.
 *
 * @covers \OCA\Dossiq\Service\Transitions\TransitionAuthorizer
 *
 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-5.1
 */
final class StatusTransitionGroupAuthTest extends TestCase {

	/**
	 * Build the authorizer with a group manager whose membership is driven by $memberships.
	 *
	 * @param array<string, array<int, string>> $memberships Map of uid => group ids the user belongs to.
	 *
	 * @return TransitionAuthorizer
	 */
	private function serviceWithGroups(array $memberships): TransitionAuthorizer {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($gid, ($memberships[$uid] ?? []), true)
		);

		return new TransitionAuthorizer(
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end serviceWithGroups()

	/**
	 * Invoke the group-authorization check.
	 *
	 * @param TransitionAuthorizer $service The authorizer under test.
	 * @param array<string, mixed> $transition The transition spec.
	 * @param string $userId The acting user.
	 *
	 * @return bool
	 */
	private function authorized(TransitionAuthorizer $service, array $transition, string $userId): bool {
		return $service->isTransitionGroupAuthorized(transition: $transition, userId: $userId);
	}//end authorized()

	/**
	 * A user not in the authorized group is rejected.
	 *
	 * @return void
	 */
	public function testUnauthorizedGroupIsRejected(): void {
		$service = $this->serviceWithGroups(['jan' => ['behandelaars']]);

		self::assertFalse(
			$this->authorized($service, ['authorization' => ['vergunningverleners']], 'jan')
		);
	}//end testUnauthorizedGroupIsRejected()

	/**
	 * A user in the authorized group passes.
	 *
	 * @return void
	 */
	public function testAuthorizedGroupPasses(): void {
		$service = $this->serviceWithGroups(['piet' => ['vergunningverleners']]);

		self::assertTrue(
			$this->authorized($service, ['authorization' => ['vergunningverleners']], 'piet')
		);
	}//end testAuthorizedGroupPasses()

	/**
	 * An empty or absent authorization list is open to everyone.
	 *
	 * @return void
	 */
	public function testEmptyAuthorizationIsOpen(): void {
		$service = $this->serviceWithGroups(['jan' => []]);

		self::assertTrue($this->authorized($service, ['authorization' => []], 'jan'));
		self::assertTrue($this->authorized($service, ['label' => 'Goedkeuren'], 'jan'));
	}//end testEmptyAuthorizationIsOpen()

	/**
	 * An anonymous caller can never satisfy a group gate.
	 *
	 * @return void
	 */
	public function testAnonymousCallerRejected(): void {
		$service = $this->serviceWithGroups([]);

		self::assertFalse(
			$this->authorized($service, ['authorization' => ['vergunningverleners']], '')
		);
	}//end testAnonymousCallerRejected()

	/**
	 * An admin bypasses the group gate.
	 *
	 * @return void
	 */
	public function testAdminBypassesGate(): void {
		$service = $this->serviceWithGroups(['boss' => ['admin']]);

		self::assertTrue(
			$this->authorized($service, ['authorization' => ['vergunningverleners']], 'boss')
		);
	}//end testAdminBypassesGate()
}//end class
