<?php

/**
 * EmailTemplateController::seedDefaults() Unit Tests
 *
 * Covers the endpoint that exposes EmailTemplateService::seedDefaultTemplates()
 * — the authentication gate, the created-count passthrough, the idempotent
 * second call, and RuntimeException mapping to 400.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\EmailTemplateController;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for EmailTemplateController::seedDefaults().
 *
 * @covers \OCA\Procest\Controller\EmailTemplateController
 */
final class EmailTemplateControllerSeedDefaultsTest extends TestCase {

	/**
	 * Inbound request.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * Backend templating service.
	 *
	 * @var EmailTemplateService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private EmailTemplateService $templateService;

	/**
	 * Current user session.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Group manager (admin check on config writes).
	 *
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var EmailTemplateController
	 */
	private EmailTemplateController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->templateService = $this->createMock(EmailTemplateService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->groupManager = $this->createMock(IGroupManager::class);
		// Seeding default templates is a config write, so the default caller
		// in these fixtures is an admin. `testNonAdminIsRejected()` below is
		// the arm that proves the check can refuse.
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->controller = new EmailTemplateController(
			request: $this->request,
			templateService: $this->templateService,
			settingsService: $this->createMock(SettingsService::class),
			appConfig: $this->createMock(IAppConfig::class),
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
		);
	}//end setUp()

	/**
	 * A non-admin authenticated caller cannot seed templates, and the service
	 * is never reached.
	 *
	 * An email template's body is mailed to citizens; before this it could be
	 * created by every authenticated account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testNonAdminIsRejected(): void {
		$this->authenticate();

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$templateService = $this->createMock(EmailTemplateService::class);
		$templateService->expects($this->never())->method('seedDefaultTemplates');

		$controller = new EmailTemplateController(
			request: $this->request,
			templateService: $templateService,
			settingsService: $this->createMock(SettingsService::class),
			appConfig: $this->createMock(IAppConfig::class),
			userSession: $this->userSession,
			groupManager: $groupManager,
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
		);

		$response = $controller->seedDefaults(caseTypeId: 'ct-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testNonAdminIsRejected()

	/**
	 * Mark the session as authenticated.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * An anonymous caller is rejected before the service is consulted.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRejectedAndServiceNeverRuns(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->templateService->expects($this->never())->method('seedDefaultTemplates');

		$response = $this->controller->seedDefaults(caseTypeId: 'melding');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'unauthenticated'], $response->getData());
	}//end testAnonymousCallerIsRejectedAndServiceNeverRuns()

	/**
	 * The created count is passed through, and the caseType id reaches the service.
	 *
	 * @return void
	 */
	public function testSeedsDefaultsAndReturnsCreatedCount(): void {
		$this->authenticate();

		$this->templateService->expects($this->once())
			->method('seedDefaultTemplates')
			->with(caseTypeId: 'melding')
			->willReturn(3);

		$response = $this->controller->seedDefaults(caseTypeId: 'melding');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['created' => 3], $response->getData());
	}//end testSeedsDefaultsAndReturnsCreatedCount()

	/**
	 * A second call creates nothing — the endpoint reports 0, not an error.
	 *
	 * @return void
	 */
	public function testSecondCallIsIdempotentAndReportsZeroCreated(): void {
		$this->authenticate();

		$this->templateService->expects($this->once())
			->method('seedDefaultTemplates')
			->willReturn(0);

		$response = $this->controller->seedDefaults(caseTypeId: 'melding');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['created' => 0], $response->getData());
	}//end testSecondCallIsIdempotentAndReportsZeroCreated()

	/**
	 * A business-rule failure becomes a 400, not a 500.
	 *
	 * @return void
	 */
	public function testRuntimeExceptionBecomesBadRequest(): void {
		$this->authenticate();

		$this->templateService->method('seedDefaultTemplates')
			->willThrowException(new RuntimeException('caseType not found'));

		$response = $this->controller->seedDefaults(caseTypeId: 'nope');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'caseType not found'], $response->getData());
	}//end testRuntimeExceptionBecomesBadRequest()
}//end class
