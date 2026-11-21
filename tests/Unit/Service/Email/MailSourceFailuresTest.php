<?php

/**
 * MailMessageSource and CaseEmailMatchPreferences when the database or the user config fails, and a status that carries an error.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\Config\IUserConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Email\MailMessageSource
 * @covers \OCA\Dossiq\Service\Email\CaseEmailMatchPreferences
 */
class MailSourceFailuresTest extends TestCase {

	/**
	 * A message source over a database that refuses every query.
	 *
	 * @param string $expectedWarning The warning the source must log.
	 *
	 * @return MailMessageSource
	 */
	private function sourceOverABrokenDatabase(string $expectedWarning): MailMessageSource {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('mail tables missing'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($expectedWarning);

		return new MailMessageSource(db: $db, logger: $logger);
	}//end sourceOverABrokenDatabase()

	/**
	 * Listing messages on a broken database logs the cause and returns no messages.
	 *
	 * @return void
	 */
	public function testListingMessagesOnABrokenDatabaseReturnsNone(): void {
		$source = $this->sourceOverABrokenDatabase(expectedWarning: 'Dossiq: listing Mail messages failed: mail tables missing');

		self::assertSame([], $source->listMessagesSince(accountId: 3, sinceId: 0, limit: 50));
	}//end testListingMessagesOnABrokenDatabaseReturnsNone()

	/**
	 * Listing folders on a broken database logs the cause and returns no folders.
	 *
	 * @return void
	 */
	public function testListingFoldersOnABrokenDatabaseReturnsNone(): void {
		$source = $this->sourceOverABrokenDatabase(expectedWarning: 'Dossiq: listing Mail folders failed: mail tables missing');

		self::assertSame([], $source->mailboxesOf(accountId: 3));
	}//end testListingFoldersOnABrokenDatabaseReturnsNone()

	/**
	 * Preferences over the given user config.
	 *
	 * @param IUserConfig $config The user config.
	 *
	 * @return CaseEmailMatchPreferences
	 */
	private function preferences(IUserConfig $config): CaseEmailMatchPreferences {
		return new CaseEmailMatchPreferences(
			userConfig: $config,
			messages: $this->createMock(MailMessageSource::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end preferences()

	/**
	 * A status that cannot be read is reported as an empty status.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatusIsEmpty(): void {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('config table locked'));

		self::assertSame(
			['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null],
			$this->preferences(config: $config)->getStatus(userId: 'anna')
		);
	}//end testAnUnreadableStatusIsEmpty()

	/**
	 * A stored status with an error reports that error next to its counts.
	 *
	 * @return void
	 */
	public function testAStoredErrorIsReported(): void {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willReturn(
			json_encode(['lastRunAt' => '2026-11-14T08:00:00+00:00', 'linked' => 2, 'scanned' => 9, 'error' => 'IMAP login refused'])
		);

		self::assertSame(
			['lastRunAt' => '2026-11-14T08:00:00+00:00', 'linked' => 2, 'scanned' => 9, 'error' => 'IMAP login refused'],
			$this->preferences(config: $config)->getStatus(userId: 'anna')
		);
	}//end testAStoredErrorIsReported()
}//end class
