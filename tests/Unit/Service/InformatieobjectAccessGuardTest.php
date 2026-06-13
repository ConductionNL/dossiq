<?php

/**
 * InformatieobjectAccessGuard Unit Tests
 *
 * Exercises the ZGW DRC vertrouwelijkheidaanduiding access matrix: every
 * confidentiality level crossed with authorized/unauthorized clearances,
 * admin override, group-mapped clearance, fail-closed on unknown levels,
 * publish thresholds, dossier filtering, and the more-restrictive-only rule.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T03
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for InformatieobjectAccessGuard.
 *
 * @covers \OCA\Procest\Service\InformatieobjectAccessGuard
 */
class InformatieobjectAccessGuardTest extends TestCase
{

    /**
     * Mocked settings service.
     *
     * @var SettingsService
     */
    private SettingsService $settings;

    /**
     * Mocked group manager.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Guard under test.
     *
     * @var InformatieobjectAccessGuard
     */
    private InformatieobjectAccessGuard $guard;


    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settings     = $this->createMock(SettingsService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->guard        = new InformatieobjectAccessGuard(
            settingsService: $this->settings,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Build a non-admin user with the given clearance default and groups.
     *
     * @param string   $defaultClearance The configured default clearance.
     * @param string   $groupMap         The configured group->level map string.
     * @param string[] $userGroups       The user's group ids.
     *
     * @return IUser The configured mock user.
     */
    private function userWith(string $defaultClearance, string $groupMap='', array $userGroups=[]): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular');
        $user->method('getDisplayName')->willReturn('Regular User');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroupIds')->willReturn($userGroups);

        $this->settings->method('getConfigValue')->willReturnMap([
            ['dossier_default_clearance', 'intern', $defaultClearance],
            ['dossier_clearance_group_map', '', $groupMap],
        ]);

        return $user;
    }//end userWith()


    /**
     * ordinalOf returns the hierarchy index and fails closed on unknown levels.
     *
     * @return void
     */
    public function testOrdinalOfFailsClosedOnUnknown(): void
    {
        $this->settings->method('getConfigValue')->willReturn('intern');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->assertSame(0, $this->guard->ordinalOf('openbaar'));
        $this->assertSame(2, $this->guard->ordinalOf('intern'));
        $this->assertSame(7, $this->guard->ordinalOf('zeer_geheim'));
        // Unknown / empty maps to the highest ordinal (most restrictive).
        $this->assertSame(7, $this->guard->ordinalOf('bogus'));
        $this->assertSame(7, $this->guard->ordinalOf(''));

    }//end testOrdinalOfFailsClosedOnUnknown()


    /**
     * Admin always has top clearance and can read everything.
     *
     * @return void
     */
    public function testAdminHasTopClearance(): void
    {
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->settings->method('getConfigValue')->willReturn('intern');

        $this->assertSame(7, $this->guard->getUserClearanceOrdinal($admin));
        $this->assertTrue($this->guard->canRead($admin, ['vertrouwelijkheidaanduiding' => 'zeer_geheim']));

    }//end testAdminHasTopClearance()


    /**
     * Full read matrix: each document level x each clearance level.
     *
     * @return void
     */
    public function testReadMatrixAcrossAllLevels(): void
    {
        $levels = InformatieobjectAccessGuard::HIERARCHY;

        foreach ($levels as $clearanceIndex => $clearance) {
            // Fresh guard per clearance so the willReturnMap is unambiguous.
            $settings     = $this->createMock(SettingsService::class);
            $groupManager = $this->createMock(IGroupManager::class);
            $groupManager->method('isAdmin')->willReturn(false);
            $groupManager->method('getUserGroupIds')->willReturn([]);
            $settings->method('getConfigValue')->willReturnMap([
                ['dossier_default_clearance', 'intern', $clearance],
                ['dossier_clearance_group_map', '', ''],
            ]);
            $guard = new InformatieobjectAccessGuard(
                settingsService: $settings,
                groupManager: $groupManager,
                logger: $this->createMock(LoggerInterface::class),
            );

            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('u');

            foreach ($levels as $docIndex => $docLevel) {
                $expected = ($clearanceIndex >= $docIndex);
                $actual   = $guard->canRead($user, ['vertrouwelijkheidaanduiding' => $docLevel]);
                $this->assertSame(
                    $expected,
                    $actual,
                    'clearance='.$clearance.' doc='.$docLevel,
                );
            }
        }//end foreach

    }//end testReadMatrixAcrossAllLevels()


    /**
     * A user below clearance cannot read; assertCanRead throws.
     *
     * @return void
     */
    public function testAssertCanReadThrowsBelowClearance(): void
    {
        $user = $this->userWith(defaultClearance: 'vertrouwelijk');

        $this->expectException(NotPermittedException::class);
        $this->guard->assertCanRead($user, ['vertrouwelijkheidaanduiding' => 'geheim']);

    }//end testAssertCanReadThrowsBelowClearance()


    /**
     * assertCanRead is silent when clearance is sufficient.
     *
     * @return void
     */
    public function testAssertCanReadPassesAtOrAboveClearance(): void
    {
        $user = $this->userWith(defaultClearance: 'geheim');

        $this->guard->assertCanRead($user, ['vertrouwelijkheidaanduiding' => 'geheim']);
        $this->guard->assertCanRead($user, ['vertrouwelijkheidaanduiding' => 'openbaar']);
        $this->addToAssertionCount(1);

    }//end testAssertCanReadPassesAtOrAboveClearance()


    /**
     * Group mapping raises clearance to the highest matched group level.
     *
     * @return void
     */
    public function testGroupMapRaisesClearance(): void
    {
        $user = $this->userWith(
            defaultClearance: 'intern',
            groupMap: 'vertrouwelijk-cleared:vertrouwelijk,geheim-cleared:geheim',
            userGroups: ['users', 'geheim-cleared'],
        );

        $this->assertSame(
            $this->guard->ordinalOf('geheim'),
            $this->guard->getUserClearanceOrdinal($user),
        );
        $this->assertTrue($this->guard->canRead($user, ['vertrouwelijkheidaanduiding' => 'geheim']));
        $this->assertFalse($this->guard->canRead($user, ['vertrouwelijkheidaanduiding' => 'zeer_geheim']));

    }//end testGroupMapRaisesClearance()


    /**
     * canPublish rejects documents at or above the vertrouwelijk threshold.
     *
     * @return void
     */
    public function testCanPublishThreshold(): void
    {
        $this->settings->method('getConfigValue')->willReturn('intern');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->assertTrue($this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'openbaar']));
        $this->assertTrue($this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'intern']));
        $this->assertTrue($this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk']));
        $this->assertFalse($this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'vertrouwelijk']));
        $this->assertFalse($this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'geheim']));

    }//end testCanPublishThreshold()


    /**
     * filterDossierForUser removes documents above the user's clearance.
     *
     * @return void
     */
    public function testFilterDossierForUser(): void
    {
        $user = $this->userWith(defaultClearance: 'intern');

        $docs = [
            ['id' => '1', 'vertrouwelijkheidaanduiding' => 'openbaar'],
            ['id' => '2', 'vertrouwelijkheidaanduiding' => 'intern'],
            ['id' => '3', 'vertrouwelijkheidaanduiding' => 'vertrouwelijk'],
            ['id' => '4', 'vertrouwelijkheidaanduiding' => 'geheim'],
        ];

        $filtered = $this->guard->filterDossierForUser($user, $docs);
        $ids      = array_column($filtered, 'id');

        $this->assertSame(['1', '2'], $ids);

    }//end testFilterDossierForUser()


    /**
     * isClassificationAllowed permits equal/more-restrictive but not less.
     *
     * @return void
     */
    public function testIsClassificationAllowedMoreRestrictiveOnly(): void
    {
        $this->settings->method('getConfigValue')->willReturn('intern');
        $this->groupManager->method('isAdmin')->willReturn(false);

        // Default = intern.
        $this->assertTrue($this->guard->isClassificationAllowed('intern', 'intern'));
        $this->assertTrue($this->guard->isClassificationAllowed('intern', 'geheim'));
        $this->assertFalse($this->guard->isClassificationAllowed('intern', 'openbaar'));
        // Empty requested falls back to allowed (use default).
        $this->assertTrue($this->guard->isClassificationAllowed('intern', ''));

    }//end testIsClassificationAllowedMoreRestrictiveOnly()
}//end class
