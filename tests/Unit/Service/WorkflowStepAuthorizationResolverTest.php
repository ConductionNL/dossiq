<?php

/**
 * WorkflowStepAuthorizationResolver unit tests.
 *
 * Verifies the roleType → ncGroupId resolution that backs the OR-RBAC
 * per-transition authorization gate (migrate-role-routing-to-or-rbac): a
 * roleType with a configured `ncGroupId` resolves to that literal NC group id;
 * a roleType with a null/absent `ncGroupId` resolves to no group (open access).
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
use OCA\Procest\Service\WorkflowStepAuthorizationResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenRegister ObjectService shape used by the resolver.
 *
 * Declared as an interface so `createMock()` honours the named-argument
 * `find($id, register:, schema:)` signature the production code calls.
 */
interface AuthResolverObjectServiceStub
{

    /**
     * Load a single object by id within a register/schema.
     *
     * @param string $id       Object UUID.
     * @param string $register Register id.
     * @param string $schema   Schema id.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array;
}//end interface

/**
 * Tests for WorkflowStepAuthorizationResolver::resolveGroupIds().
 *
 * @covers \OCA\Procest\Service\WorkflowStepAuthorizationResolver
 *
 * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-6.1
 */
final class WorkflowStepAuthorizationResolverTest extends TestCase
{

    /**
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Map of roleType UUID => ncGroupId|null returned by the ObjectService stub.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $roleTypes = [];

    /**
     * Wire the resolver with a stubbed ObjectService driven by $this->roleTypes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $objectService = $this->createMock(AuthResolverObjectServiceStub::class);
        $objectService->method('find')->willReturnCallback(
            fn(string $id, string $register, string $schema): ?array => ($this->roleTypes[$id] ?? null)
        );

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'         => '1',
                    'role_type_schema' => '7',
                    default            => '',
                };
            }
        );
    }//end setUp()

    /**
     * Build the system under test.
     *
     * @return WorkflowStepAuthorizationResolver
     */
    private function resolver(): WorkflowStepAuthorizationResolver
    {
        return new WorkflowStepAuthorizationResolver($this->settingsService, $this->logger);
    }//end resolver()

    /**
     * assigneeRole pointing at a mapped roleType resolves to its group id.
     *
     * @return void
     */
    public function testResolvesAssigneeRoleToGroupId(): void
    {
        $this->roleTypes = ['role-a' => ['name' => 'Vergunningverlener', 'ncGroupId' => 'vergunningverleners']];

        $groups = $this->resolver()->resolveGroupIds(['assigneeRole' => 'role-a']);

        self::assertSame(['vergunningverleners'], $groups);
    }//end testResolvesAssigneeRoleToGroupId()

    /**
     * A roleType with a null ncGroupId resolves to no group (open access).
     *
     * @return void
     */
    public function testUnmappedRoleResolvesToNoGroup(): void
    {
        $this->roleTypes = ['role-b' => ['name' => 'Behandelaar', 'ncGroupId' => null]];

        $groups = $this->resolver()->resolveGroupIds(['assigneeRole' => 'role-b']);

        self::assertSame([], $groups);
    }//end testUnmappedRoleResolvesToNoGroup()

    /**
     * allowedRoles + routingRule roleTypes resolve to a de-duplicated union.
     *
     * @return void
     */
    public function testResolvesOrSetAndRoutingRuleUnion(): void
    {
        $this->roleTypes = [
            'role-a' => ['ncGroupId' => 'vergunningverleners'],
            'role-b' => ['ncGroupId' => 'behandelaars'],
            'role-c' => ['ncGroupId' => 'vergunningverleners'],
        ];

        $groups = $this->resolver()->resolveGroupIds(
            [
                'allowedRoles' => ['role-a', 'role-b'],
                'routingRule'  => ['strategy' => 'or-set', 'roleTypes' => ['role-c']],
            ]
        );

        sort($groups);
        self::assertSame(['behandelaars', 'vergunningverleners'], $groups);
    }//end testResolvesOrSetAndRoutingRuleUnion()

    /**
     * A transition with no role reference resolves to no group.
     *
     * @return void
     */
    public function testNoRoleReferenceResolvesToEmpty(): void
    {
        self::assertSame([], $this->resolver()->resolveGroupIds(['label' => 'Goedkeuren']));
    }//end testNoRoleReferenceResolvesToEmpty()
}//end class
