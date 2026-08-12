<?php

/**
 * CaseAccessGuard::hasCaseReadAccess() Unit Tests
 *
 * The read predicate added for the gate-7 IDOR remediation. Written so the BAD
 * path is the thing under test: every branch that cannot establish a real
 * relationship between the caller and the case must DENY, and in particular the
 * three branches on which `Sharing\CaseAccessPolicy::canUserAccessCase()` grants
 * access (OpenRegister absent, schema unconfigured, lookup throws) must deny
 * here.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the per-case read predicate.
 *
 * @covers \OCA\Procest\Service\CaseAccessGuard
 */
class CaseAccessGuardReadAccessTest extends TestCase
{

    private SettingsService $settingsService;

    private IGroupManager $groupManager;

    private LoggerInterface $logger;

    /**
     * Set up shared collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->groupManager->method('isAdmin')->willReturn(false);
    }//end setUp()

    /**
     * Build a user double.
     *
     * @param string $uid The user id.
     *
     * @return IUser The user double.
     */
    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }//end user()

    /**
     * Configure the settings double to return a working OR object service that
     * resolves the case to the given payload.
     *
     * `find()` is what `SearchesObjects::findObjectAsArray()` calls; returning
     * a plain array mirrors the array branch of that helper.
     *
     * @param array<string, mixed>|null $case The case payload, or null for "not found".
     *
     * @return void
     */
    private function givenCase(?array $case): void
    {
        $objectService = new class($case) {

            /**
             * @param array<string, mixed>|null $case The case payload.
             */
            public function __construct(private readonly ?array $case)
            {
            }

            /**
             * Mimic ObjectService::find().
             *
             * @param string $id       The object id.
             * @param mixed  $register The register.
             * @param mixed  $schema   The schema.
             *
             * @return array<string, mixed>|null The object.
             */
            public function find(string $id, mixed $register=null, mixed $schema=null): ?array
            {
                return $this->case;
            }
        };

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', '14'],
                ['case_schema', '', '24'],
            ]
        );
    }//end givenCase()

    /**
     * Build the guard under test.
     *
     * @return CaseAccessGuard The guard.
     */
    private function guard(): CaseAccessGuard
    {
        return new CaseAccessGuard(
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );
    }//end guard()

    /**
     * The named assignee may read the case.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testAssigneeMayRead(): void
    {
        $this->givenCase(['id' => 'case-1', 'assignee' => 'alice']);

        $this->assertTrue($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
    }//end testAssigneeMayRead()

    /**
     * A member of the `assignees` array may read the case.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testAssigneesArrayMemberMayRead(): void
    {
        $this->givenCase(['id' => 'case-1', 'assignee' => 'alice', 'assignees' => ['bob', 'carol']]);

        $this->assertTrue($this->guard()->hasCaseReadAccess('case-1', $this->user('bob')));
    }//end testAssigneesArrayMemberMayRead()

    /**
     * An unrelated authenticated user may not read the case.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testUnrelatedUserMayNotRead(): void
    {
        $this->givenCase(['id' => 'case-1', 'assignee' => 'alice', 'assignees' => ['bob']]);

        $this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('mallory')));
    }//end testUnrelatedUserMayNotRead()

    /**
     * A case that cannot be resolved denies rather than skipping the check.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testUnresolvableCaseDenies(): void
    {
        $this->givenCase(null);

        $this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
    }//end testUnresolvableCaseDenies()

    /**
     * An absent OpenRegister denies — this is the first of the three branches
     * on which `CaseAccessPolicy::canUserAccessCase()` returns TRUE.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testAbsentOpenRegisterDenies(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
    }//end testAbsentOpenRegisterDenies()

    /**
     * An unconfigured case schema denies — the second fail-open branch of
     * `CaseAccessPolicy`.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testUnconfiguredSchemaDenies(): void
    {
        $objectService = new \stdClass();
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturn('');

        $this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
    }//end testUnconfiguredSchemaDenies()

    /**
     * A lookup that throws denies — the third fail-open branch of
     * `CaseAccessPolicy`.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testThrowingLookupDenies(): void
    {
        $objectService = new class {

            /**
             * Mimic an ObjectService whose backend is down.
             *
             * @param string $id       The object id.
             * @param mixed  $register The register.
             * @param mixed  $schema   The schema.
             *
             * @return array<string, mixed> Never returns.
             *
             * @throws \RuntimeException Always.
             */
            public function find(string $id, mixed $register=null, mixed $schema=null): array
            {
                throw new \RuntimeException('backend down');
            }
        };

        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', '14'],
                ['case_schema', '', '24'],
            ]
        );

        $this->assertFalse($this->guard()->hasCaseReadAccess('case-1', $this->user('alice')));
    }//end testThrowingLookupDenies()

    /**
     * An empty case id or an empty uid denies before anything is loaded.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testEmptyIdentifiersDeny(): void
    {
        $this->settingsService->expects($this->never())->method('getObjectService');

        $guard = $this->guard();

        $this->assertFalse($guard->hasCaseReadAccess('', $this->user('alice')));
        $this->assertFalse($guard->hasCaseReadAccess('case-1', $this->user('')));
    }//end testEmptyIdentifiersDeny()

    /**
     * A Nextcloud admin may read any case.
     *
     * @return void
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function testAdminMayRead(): void
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $guard = new CaseAccessGuard(
            settingsService: $this->settingsService,
            groupManager: $groupManager,
            logger: $this->logger,
        );

        $this->assertTrue($guard->hasCaseReadAccess('case-1', $this->user('admin')));
    }//end testAdminMayRead()
}//end class
