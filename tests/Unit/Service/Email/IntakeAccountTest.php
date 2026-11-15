<?php

/**
 * IntakeAccount unit tests
 *
 * Which Nextcloud Mail account and folder the intake reads: the folder falls
 * back to INBOX when left blank, an account id of zero means not configured,
 * and a message belongs to the intake only on that account and folder
 * (folder compared without case or surrounding spaces).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\IntakeAccount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the intake mailbox setting.
 *
 * @covers \OCA\Dossiq\Service\Email\IntakeAccount
 */
class IntakeAccountTest extends TestCase {

	public function testTheConfiguredAccountAndFolderAreRead(): void {
		$account = $this->account(accountId: '7', folder: ' Zaken ');

		$this->assertSame(7, $account->accountId());
		$this->assertSame('Zaken', $account->folder());
		$this->assertTrue($account->isConfigured());
	}//end testTheConfiguredAccountAndFolderAreRead()

	public function testABlankFolderIsTheInbox(): void {
		$this->assertSame('INBOX', $this->account(accountId: '7', folder: '   ')->folder());
		$this->assertSame('INBOX', $this->account(accountId: '7', folder: null)->folder());
	}//end testABlankFolderIsTheInbox()

	public function testNoAccountMeansNotConfigured(): void {
		$account = $this->account(accountId: null, folder: null);

		$this->assertSame(0, $account->accountId());
		$this->assertFalse($account->isConfigured());
		$this->assertFalse($account->matches(accountId: 0, mailbox: 'INBOX'));
	}//end testNoAccountMeansNotConfigured()

	public function testAMessageMatchesOnlyOnTheIntakeAccountAndFolder(): void {
		$account = $this->account(accountId: '7', folder: 'Zaken');

		$this->assertTrue($account->matches(accountId: 7, mailbox: ' zaken '));
		$this->assertFalse($account->matches(accountId: 8, mailbox: 'Zaken'));
		$this->assertFalse($account->matches(accountId: 7, mailbox: 'INBOX'));
		$this->assertFalse($account->matches(accountId: -1, mailbox: 'Zaken'));
	}//end testAMessageMatchesOnlyOnTheIntakeAccountAndFolder()

	/**
	 * The setting over stored values; null leaves the default.
	 *
	 * @param string|null $accountId The stored account id.
	 * @param string|null $folder    The stored folder.
	 *
	 * @return IntakeAccount
	 */
	private function account(?string $accountId, ?string $folder): IntakeAccount {
		$stored = array_filter([IntakeAccount::ACCOUNT_KEY => $accountId, IntakeAccount::FOLDER_KEY => $folder], static fn (?string $value): bool => $value !== null);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($stored): string {
				TestCase::assertSame('dossiq', $app);
				return ($stored[$key] ?? $default);
			}
		);

		return new IntakeAccount(appConfig: $config);
	}//end account()
}//end class
