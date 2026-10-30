<?php

/**
 * The access purpose endpoint: an anonymous caller is refused, a refusal
 * keeps its rule and status, a recorded purpose answers 201 (case-access-purpose).
 *
 * @category Test
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
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseAccessPurposeController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessPurposeService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see CaseAccessPurposeController}.
 */
class CaseAccessPurposeControllerTest extends TestCase {

	/**
	 * The controller over a purpose service double.
	 *
	 * @param CaseAccessPurposeService $service The service.
	 * @param bool                     $signedIn Whether a user is signed in.
	 *
	 * @return CaseAccessPurposeController The controller.
	 */
	private function controller(CaseAccessPurposeService $service, bool $signedIn=true): CaseAccessPurposeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['purpose', '', 'handling'], ['note', '', '']]);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('pieter');
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseAccessPurposeController(request: $request, purposes: $service, userSession: $session, l10n: $l10n);
	}//end controller()

	/**
	 * A recorded purpose answers 201.
	 *
	 * @return void
	 */
	public function testARecordedPurposeAnswersCreated(): void {
		$service = $this->createMock(CaseAccessPurposeService::class);
		$service->expects($this->once())->method('record')
			->with('case-1', 'pieter', 'handling', '')
			->willReturn(['recorded' => true, 'required' => true]);

		$response = $this->controller(service: $service)->record(id: 'case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testARecordedPurposeAnswersCreated()

	/**
	 * A refused open answers the refusal's status, rule and sentence.
	 *
	 * @return void
	 */
	public function testARefusedOpenKeepsItsRuleAndStatus(): void {
		$service = $this->createMock(CaseAccessPurposeService::class);
		$service->method('record')->willThrowException(
			new RefusedException(rule: 'access-purpose-not-recorded', sentence: 'Your purpose could not be recorded, so the case does not open.', status: RefusedException::STATUS_INDETERMINATE)
		);

		$response = $this->controller(service: $service)->record(id: 'case-1');

		$this->assertSame(RefusedException::STATUS_INDETERMINATE, $response->getStatus());
		$this->assertSame('access-purpose-not-recorded', $response->getData()['error']);
		$this->assertSame('Your purpose could not be recorded, so the case does not open.', $response->getData()['message']);
	}//end testARefusedOpenKeepsItsRuleAndStatus()

	/**
	 * An anonymous caller is refused before anything is recorded.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$service = $this->createMock(CaseAccessPurposeService::class);
		$service->expects($this->never())->method('record');

		$response = $this->controller(service: $service, signedIn: false)->record(id: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The check answers whether a purpose is required, as the reader, and
	 * refuses an anonymous caller before it asks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	public function testTheCheckAnswersForTheReader(): void {
		$service = $this->createMock(CaseAccessPurposeService::class);
		$service->expects($this->once())->method('check')
			->with('case-1', 'pieter')
			->willReturn(['required' => false, 'recorded' => true]);

		$response = $this->controller(service: $service)->check(id: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['required' => false, 'recorded' => true], $response->getData());

		$anonymous = $this->createMock(CaseAccessPurposeService::class);
		$anonymous->expects($this->never())->method('check');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(service: $anonymous, signedIn: false)->check(id: 'case-1')->getStatus());
	}//end testTheCheckAnswersForTheReader()
}//end class
