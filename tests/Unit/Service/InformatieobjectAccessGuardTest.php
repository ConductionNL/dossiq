<?php

/**
 * InformatieobjectAccessGuard Unit Tests
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InformatieobjectAccessGuard;
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

    private IGroupManager $groupManager;
    private LoggerInterface $logger;
    private InformatieobjectAccessGuard $guard;

    protected function setUp(): void
    {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->guard = new InformatieobjectAccessGuard(
            groupManager: $this->groupManager,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that openbaar documents are accessible to any user.
     *
     * @return void
     */
    public function testCanReadOpenbaarIsAlwaysTrue(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->guard->canRead(
            user: $user,
            informatieobject: ['vertrouwelijkheidaanduiding' => 'openbaar'],
        );

        $this->assertTrue($result);
    }//end testCanReadOpenbaarIsAlwaysTrue()

    /**
     * Test that intern documents are readable by non-admin users (index <= 2).
     *
     * @return void
     */
    public function testCanReadInternReturnsTrueForRegularUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->guard->canRead(
            user: $user,
            informatieobject: ['vertrouwelijkheidaanduiding' => 'intern'],
        );

        $this->assertTrue($result);
    }//end testCanReadInternReturnsTrueForRegularUser()

    /**
     * Test that geheim documents are blocked for non-admin users without clearance.
     *
     * @return void
     */
    public function testCanReadGeheimReturnsFalseWithoutClearance(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->guard->canRead(
            user: $user,
            informatieobject: ['vertrouwelijkheidaanduiding' => 'geheim'],
        );

        $this->assertFalse($result);
    }//end testCanReadGeheimReturnsFalseWithoutClearance()

    /**
     * Test that admins can always read any document.
     *
     * @return void
     */
    public function testAdminCanReadAnyDocument(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->groupManager->method('isAdmin')->willReturn(true);

        $result = $this->guard->canRead(
            user: $user,
            informatieobject: ['vertrouwelijkheidaanduiding' => 'zeer_geheim'],
        );

        $this->assertTrue($result);
    }//end testAdminCanReadAnyDocument()

    /**
     * Test canPublish rejects vertrouwelijk and above.
     *
     * @return void
     */
    public function testCanPublishRejectsVertrouwelijk(): void
    {
        $this->assertFalse(
            $this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'vertrouwelijk'])
        );
    }//end testCanPublishRejectsVertrouwelijk()

    /**
     * Test canPublish allows intern.
     *
     * @return void
     */
    public function testCanPublishAllowsIntern(): void
    {
        $this->assertTrue(
            $this->guard->canPublish(['vertrouwelijkheidaanduiding' => 'intern'])
        );
    }//end testCanPublishAllowsIntern()

    /**
     * Test filterDossierForUser removes inaccessible records.
     *
     * @return void
     */
    public function testFilterDossierForUserRemovesInaccessibleRecords(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $docs = [
            ['id' => '1', 'vertrouwelijkheidaanduiding' => 'openbaar'],
            ['id' => '2', 'vertrouwelijkheidaanduiding' => 'intern'],
            ['id' => '3', 'vertrouwelijkheidaanduiding' => 'geheim'],
        ];

        $filtered = $this->guard->filterDossierForUser(user: $user, informatieobjecten: $docs);

        $this->assertCount(2, $filtered);
        $ids = array_column($filtered, 'id');
        $this->assertContains('1', $ids);
        $this->assertContains('2', $ids);
        $this->assertNotContains('3', $ids);
    }//end testFilterDossierForUserRemovesInaccessibleRecords()

    /**
     * Test requireRead throws for inaccessible document.
     *
     * @return void
     */
    public function testRequireReadThrowsForInaccessibleDocument(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular');

        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $this->expectException(\OCP\Files\NotPermittedException::class);

        $this->guard->requireRead(
            user: $user,
            informatieobject: ['vertrouwelijkheidaanduiding' => 'geheim'],
        );
    }//end testRequireReadThrowsForInaccessibleDocument()

}//end class
