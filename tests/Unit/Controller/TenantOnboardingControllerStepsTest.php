<?php

/**
 * TenantOnboardingController unit tests: progress, step completion and their failure answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\TenantOnboardingController;
use OCA\Dossiq\Kernel\Contracts\OnboardingStepGuideInterface;
use OCA\Dossiq\Kernel\Contracts\TenantOnboardingServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The progress and complete endpoints, against doubles of their two collaborators.
 *
 * @covers \OCA\Dossiq\Controller\TenantOnboardingController
 */
class TenantOnboardingControllerStepsTest extends TestCase {

	/**
	 * The onboarding service double.
	 *
	 * @var TenantOnboardingServiceInterface&MockObject
	 */
	private TenantOnboardingServiceInterface $onboarding;

	/**
	 * The step guide double.
	 *
	 * @var OnboardingStepGuideInterface&MockObject
	 */
	private OnboardingStepGuideInterface $guide;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->onboarding = $this->createMock(TenantOnboardingServiceInterface::class);
		$this->guide = $this->createMock(OnboardingStepGuideInterface::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return TenantOnboardingController
	 */
	private function controller(): TenantOnboardingController {
		return new TenantOnboardingController(
			request: $this->createMock(IRequest::class),
			onboarding: $this->onboarding,
			userSession: $this->session,
			guide: $this->guide,
		);
	}//end controller()

	/**
	 * Sign in a user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Progress is the service's progress with the guide's guidance laid over it.
	 *
	 * @return void
	 */
	public function testProgressReturnsTheGuidedProgress(): void {
		$this->onboarding->expects($this->once())->method('getProgress')->with('org-1')
			->willReturn(['done' => 1, 'total' => 3]);
		$this->guide->expects($this->once())->method('withGuidance')->with(['done' => 1, 'total' => 3])
			->willReturn(['done' => 1, 'total' => 3, 'next' => 'Connect the mailbox']);

		$response = $this->controller()->progress(tenantId: 'org-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success' => true, 'progress' => ['done' => 1, 'total' => 3, 'next' => 'Connect the mailbox']],
			$response->getData()
		);
	}//end testProgressReturnsTheGuidedProgress()

	/**
	 * Without a session, completing a step is a 401 and nothing is marked.
	 *
	 * @return void
	 */
	public function testCompleteWithoutASessionIsUnauthorised(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->onboarding->expects($this->never())->method('markStepComplete');

		$response = $this->controller()->complete(tenantId: 'org-1', step: 'mailbox');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Not authenticated'], $response->getData());
	}//end testCompleteWithoutASessionIsUnauthorised()

	/**
	 * The step is marked complete by the caller and the task comes back.
	 *
	 * @return void
	 */
	public function testCompleteMarksTheStepByTheCaller(): void {
		$this->signIn('admin');
		$this->onboarding->expects($this->once())->method('markStepComplete')
			->with('org-1', 'mailbox', 'admin')
			->willReturn(['step' => 'mailbox', 'status' => 'done']);

		$response = $this->controller()->complete(tenantId: 'org-1', step: 'mailbox');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true, 'task' => ['step' => 'mailbox', 'status' => 'done']], $response->getData());
	}//end testCompleteMarksTheStepByTheCaller()

	/**
	 * An unknown step is a 404.
	 *
	 * @return void
	 */
	public function testCompleteOfAnUnknownStepIsNotFound(): void {
		$this->signIn('admin');
		$this->onboarding->method('markStepComplete')->willReturn(null);

		$response = $this->controller()->complete(tenantId: 'org-1', step: 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Step not found'], $response->getData());
	}//end testCompleteOfAnUnknownStepIsNotFound()

	/**
	 * An invalid argument from the service is a 400 carrying its message.
	 *
	 * @return void
	 */
	public function testCompleteWithAnInvalidStepIsABadRequest(): void {
		$this->signIn('admin');
		$this->onboarding->method('markStepComplete')
			->willThrowException(new InvalidArgumentException('Step mailbox is already complete'));

		$response = $this->controller()->complete(tenantId: 'org-1', step: 'mailbox');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Step mailbox is already complete'], $response->getData());
	}//end testCompleteWithAnInvalidStepIsABadRequest()

	/**
	 * Activation that did not happen answers 409 with the service's result.
	 *
	 * @return void
	 */
	public function testActivateThatDidNotHappenIsAConflict(): void {
		$this->onboarding->method('activate')->with('org-1')
			->willReturn(['activated' => false, 'missing' => ['mailbox']]);

		$response = $this->controller()->activate(tenantId: 'org-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['success' => false, 'result' => ['activated' => false, 'missing' => ['mailbox']]],
			$response->getData()
		);
	}//end testActivateThatDidNotHappenIsAConflict()
}//end class
