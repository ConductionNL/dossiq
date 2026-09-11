<?php

/**
 * A user's own matching settings: the account id is the one thing the request
 * supplies, so it is the thing the controller must check.
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseEmailMatchController;
use OCA\Dossiq\Service\CaseEmailMatchService;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseEmailMatchController.
 *
 * @covers \OCA\Dossiq\Controller\CaseEmailMatchController
 */
class CaseEmailMatchControllerTest extends TestCase {

	/**
	 * The matcher.
	 *
	 * @var CaseEmailMatchService&MockObject
	 */
	private CaseEmailMatchService $matcher;

	/**
	 * The Mail account reader.
	 *
	 * @var MailMessageSource&MockObject
	 */
	private MailMessageSource $messages;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Set up a controller with Alice signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->matcher = $this->createMock(CaseEmailMatchService::class);
		$this->messages = $this->createMock(MailMessageSource::class);
		$this->session = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->matcher->method('getUserSettings')->willReturn(['enabled' => false, 'account' => 0]);
		$this->matcher->method('getStatus')->willReturn(['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null]);
		$this->messages->method('accountsOf')->willReturn([['id' => 7, 'name' => 'Werk', 'email' => 'alice@gemeente.test']]);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return CaseEmailMatchController The controller.
	 */
	private function controller(): CaseEmailMatchController {
		return new CaseEmailMatchController(
			request: $this->createMock(IRequest::class),
			matcher: $this->matcher,
			messages: $this->messages,
			userSession: $this->session
		);
	}//end controller()

	/**
	 * Somebody else's account id is refused with 403 and nothing is saved.
	 *
	 * @return void
	 */
	public function testSomebodyElsesAccountIsRefusedAndNothingIsSaved(): void {
		$this->messages->method('ownsAccount')->willReturnCallback(
			static fn (int $accountId, string $userId): bool => ($accountId === 7 && $userId === 'alice')
		);
		$this->matcher->expects($this->never())->method('saveUserSettings');

		$response = $this->controller()->saveSettings(enabled: true, account: 99);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSomebodyElsesAccountIsRefusedAndNothingIsSaved()

	/**
	 * The caller's own account is saved under the session user.
	 *
	 * @return void
	 */
	public function testTheCallersOwnAccountIsSavedForTheSessionUser(): void {
		$this->messages->method('ownsAccount')->willReturn(true);
		$this->matcher->expects($this->once())
			->method('saveUserSettings')
			->with('alice', true, 7)
			->willReturn(['enabled' => true, 'account' => 7]);

		$response = $this->controller()->saveSettings(enabled: true, account: 7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testTheCallersOwnAccountIsSavedForTheSessionUser()

	/**
	 * Switching on without an account is refused rather than stored half-set.
	 *
	 * @return void
	 */
	public function testSwitchingOnWithoutAnAccountIsRefused(): void {
		$this->matcher->expects($this->never())->method('saveUserSettings');

		$response = $this->controller()->saveSettings(enabled: true, account: 0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSwitchingOnWithoutAnAccountIsRefused()

	/**
	 * Reading answers the caller's settings, accounts, status and the instance toggle.
	 *
	 * @return void
	 */
	public function testReadingAnswersEverythingTheSectionShows(): void {
		$this->matcher->method('isInstanceEnabled')->willReturn(true);

		$data = $this->controller()->getSettings()->getData();

		$this->assertSame(
			['instanceEnabled', 'enabled', 'account', 'accounts', 'status'],
			array_keys($data)
		);
		$this->assertTrue($data['instanceEnabled']);
		$this->assertSame(7, $data['accounts'][0]['id']);
	}//end testReadingAnswersEverythingTheSectionShows()
}//end class
