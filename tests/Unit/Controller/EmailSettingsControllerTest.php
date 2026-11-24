<?php

/**
 * EmailSettingsController Unit Tests
 *
 * Pins what the mail settings form reads and refuses: an anonymous caller gets
 * 401 on all three routes and nothing is read, and the read returns every
 * settings key from appconfig, empty when unset, and no other key.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EmailSettingsController;
use OCA\Dossiq\Controller\Support\MailIntakeSettings;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\EmailTemplateServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SenderBlocklistInterface;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailSettingsController.
 */
#[CoversClass(EmailSettingsController::class)]
#[CoversClass(MailIntakeSettings::class)]
final class EmailSettingsControllerTest extends TestCase {
	/**
	 * A controller over this session and appconfig.
	 *
	 * @param boolean    $signedIn  Whether a user is signed in.
	 * @param IAppConfig $appConfig The app config.
	 * @param MailGatewayInterface|null $gateway The mail gateway, or a mock that must not be asked.
	 *
	 * @return EmailSettingsController
	 */
	private function controller(bool $signedIn, IAppConfig $appConfig, ?MailGatewayInterface $gateway = null): EmailSettingsController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new EmailSettingsController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $userSession,
			settings: new MailIntakeSettings(
				appConfig: $appConfig,
				blocklist: $this->createMock(originalClassName: SenderBlocklistInterface::class),
				mailGateway: $gateway ?? $this->createMock(originalClassName: MailGatewayInterface::class),
				templateService: $this->createMock(originalClassName: EmailTemplateServiceInterface::class),
			),
		);
	}//end controller()

	/**
	 * An anonymous caller gets 401 on every route, and neither appconfig nor Mail is asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedOnEveryRoute(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');
		$appConfig->expects($this->never())->method('setValueString');
		$gateway = $this->createMock(originalClassName: MailGatewayInterface::class);
		$gateway->expects($this->never())->method('isAvailable');
		$controller = $this->controller(signedIn: false, appConfig: $appConfig, gateway: $gateway);

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->getSettings()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->saveSettings()->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->mailAccounts()->getStatus());
	}//end testAnAnonymousCallerIsRefusedOnEveryRoute()

	/**
	 * The read returns every settings key of this app, empty when unset, and nothing else.
	 *
	 * @return void
	 */
	public function testTheReadReturnsEverySettingsKeyEmptyWhenUnset(): void {
		$asked     = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use (&$asked): string {
				$asked[] = $app;
				if ($key === 'email_imap_folder') {
					return 'INBOX/Zaken';
				}

				return $default;
			}
		);

		$data = $this->controller(signedIn: true, appConfig: $appConfig)->getSettings()->getData();

		self::assertSame(expected: 'INBOX/Zaken', actual: $data['email_imap_folder']);
		self::assertSame(expected: '', actual: $data['email_mail_account_id']);
		self::assertSame(
			expected: [
				'email_mail_account_id',
				'email_imap_folder',
				'email_transport',
				'email_poll_interval',
				'email_poll_batch_size',
				'email_max_attachment_size',
				'email_fallback_case_type',
				'email_intake_blocklist',
				'email_intake_junk_rules',
				'email_intake_role',
			],
			actual: array_keys($data),
		);
		self::assertSame(expected: [AppIdentity::APP_ID], actual: array_values(array_unique($asked)));
	}//end testTheReadReturnsEverySettingsKeyEmptyWhenUnset()

	/**
	 * A save stores only the keys given, sends the block list through its own class, and ignores a key not in the form.
	 *
	 * @return void
	 */
	public function testASaveStoresTheNamedKeysAndTheBlockListThroughItsClass(): void {
		$stored    = [];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$app . '/' . $key] = $value;
				return true;
			}
		);
		$blocklist = $this->createMock(originalClassName: SenderBlocklistInterface::class);
		$blocklist->expects($this->once())->method('replace')->with(['a@x.nl', ' B@Y.nl ']);

		$settings = new MailIntakeSettings(
			appConfig: $appConfig,
			blocklist: $blocklist,
			mailGateway: $this->createMock(originalClassName: MailGatewayInterface::class),
			templateService: $this->createMock(originalClassName: EmailTemplateServiceInterface::class),
		);
		$settings->save(
			params: [
				'email_imap_folder'                      => 'INBOX',
				'email_transport'                        => null,
				SenderBlocklistInterface::BLOCKLIST_KEY => 'a@x.nl, B@Y.nl ',
				'email_imap_password'                    => 'secret',
			]
		);

		self::assertSame(expected: [AppIdentity::APP_ID . '/email_imap_folder' => 'INBOX'], actual: $stored);
	}//end testASaveStoresTheNamedKeysAndTheBlockListThroughItsClass()
}//end class
