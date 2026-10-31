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
 * authenticated user could mutate ANY WOO case across every
 * `#[NoAdminRequired]` endpoint on this controller — including statutory
 * deadline extension.
 *
 * These tests prove the BAD path is now rejected on all SEVEN of them, and that
 * the absent-group case does NOT grant access. (The audit reported 3; there were
 * 5 at the time of writing, and `woo-llm-anonymisation` has since added 2 more
 * that silently inherited the same fail-open.)
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WOOAssessmentController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\HandlerActsAsSystem;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Typed stub for the OpenRegister ObjectService (named-argument safe).
 */
interface WooCaseObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * @param int|string $id Object UUID.
	 * @param mixed $register Register slug.
	 * @param mixed $schema Schema slug.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;
}//end interface

/**
 * Unit tests for WOOAssessmentController per-case authorization.
 *
 * @covers \OCA\Dossiq\Controller\WOOAssessmentController
 * @covers \OCA\Dossiq\Service\CaseAccessGuard
 * @covers \OCA\Dossiq\Service\Cases\HandlerActsAsSystem
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class WOOAssessmentControllerAuthorizationTest extends TestCase {

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
	 * @var WOOAnonymisationAssistService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $anonymisationAssist;

	/**
	 * Set up fixtures. The case under test is handled by `alice`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->objectService = $this->createMock(WooCaseObjectServiceStub::class);
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->deadlineService = $this->createMock(WOODeadlineService::class);
		$this->decisionService = $this->createMock(WOODecisionService::class);
		$this->publicationService = $this->createMock(WooPublicationService::class);
		$this->anonymisationAssist = $this->createMock(WOOAnonymisationAssistService::class);

		$this->settingsService->method('getObjectService')->willReturn($this->objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return [
					'register' => 'dossiq',
					'case_schema' => 'case',
				][$key] ?? '';
			}
		);

		$this->objectService->method('find')->willReturn(['id' => 'case-1', 'assignee' => 'alice']);
	}//end setUp()

	/**
	 * Build the controller for the given signed-in user.
	 *
	 * @param string $uid The signed-in user id.
	 * @param bool $isAdmin Whether that user is an admin.
	 *
	 * @return WOOAssessmentController
	 */
	private function controllerFor(string $uid, bool $isAdmin = false, string $publicationStatus = 'ready'): WOOAssessmentController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);

		$guard = new CaseAccessGuard(
			settingsService: $this->settingsService,
			groupManager: $this->groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-1', 'assignee' => 'alice', 'wooPublicationStatus' => $publicationStatus]);

		return new WOOAssessmentController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			assessmentService: $this->assessmentService,
			deadlineService: $this->deadlineService,
			decisionService: $this->decisionService,
			publicationService: $this->publicationService,
			anonymisationAssist: $this->anonymisationAssist,
			userSession: $this->userSession,
			caseAccessGuard: $guard,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $this->createMock(IL10N::class),
			handlerActs: new HandlerActsAsSystem(guard: $guard, store: $store, settings: $this->settingsService),
		);
	}//end controllerFor()

	/**
	 * Every `#[NoAdminRequired]` WOO mutation endpoint, with its arguments.
	 *
	 * All of them route through `requireCaseMutationAccess()`, so all of them
	 * inherited the fail-open. `proposeRedaction` / `reviewRedactionProposal`
	 * arrived later (woo-llm-anonymisation) and inherited it too — the blast
	 * radius grows every time an endpoint is added to this controller, which is
	 * exactly why the guard itself must fail closed.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function mutationEndpointProvider(): array {
		return [
			'bulkAssess' => ['bulkAssess', ['case-1']],
			'extendDeadline' => ['extendDeadline', ['case-1']],
			'createDecision' => ['createDecision', ['case-1']],
			'proposeRedaction' => ['proposeRedaction', ['case-1', 'doc-1']],
			'reviewRedactionProposal' => ['reviewRedactionProposal', ['case-1', 'doc-1']],
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
	 * @param array<int, string> $args The method arguments.
	 *
	 * @return void
	 *
	 * @dataProvider mutationEndpointProvider
	 */
	public function testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint(string $method, array $args): void {
		$controller = $this->controllerFor('mallory');

		// The bad path must never reach the underlying service.
		$this->assessmentService->expects($this->never())->method('bulkUpsert');
		$this->deadlineService->expects($this->never())->method('extendDeadline');
		$this->decisionService->expects($this->never())->method('assembleDecision');
		$this->publicationService->expects($this->never())->method('publish');
		$this->publicationService->expects($this->never())->method('withdraw');
		$this->anonymisationAssist->expects($this->never())->method('proposeSpans');
		$this->anonymisationAssist->expects($this->never())->method('reviewProposal');

		$this->expectException(OCSForbiddenException::class);

		$controller->$method(...$args);
	}//end testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint()

	/**
	 * Publishing and withdrawing are refused to a user who is not the case's
	 * handler, with a 403 in the user's language rather than an exception the
	 * framework turns into English, and the service is never reached.
	 *
	 * @param string $method The controller method under test.
	 *
	 * @return void
	 *
	 * @dataProvider publicationEndpointProvider
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function testANonHandlerIsRefusedPublicationWithA403(string $method): void {
		$this->publicationService->expects($this->never())->method('publish');
		$this->publicationService->expects($this->never())->method('withdraw');

		$response = $this->controllerFor('mallory', publicationStatus: 'published')->$method('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('not-the-handler', $response->getData()['error']);
	}//end testANonHandlerIsRefusedPublicationWithA403()

	/**
	 * The two publication endpoints.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function publicationEndpointProvider(): array {
		return ['publishDecision' => ['publishDecision'], 'withdrawPublication' => ['withdrawPublication']];
	}//end publicationEndpointProvider()

	/**
	 * Control: the case's handler publishes, and the publish runs as the system.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function testTheHandlerPublishesAsTheSystem(): void {
		$inSystem = null;
		$objects = new class {
			/** @var bool */
			public bool $system = false;

			/**
			 * @param int|string $id       The id.
			 * @param mixed      $register The register.
			 * @param mixed      $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(int|string $id, mixed $register = null, mixed $schema = null): array {
				return ['id' => 'case-1', 'assignee' => 'alice'];
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->system = true;
				try {
					return $operation();
				} finally {
					$this->system = false;
				}
			}
		};
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getObjectService')->willReturn($objects);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);
		$this->publicationService->expects($this->once())->method('publish')->willReturnCallback(
			function () use ($objects, &$inSystem): array {
				$inSystem = $objects->system;
				return ['available' => true, 'publicationId' => 'pub-1'];
			}
		);

		$response = $this->controllerFor('alice')->publishDecision('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($inSystem);
	}//end testTheHandlerPublishesAsTheSystem()

	/**
	 * The handler is refused with a 422 when the case is not at the
	 * publication step: no decision ready to publish.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function testTheHandlerIsRefusedBeforeThePublicationStep(): void {
		$this->publicationService->expects($this->never())->method('publish');

		$response = $this->controllerFor('alice', publicationStatus: '')->publishDecision('case-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('not-at-this-step', $response->getData()['error']);
	}//end testTheHandlerIsRefusedBeforeThePublicationStep()

	/**
	 * Statutory WOO deadline extension is rejected for an unrelated user.
	 *
	 * Called out separately because extending a statutory term is the
	 * highest-impact of the five endpoints.
	 *
	 * @return void
	 */
	public function testStatutoryDeadlineExtensionIsRejectedForUnrelatedUser(): void {
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
	public function testAbsentAuthorizationGroupDoesNotGrantAccess(): void {
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
	public function testOpenRegisterUnavailableDeniesRatherThanSkips(): void {
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
	public function testUnresolvableCaseDenies(): void {
		$objectService = $this->createMock(WooCaseObjectServiceStub::class);
		$objectService->method('find')->willReturn(null);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '';
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
	public function testCaseAssigneeIsAuthorized(): void {
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
	public function testAdminIsAuthorized(): void {
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
