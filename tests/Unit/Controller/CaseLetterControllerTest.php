<?php

/**
 * CaseLetterController Unit Tests.
 *
 * Pins the read and mutation guards, the letter returned for a purpose, and
 * the 422 when a draft could not be made.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseLetterController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DecisionDocument\DecisionLetter;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseLetterController.
 *
 * @covers \OCA\Dossiq\Controller\CaseLetterController
 */
class CaseLetterControllerTest extends TestCase {

	/**
	 * The decision letter double.
	 *
	 * @var DecisionLetter&MockObject
	 */
	private DecisionLetter&MockObject $letters;

	/**
	 * The case access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $guard;

	/**
	 * The drafts double.
	 *
	 * @var CaseDocumentDrafts&MockObject
	 */
	private CaseDocumentDrafts&MockObject $drafts;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->letters = $this->createMock(DecisionLetter::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->drafts = $this->createMock(CaseDocumentDrafts::class);
	}//end setUp()

	/**
	 * Build the controller with the given signed-in user.
	 *
	 * @param IUser|null $user The signed-in user, or null.
	 *
	 * @return CaseLetterController
	 */
	private function controller(?IUser $user): CaseLetterController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CaseLetterController('dossiq', $this->createMock(IRequest::class), $this->letters, $session, $this->guard, $this->drafts);
	}//end controller()

	/**
	 * Both actions answer 401 without a user and touch nothing.
	 *
	 * @return void
	 */
	public function testBothActionsRequireAUser(): void {
		$this->guard->expects($this->never())->method($this->anything());
		$this->letters->expects($this->never())->method('forCase');
		$this->drafts->expects($this->never())->method('draft');
		$controller = $this->controller(user: null);

		foreach ([$controller->show('case-1', 'besluit'), $controller->draft('case-1', 'besluit')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testBothActionsRequireAUser()

	/**
	 * Showing the letter needs read access to the case.
	 *
	 * @return void
	 */
	public function testShowRequiresReadAccess(): void {
		$user = $this->createMock(IUser::class);
		$this->guard->expects($this->once())->method('hasCaseReadAccess')->with('case-1', $user)->willReturn(false);
		$this->letters->expects($this->never())->method('forCase');

		$response = $this->controller(user: $user)->show('case-1', 'besluit');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $response->getData());
	}//end testShowRequiresReadAccess()

	/**
	 * With read access the letter for that case and purpose is returned.
	 *
	 * @return void
	 */
	public function testShowReturnsTheLetterForThePurpose(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->letters->expects($this->once())->method('forCase')->with('case-1', 'besluit')->willReturn(['letter' => 'L-1']);

		$response = $this->controller(user: $this->createMock(IUser::class))->show('case-1', 'besluit');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['letter' => 'L-1'], $response->getData());
	}//end testShowReturnsTheLetterForThePurpose()

	/**
	 * Drafting without mutation access is refused by the guard; nothing is drafted.
	 *
	 * @return void
	 */
	public function testDraftRequiresMutationAccess(): void {
		$this->guard->method('assertCaseMutationAccess')->willThrowException(new OCSForbiddenException('Not authorized to modify case case-1'));
		$this->drafts->expects($this->never())->method('draft');

		$this->expectException(OCSForbiddenException::class);
		$this->controller(user: $this->createMock(IUser::class))->draft('case-1', 'besluit');
	}//end testDraftRequiresMutationAccess()

	/**
	 * A draft that was not made is a 422 with its status and reason.
	 *
	 * @return void
	 */
	public function testAnUndraftedOutcomeIs422(): void {
		$this->drafts->method('draft')->willReturn(['status' => 'no-template', 'reason' => 'No template for besluit']);
		$this->letters->expects($this->never())->method('forCase');

		$response = $this->controller(user: $this->createMock(IUser::class))->draft('case-1', 'besluit');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'no-template', 'reason' => 'No template for besluit'], $response->getData());
	}//end testAnUndraftedOutcomeIs422()

	/**
	 * A made draft is returned alongside the refreshed letter.
	 *
	 * @return void
	 */
	public function testADraftIsReturnedWithTheLetter(): void {
		$this->drafts->expects($this->once())->method('draft')->with('case-1', 'besluit')->willReturn(['status' => 'drafted', 'fileId' => 9]);
		$this->letters->method('forCase')->with('case-1', 'besluit')->willReturn(['letter' => 'L-1']);

		$response = $this->controller(user: $this->createMock(IUser::class))->draft('case-1', 'besluit');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['draft' => ['status' => 'drafted', 'fileId' => 9], 'letter' => 'L-1'], $response->getData());
	}//end testADraftIsReturnedWithTheLetter()
}//end class
