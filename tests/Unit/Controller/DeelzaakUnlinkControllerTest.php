<?php

/**
 * DeelzaakController::unlink Unit Tests
 *
 * Covers procest#793: a partial unlink must be distinguishable from a clean
 * one by status code alone, so the caller can refuse to delete the parent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-deletion-protection
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DeelzaakController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DeelzaakService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the unlink endpoint's result reporting.
 *
 * ⚠️ Declared at CLASS scope, matching the other controller tests here. A
 * method-scoped declaration is not enough: constructing the controller runs
 * `__construct`, which is not the named method, so strict coverage metadata
 * reports every case as risky.
 *
 * @covers \OCA\Dossiq\Controller\DeelzaakController
 */
class DeelzaakUnlinkControllerTest extends TestCase {

	/**
	 * Request, mocked.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * Deelzaak service, mocked.
	 *
	 * @var DeelzaakService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private DeelzaakService $service;

	/**
	 * User session, mocked.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Per-case authorization guard, mocked.
	 *
	 * Added when #805 gave `unlink()` a real mutation check. These cases are
	 * about the partial-unlink REPORTING, so the guard grants throughout and
	 * the refusal path is covered by the authorization tests next to it.
	 *
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DeelzaakService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
	}//end setUp()

	/**
	 * Build the controller with a signed-in or anonymous session.
	 *
	 * @param bool $signedIn Whether a user is present.
	 *
	 * @return DeelzaakController The controller.
	 */
	private function controller(bool $signedIn = true): DeelzaakController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$this->userSession->method('getUser')->willReturn($user);

		return new DeelzaakController(
			$this->request,
			$this->service,
			$this->userSession,
			$this->caseAccessGuard
		);
	}//end controller()

	/**
	 * A complete unlink answers 200 and reports the full result.
	 *
	 * Negative control for the 207 test: an implementation that always
	 * answered 207 would satisfy that test on its own.
	 *
	 * @return void
	 */
	public function testACompleteUnlinkAnswers200(): void {
		$this->service->method('unlinkSubCases')->willReturn(
			['unlinked' => 3, 'failed' => 0, 'total' => 3, 'complete' => true]
		);

		$response = $this->controller()->unlink('parent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['complete']);
		$this->assertSame(3, $response->getData()['unlinked']);
	}//end testACompleteUnlinkAnswers200()

	/**
	 * 🔴 A partial unlink answers 207, not 200.
	 *
	 * This used to answer `200 OK` with a count that under-reported, and the
	 * caller went on to delete the parent — orphaning the remaining children
	 * under a dead reference.
	 *
	 * @return void
	 */
	public function testAPartialUnlinkAnswers207(): void {
		$this->service->method('unlinkSubCases')->willReturn(
			['unlinked' => 197, 'failed' => 3, 'total' => 200, 'complete' => false]
		);

		$response = $this->controller()->unlink('parent-1');

		$this->assertSame(Http::STATUS_MULTI_STATUS, $response->getStatus());
		$this->assertFalse($response->getData()['complete']);
		$this->assertSame(3, $response->getData()['failed']);
	}//end testAPartialUnlinkAnswers207()

	/**
	 * An anonymous caller is refused before any unlink runs.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->service->expects($this->never())->method('unlinkSubCases');

		$response = $this->controller(signedIn: false)->unlink('parent-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A parent with no sub-cases is a clean 200.
	 *
	 * @return void
	 */
	public function testNoSubCasesIsAClean200(): void {
		$this->service->method('unlinkSubCases')->willReturn(
			['unlinked' => 0, 'failed' => 0, 'total' => 0, 'complete' => true]
		);

		$response = $this->controller()->unlink('parent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
	}//end testNoSubCasesIsAClean200()
}//end class
