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
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\StatusTransitionService;
use OCA\Procest\Service\Transitions\GuardRegistry;
use OCA\Procest\Service\Transitions\SideEffectDispatcher;
use OCA\Procest\Service\WorkflowTemplateLoader;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for StatusTransitionService::isTransitionGroupAuthorized().
 *
 * @covers \OCA\Procest\Service\StatusTransitionService
 *
 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-6.2
 */
final class StatusTransitionGroupAuthTest extends TestCase
{

    /**
     * Build the service with a group manager whose membership is driven by $memberships.
     *
     * @param array<string, array<int, string>> $memberships Map of uid => group ids the user belongs to.
     *
     * @return StatusTransitionService
     */
    private function serviceWithGroups(array $memberships): StatusTransitionService
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isInGroup')->willReturnCallback(
            static fn(string $uid, string $gid): bool => in_array($gid, ($memberships[$uid] ?? []), true)
        );

        return new StatusTransitionService(
            $this->createMock(SettingsService::class),
            $this->createMock(WorkflowTemplateLoader::class),
            $this->createMock(GuardRegistry::class),
            $this->createMock(SideEffectDispatcher::class),
            $this->createMock(IUserSession::class),
            $groupManager,
            $this->createMock(LoggerInterface::class),
        );
    }//end serviceWithGroups()

    /**
     * Invoke the private group-authorization check.
     *
     * @param StatusTransitionService $service    The service under test.
     * @param array<string, mixed>    $transition The transition spec.
     * @param string                  $userId     The acting user.
     *
     * @return bool
     */
    private function authorized(StatusTransitionService $service, array $transition, string $userId): bool
    {
        $method = new ReflectionMethod(StatusTransitionService::class, 'isTransitionGroupAuthorized');
        $method->setAccessible(true);
        return (bool) $method->invoke($service, $transition, $userId);
    }//end authorized()

    /**
     * A user not in the authorized group is rejected.
     *
     * @return void
     */
    public function testUnauthorizedGroupIsRejected(): void
    {
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
    public function testAuthorizedGroupPasses(): void
    {
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
    public function testEmptyAuthorizationIsOpen(): void
    {
        $service = $this->serviceWithGroups(['jan' => []]);

        self::assertTrue($this->authorized($service, ['authorization' => []], 'jan'));
        self::assertTrue($this->authorized($service, ['label' => 'Goedkeuren'], 'jan'));
    }//end testEmptyAuthorizationIsOpen()

    /**
     * An anonymous caller can never satisfy a group gate.
     *
     * @return void
     */
    public function testAnonymousCallerRejected(): void
    {
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
    public function testAdminBypassesGate(): void
    {
        $service = $this->serviceWithGroups(['boss' => ['admin']]);

        self::assertTrue(
            $this->authorized($service, ['authorization' => ['vergunningverleners']], 'boss')
        );
    }//end testAdminBypassesGate()
}//end class
