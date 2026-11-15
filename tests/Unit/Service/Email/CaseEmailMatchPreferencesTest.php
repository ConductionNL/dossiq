<?php

/**
 * A user's email case matching preferences: opting in, the account, the scan cursor and the last run's status.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseEmailMatchPreferences
 */
class CaseEmailMatchPreferencesTest extends TestCase {

	/**
	 * Per-user configuration.
	 *
	 * @var IUserConfig&MockObject
	 */
	private IUserConfig $config;

	/**
	 * The Mail app's tables.
	 *
	 * @var MailMessageSource&MockObject
	 */
	private MailMessageSource $messages;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IUserConfig::class);
		$this->messages = $this->createMock(MailMessageSource::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The preferences under test.
	 *
	 * @return CaseEmailMatchPreferences
	 */
	private function preferences(): CaseEmailMatchPreferences {
		return new CaseEmailMatchPreferences(userConfig: $this->config, messages: $this->messages, logger: $this->logger);
	}//end preferences()

	/**
	 * Stored settings for the user.
	 *
	 * @param bool $enabled Whether matching was on.
	 * @param int  $account The stored account.
	 *
	 * @return void
	 */
	private function stored(bool $enabled, int $account): void {
		$this->config->method('getValueBool')->with('jan', Application::APP_ID, CaseEmailMatchPreferences::PREF_ENABLED, false)->willReturn($enabled);
		$this->config->method('getValueInt')->willReturnCallback(
			static fn (string $user, string $app, string $key, int $default): int => ($key === CaseEmailMatchPreferences::PREF_ACCOUNT ? $account : $default)
		);
	}//end stored()

	public function testOptedInUsersAreSearchedByTheEnabledFlag(): void {
		$this->config->expects($this->once())->method('searchUsersByValueBool')
			->with(Application::APP_ID, CaseEmailMatchPreferences::PREF_ENABLED, true)
			->willReturn((static function (): \Generator {
				yield 'jan';
				yield 'piet';
			})());

		self::assertSame(['jan', 'piet'], iterator_to_array($this->preferences()->optedInUsers(), false));
	}//end testOptedInUsersAreSearchedByTheEnabledFlag()

	public function testUnreadableSettingsReadAsOffAndAreLogged(): void {
		$this->config->method('getValueBool')->willThrowException(new RuntimeException('type mismatch'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('type mismatch'));

		self::assertSame(['enabled' => false, 'account' => 0], $this->preferences()->getUserSettings(userId: 'jan'));
	}//end testUnreadableSettingsReadAsOffAndAreLogged()

	public function testSavingSomeoneElsesAccountIsRefusedBeforeAnyWrite(): void {
		$this->messages->method('ownsAccount')->with(5, 'jan')->willReturn(false);
		$this->config->expects($this->never())->method('setValueBool');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('That Mail account is not yours.');
		$this->preferences()->saveUserSettings(userId: 'jan', enabled: true, account: 5);
	}//end testSavingSomeoneElsesAccountIsRefusedBeforeAnyWrite()

	public function testSwitchingOnStartsTheCursorAtTheNewestMessage(): void {
		$this->stored(enabled: false, account: 0);
		$this->messages->method('ownsAccount')->willReturn(true);
		$this->messages->expects($this->once())->method('maxMessageId')->with(5)->willReturn(812);
		$this->config->expects($this->once())->method('setValueBool')->with('jan', Application::APP_ID, CaseEmailMatchPreferences::PREF_ENABLED, true);
		$ints = [];
		$this->config->method('setValueInt')->willReturnCallback(
			static function (string $user, string $app, string $key, int $value) use (&$ints): bool {
				$ints[$key] = $value;
				return true;
			}
		);

		self::assertSame(['enabled' => true, 'account' => 5], $this->preferences()->saveUserSettings(userId: 'jan', enabled: true, account: 5));
		self::assertSame([CaseEmailMatchPreferences::PREF_ACCOUNT => 5, CaseEmailMatchPreferences::PREF_CURSOR => 812], $ints);
	}//end testSwitchingOnStartsTheCursorAtTheNewestMessage()

	public function testMovingToAnotherAccountRestartsTheCursorThere(): void {
		$this->stored(enabled: true, account: 3);
		$this->messages->method('ownsAccount')->with(5, 'jan')->willReturn(true);
		$this->messages->expects($this->once())->method('maxMessageId')->with(5)->willReturn(40);
		$this->config->expects($this->exactly(2))->method('setValueInt');

		$this->preferences()->saveUserSettings(userId: 'jan', enabled: true, account: 5);
	}//end testMovingToAnotherAccountRestartsTheCursorThere()

	public function testReEnablingTheSameAccountRestartsTheCursor(): void {
		$this->stored(enabled: false, account: 5);
		$this->messages->method('ownsAccount')->willReturn(true);
		$this->messages->expects($this->once())->method('maxMessageId')->with(5)->willReturn(41);

		$this->preferences()->saveUserSettings(userId: 'jan', enabled: true, account: 5);
	}//end testReEnablingTheSameAccountRestartsTheCursor()

	public function testResavingTheSameAccountKeepsTheCursor(): void {
		$this->stored(enabled: true, account: 5);
		$this->messages->method('ownsAccount')->willReturn(true);
		$this->messages->expects($this->never())->method('maxMessageId');
		$this->config->expects($this->once())->method('setValueInt')->with('jan', Application::APP_ID, CaseEmailMatchPreferences::PREF_ACCOUNT, 5);

		$this->preferences()->saveUserSettings(userId: 'jan', enabled: true, account: 5);
	}//end testResavingTheSameAccountKeepsTheCursor()

	public function testANegativeAccountIsStoredAsNoneWithoutAnOwnershipCheck(): void {
		$this->stored(enabled: true, account: 3);
		$this->messages->expects($this->never())->method('ownsAccount');
		$this->messages->expects($this->never())->method('maxMessageId');

		self::assertSame(['enabled' => false, 'account' => 0], $this->preferences()->saveUserSettings(userId: 'jan', enabled: false, account: -4));
	}//end testANegativeAccountIsStoredAsNoneWithoutAnOwnershipCheck()

	public function testTheCursorReadsUnsetOnFailureAndIsNeverWrittenNegative(): void {
		$this->config->method('getValueInt')->willThrowException(new RuntimeException('x'));
		$this->config->expects($this->once())->method('setValueInt')->with('jan', Application::APP_ID, CaseEmailMatchPreferences::PREF_CURSOR, 0);

		$preferences = $this->preferences();
		self::assertSame(CaseEmailMatchPreferences::CURSOR_UNSET, $preferences->readCursor(userId: 'jan'));
		$preferences->writeCursor(userId: 'jan', cursor: -3);
	}//end testTheCursorReadsUnsetOnFailureAndIsNeverWrittenNegative()

	public function testTheStatusIsDecodedAndTypedOrEmpty(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls(
			'{"lastRunAt":"2026-03-01T10:00:00+00:00","linked":"3","scanned":40,"error":null}',
			'not json',
		);
		$preferences = $this->preferences();

		self::assertSame(['lastRunAt' => '2026-03-01T10:00:00+00:00', 'linked' => 3, 'scanned' => 40, 'error' => null], $preferences->getStatus(userId: 'jan'));
		self::assertSame(['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null], $preferences->getStatus(userId: 'jan'));
	}//end testTheStatusIsDecodedAndTypedOrEmpty()

	public function testWritingTheStatusStoresTheRunAndSurvivesAFailedWrite(): void {
		$written = '';
		$this->config->expects($this->exactly(2))->method('setValueString')->willReturnCallback(
			static function (string $user, string $app, string $key, string $value) use (&$written): bool {
				if ($written !== '') {
					throw new RuntimeException('config locked');
				}

				$written = $value;
				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('config locked'));

		$preferences = $this->preferences();
		$preferences->writeStatus(userId: 'jan', linked: 2, scanned: 9, error: 'imap timeout');
		$preferences->writeStatus(userId: 'jan', linked: 0, scanned: 0, error: null);

		$decoded = json_decode($written, true);
		self::assertSame([2, 9, 'imap timeout'], [$decoded['linked'], $decoded['scanned'], $decoded['error']]);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $decoded['lastRunAt']));
	}//end testWritingTheStatusStoresTheRunAndSurvivesAFailedWrite()
}//end class
