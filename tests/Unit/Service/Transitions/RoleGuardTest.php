<?php

/**
 * RoleGuard Unit Tests
 *
 * Verifies the guard accepts users through the case.roles[] direct
 * assignment path, falls back to Nextcloud group membership, and reports a
 * silent failure (`details.silent: true`) so the UI hides forbidden
 * transitions per REQ-STE-2-003.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\Transitions\RoleGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\Transitions\RoleGuard
 *
 * @uses \OCA\Procest\Service\Transitions\GuardResult
 */
class RoleGuardTest extends TestCase
{
    /**
     * @return void
     */
    public function testPassesWhenNoAllowedRolesConfigured(): void
    {
        $guard = $this->buildGuard();

        $result = $guard->evaluate(
            guardConfig: [],
            case: ['id' => 'c'],
            userId: 'alice',
        );

        self::assertTrue($result->passed);
    }//end testPassesWhenNoAllowedRolesConfigured()

    /**
     * @return void
     */
    public function testFailsSilentlyWhenNoUserId(): void
    {
        $guard = $this->buildGuard();

        $result = $guard->evaluate(
            guardConfig: ['allowedRoles' => ['Behandelaar']],
            case: ['id' => 'c'],
            userId: '',
        );

        self::assertFalse($result->passed);
        self::assertTrue($result->details['silent']);
    }//end testFailsSilentlyWhenNoUserId()

    /**
     * @return void
     */
    public function testPassesOnDirectRoleAssignment(): void
    {
        $guard = $this->buildGuard();

        $result = $guard->evaluate(
            guardConfig: ['allowedRoles' => ['Behandelaar', 'Afdelingshoofd']],
            case: [
                'roles' => [
                    ['userId' => 'alice', 'role' => 'Behandelaar'],
                ],
            ],
            userId: 'alice',
        );

        self::assertTrue($result->passed);
        self::assertSame('Behandelaar', $result->details['matchedRole']);
    }//end testPassesOnDirectRoleAssignment()

    /**
     * @return void
     */
    public function testFallsBackToGroupMembership(): void
    {
        $user = $this->createMock(IUser::class);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            fn (string $uid, string $gid): bool => $uid === 'alice' && $gid === 'behandelaar'
        );

        $guard = new RoleGuard(
            groupManager: $groupManager,
            userManager: $userManager,
            logger: new NullLogger(),
        );

        $result = $guard->evaluate(
            guardConfig: ['allowedRoles' => ['Behandelaar']],
            case: ['id' => 'c'],
            userId: 'alice',
        );

        self::assertTrue($result->passed);
        self::assertSame('Behandelaar', $result->details['matchedRole']);
        self::assertSame('group', $result->details['via']);
    }//end testFallsBackToGroupMembership()

    /**
     * @return void
     */
    public function testFailsSilentlyOnRoleMismatch(): void
    {
        $guard = $this->buildGuard();

        $result = $guard->evaluate(
            guardConfig: ['allowedRoles' => ['Behandelaar']],
            case: [
                'roles' => [
                    ['userId' => 'alice', 'role' => 'Aanvrager'],
                ],
            ],
            userId: 'alice',
        );

        self::assertFalse($result->passed);
        self::assertTrue($result->details['silent']);
        self::assertSame(['Behandelaar'], $result->details['allowedRoles']);
    }//end testFailsSilentlyOnRoleMismatch()

    /**
     * Build a RoleGuard with manager mocks that return null/false (no group
     * membership, no resolvable user).
     *
     * @return RoleGuard
     */
    private function buildGuard(): RoleGuard
    {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn(null);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturn(false);

        return new RoleGuard(
            groupManager: $groupManager,
            userManager: $userManager,
            logger: new NullLogger(),
        );
    }//end buildGuard()
}//end class
