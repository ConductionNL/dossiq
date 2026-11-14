<?php

/**
 * Matching settings: the account id is the one object reference a user
 * supplies, and the pattern is the one value an admin can break the matcher
 * with, so those are the two things the controller must check.
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
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\SettingsService;
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
	 * The caller's preferences.
	 *
	 * @var CaseEmailMatchPreferences&MockObject
	 */
	private CaseEmailMatchPreferences $preferences;

	/**
	 * The pattern validator.
	 *
	 * @var CaseNumberRecognizer&MockObject
	 */
	private CaseNumberRecognizer $recognizer;

	/**
	 * The Mail account reader.
	 *
	 * @var MailMessageSource&MockObject
	 */
	private MailMessageSource $messages;

	/**
	 * The instance settings.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Request parameters the controller reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Set up a controller with Alice signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->matcher = $this->createMock(CaseEmailMatchService::class);
		$this->preferences = $this->createMock(CaseEmailMatchPreferences::class);
		$this->recognizer = $this->createMock(CaseNumberRecognizer::class);
		$this->messages = $this->createMock(MailMessageSource::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->params = [];

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->preferences->method('getUserSettings')->willReturn(['enabled' => false, 'account' => 0]);
		$this->preferences->method('getStatus')->willReturn(['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null]);
		$this->messages->method('accountsOf')->willReturn([['id' => 7, 'name' => 'Werk', 'email' => 'alice@gemeente.test']]);
	}//end setUp()

	/**
	 * The controller under test, reading $this->params as its request.
	 *
	 * @return CaseEmailMatchController The controller.
	 */
	private function controller(): CaseEmailMatchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		return new CaseEmailMatchController(
			request: $request,
			matcher: $this->matcher,
			preferences: $this->preferences,
			recognizer: $this->recognizer,
			messages: $this->messages,
			settingsService: $this->settings,
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
		$this->preferences->expects($this->never())->method('saveUserSettings');
		$this->params = ['enabled' => true, 'account' => 99];

		$response = $this->controller()->saveSettings();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSomebodyElsesAccountIsRefusedAndNothingIsSaved()

	/**
	 * The caller's own account is saved under the session user.
	 *
	 * @return void
	 */
	public function testTheCallersOwnAccountIsSavedForTheSessionUser(): void {
		$this->messages->method('ownsAccount')->willReturn(true);
		$this->preferences->expects($this->once())
			->method('saveUserSettings')
			->with('alice', true, 7)
			->willReturn(['enabled' => true, 'account' => 7]);
		$this->params = ['enabled' => 'true', 'account' => '7'];

		$response = $this->controller()->saveSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testTheCallersOwnAccountIsSavedForTheSessionUser()

	/**
	 * Switching on without an account is refused rather than stored half-set.
	 *
	 * @return void
	 */
	public function testSwitchingOnWithoutAnAccountIsRefused(): void {
		$this->preferences->expects($this->never())->method('saveUserSettings');
		$this->params = ['enabled' => true, 'account' => 0];

		$response = $this->controller()->saveSettings();

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

	/**
	 * An unusable pattern is refused with 400 and nothing is written, the toggle included.
	 *
	 * @return void
	 */
	public function testAnUnusablePatternIsRefusedAndNothingIsWritten(): void {
		$this->params = [
			'email_case_matching_enabled' => 'yes',
			'email_case_matching_pattern' => '/\d{4}-\d{4}/',
		];
		$this->recognizer->method('validatePattern')
			->with('/\d{4}-\d{4}/')
			->willReturn('The pattern has no capture group for the case number.');
		$this->settings->expects($this->never())->method('setConfigValue');

		$response = $this->controller()->saveInstanceSettings();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('email_case_matching_pattern', $response->getData()['field']);
	}//end testAnUnusablePatternIsRefusedAndNothingIsWritten()

	/**
	 * The toggle and a usable pattern are both stored.
	 *
	 * @return void
	 */
	public function testTheToggleAndAUsablePatternAreStored(): void {
		$this->params = [
			'email_case_matching_enabled' => 'yes',
			'email_case_matching_pattern' => '/(\d{4}-\d{4})/',
		];
		$this->recognizer->method('validatePattern')->willReturn(null);

		$written = [];
		$this->settings->method('setConfigValue')->willReturnCallback(
			static function (string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			}
		);

		$this->controller()->saveInstanceSettings();

		$this->assertSame(
			['email_case_matching_enabled' => 'yes', 'email_case_matching_pattern' => '/(\d{4}-\d{4})/'],
			$written
		);
	}//end testTheToggleAndAUsablePatternAreStored()

	/**
	 * Anything but a yes stores the toggle as off.
	 *
	 * @return void
	 */
	public function testAnythingButYesStoresTheToggleAsOff(): void {
		$this->params = ['email_case_matching_enabled' => 'maybe'];

		$written = [];
		$this->settings->method('setConfigValue')->willReturnCallback(
			static function (string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			}
		);

		$this->controller()->saveInstanceSettings();

		$this->assertSame('no', $written['email_case_matching_enabled']);
	}//end testAnythingButYesStoresTheToggleAsOff()
}//end class
