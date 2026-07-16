<?php

/**
 * WOOAssessmentController authorization tests.
 *
 * The previous guard read:
 *
 *   if (groupExists('procest-gebruikers') === true
 *       && isInGroup($uid, 'procest-gebruikers') === false) { throw; }
 *
 * `procest-gebruikers` existed nowhere in the codebase, so `groupExists()`
 * returned false, the `&&` short-circuited, nothing was thrown, and EVERY
 * authenticated user could mutate ANY WOO case across all five
 * `#[NoAdminRequired]` endpoints — including statutory deadline extension.
 *
 * These tests prove the BAD path is now rejected on every one of those five
 * endpoints, and that the absent-group case does NOT grant access.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/authz-bypass-fixes/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\WOOAssessmentController;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\WOODecisionService;
use OCA\Procest\Service\WOODeadlineService;
use OCA\Procest\Service\WOODocumentAssessmentService;
use OCA\Procest\Service\WooPublicationService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService (named-argument safe).
 */
interface WooCaseObjectServiceStub
{
    /**
     * Find a single object by ID.
     *
     * @param int|string $id       Object UUID.
     * @param mixed      $register Register slug.
     * @param mixed      $schema   Schema slug.
     *
     * @return mixed
     */
    public function find(int | string $id, mixed $register=null, mixed $schema=null): mixed;
}//end interface

/**
 * Unit tests for WOOAssessmentController per-case authorization.
 *
 * @covers \OCA\Procest\Controller\WOOAssessmentController
 * @covers \OCA\Procest\Service\CaseAccessGuard
 */
class WOOAssessmentControllerAuthorizationTest extends TestCase
{

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupManager;

    /**
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $settingsService;

    /**
     * @var WooCaseObjectServiceStub|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var WOODocumentAssessmentService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $assessmentService;

    /**
     * @var WOODeadlineService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $deadlineService;

    /**
     * @var WOODecisionService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $decisionService;

    /**
     * @var WooPublicationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $publicationService;

    /**
     * Set up fixtures. The case under test is handled by `alice`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->settingsService    = $this->createMock(SettingsService::class);
        $this->objectService      = $this->createMock(WooCaseObjectServiceStub::class);
        $this->assessmentService  = $this->createMock(WOODocumentAssessmentService::class);
        $this->deadlineService    = $this->createMock(WOODeadlineService::class);
        $this->decisionService    = $this->createMock(WOODecisionService::class);
        $this->publicationService = $this->createMock(WooPublicationService::class);

        $this->settingsService->method('getObjectService')->willReturn($this->objectService);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return [
                    'register'    => 'procest',
                    'case_schema' => 'case',
                ][$key] ?? '';
            }
        );

        $this->objectService->method('find')->willReturn(['id' => 'case-1', 'assignee' => 'alice']);
    }//end setUp()

    /**
     * Build the controller for the given signed-in user.
     *
     * @param string $uid     The signed-in user id.
     * @param bool   $isAdmin Whether that user is an admin.
     *
     * @return WOOAssessmentController
     */
    private function controllerFor(string $uid, bool $isAdmin=false): WOOAssessmentController
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);

        $guard = new CaseAccessGuard(
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

        return new WOOAssessmentController(
            appName: 'procest',
            request: $this->createMock(IRequest::class),
            assessmentService: $this->assessmentService,
            deadlineService: $this->deadlineService,
            decisionService: $this->decisionService,
            publicationService: $this->publicationService,
            userSession: $this->userSession,
            caseAccessGuard: $guard,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end controllerFor()

    /**
     * Every WOO mutation endpoint, invoked on the controller.
     *
     * @return array<string, array{0: string}>
     */
    public static function mutationEndpointProvider(): array
    {
        return [
            'bulkAssess'          => ['bulkAssess'],
            'extendDeadline'      => ['extendDeadline'],
            'createDecision'      => ['createDecision'],
            'publishDecision'     => ['publishDecision'],
            'withdrawPublication' => ['withdrawPublication'],
        ];
    }//end mutationEndpointProvider()

    /**
     * THE FAIL-OPEN. An authenticated non-admin who does not handle the case is
     * rejected from every WOO mutation endpoint.
     *
     * Note `procest-gebruikers` is never configured to exist on the group
     * manager mock — i.e. this test runs in exactly the absent-group condition
     * that previously granted access to everyone.
     *
     * @param string $method The controller method under test.
     *
     * @return void
     *
     * @dataProvider mutationEndpointProvider
     */
    public function testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint(string $method): void
    {
        $controller = $this->controllerFor('mallory');

        // The bad path must never reach the underlying service.
        $this->assessmentService->expects($this->never())->method('bulkUpsert');
        $this->deadlineService->expects($this->never())->method('extendDeadline');
        $this->decisionService->expects($this->never())->method('assembleDecision');
        $this->publicationService->expects($this->never())->method('publish');
        $this->publicationService->expects($this->never())->method('withdraw');

        $this->expectException(OCSForbiddenException::class);

        $controller->$method('case-1');
    }//end testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint()

    /**
     * Statutory WOO deadline extension is rejected for an unrelated user.
     *
     * Called out separately because extending a statutory term is the
     * highest-impact of the five endpoints.
     *
     * @return void
     */
    public function testStatutoryDeadlineExtensionIsRejectedForUnrelatedUser(): void
    {
        $controller = $this->controllerFor('mallory');

        $this->deadlineService->expects($this->never())->method('extendDeadline');

        $this->expectException(OCSForbiddenException::class);

        $controller->extendDeadline('case-1');
    }//end testStatutoryDeadlineExtensionIsRejectedForUnrelatedUser()

    /**
     * The exact fail-open being closed: `procest-gebruikers` does not exist, and
     * that must NOT be treated as authorization.
     *
     * The guard must never consult group existence at all — asserting
     * `groupExists` is never called pins the short-circuit out of existence.
     *
     * @return void
     */
    public function testAbsentAuthorizationGroupDoesNotGrantAccess(): void
    {
        // The group is never stubbed to exist — this is exactly the absent-group
        // condition the old guard short-circuited on. Asserting the guard never
        // even asks about groups pins the short-circuit out of existence.
        $this->groupManager->expects($this->never())->method('groupExists');
        $this->groupManager->expects($this->never())->method('isInGroup');

        $controller = $this->controllerFor('mallory');

        $this->expectException(OCSForbiddenException::class);

        $controller->extendDeadline('case-1');
    }//end testAbsentAuthorizationGroupDoesNotGrantAccess()

    /**
     * OpenRegister unavailable must DENY, not skip the check.
     *
     * @return void
     */
    public function testOpenRegisterUnavailableDeniesRatherThanSkips(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);

        $guard = new CaseAccessGuard(
            settingsService: $settings,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('mallory');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(OCSForbiddenException::class);

        $guard->assertCaseMutationAccess('case-1', $user);
    }//end testOpenRegisterUnavailableDeniesRatherThanSkips()

    /**
     * An unresolvable case denies (and does not leak existence).
     *
     * @return void
     */
    public function testUnresolvableCaseDenies(): void
    {
        $objectService = $this->createMock(WooCaseObjectServiceStub::class);
        $objectService->method('find')->willReturn(null);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return ['register' => 'procest', 'case_schema' => 'case'][$key] ?? '';
            }
        );

        $guard = new CaseAccessGuard(
            settingsService: $settings,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(OCSForbiddenException::class);

        $guard->assertCaseMutationAccess('case-does-not-exist', $user);
    }//end testUnresolvableCaseDenies()

    /**
     * No functional regression: the case assignee is allowed through the guard.
     *
     * @return void
     */
    public function testCaseAssigneeIsAuthorized(): void
    {
        $guard = new CaseAccessGuard(
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->groupManager->method('isAdmin')->willReturn(false);

        $guard->assertCaseMutationAccess('case-1', $user);

        $this->assertTrue($guard->hasCaseMutationAccess('case-1', $user));
    }//end testCaseAssigneeIsAuthorized()

    /**
     * No functional regression: admins are allowed.
     *
     * @return void
     */
    public function testAdminIsAuthorized(): void
    {
        $guard = new CaseAccessGuard(
            settingsService: $this->settingsService,
            groupManager: $this->groupManager,
            logger: $this->createMock(LoggerInterface::class),
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('root');
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->assertTrue($guard->hasCaseMutationAccess('case-1', $user));
    }//end testAdminIsAuthorized()
}//end class
