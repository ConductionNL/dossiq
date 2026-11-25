<?php

/**
 * MailSenderChecks: the DKIM verdict and the trusted-sender answer.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\AuthenticationResult;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\MailSenderChecks;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/MailCollaboratorLog.php';

/**
 * The DKIM verdict and the trusted-sender answer.
 *
 * @covers \OCA\Dossiq\Service\Email\MailSenderChecks
 */
class MailSenderChecksTest extends TestCase {

	/**
	 * The checks over a Mail table with these account owners (id => owner).
	 *
	 * @param array<int, string>  $owners The owners.
	 * @param MailCollaboratorLog $log    The log.
	 *
	 * @return MailSenderChecks
	 */
	private function checks(array $owners, MailCollaboratorLog $log): MailSenderChecks {
		$tables = $this->createMock(MailMessageSource::class);
		$tables->method('allAccounts')->willReturn(array_map(static fn (int $id): array => ['id' => $id], array_keys($owners)));
		$tables->method('ownerOf')->willReturnCallback(static fn (int $accountId): string => $owners[$accountId]);

		return new MailSenderChecks(mailTables: $tables, logger: $log);
	}//end checks()

	/**
	 * Only a boolean is a verdict; a validator that throws is unavailable.
	 *
	 * @return void
	 */
	public function testTheDkimVerdict(): void {
		self::assertSame(AuthenticationResult::PASS, MailSenderChecks::dkimVerdictOf(valid: true));
		self::assertSame(AuthenticationResult::FAIL, MailSenderChecks::dkimVerdictOf(valid: false));
		self::assertSame(AuthenticationResult::UNAVAILABLE, MailSenderChecks::dkimVerdictOf(valid: 'yes'));

		$log = new MailCollaboratorLog();
		$checks = $this->checks([], $log);
		$validator = new class() {
			public function validateRaw(string $source): bool {
				if ($source === 'broken') {
					throw new RuntimeException('no key');
				}

				return $source === 'signed';
			}
		};
		self::assertSame(AuthenticationResult::PASS, $checks->dkim(validator: $validator, source: 'signed'));
		self::assertSame(AuthenticationResult::FAIL, $checks->dkim(validator: $validator, source: 'tampered'));
		self::assertSame(AuthenticationResult::UNAVAILABLE, $checks->dkim(validator: $validator, source: 'broken'));
		self::assertSame([['debug', 'Dossiq: the DKIM check could not run']], $log->records);
	}//end testTheDkimVerdict()

	/**
	 * Each owner is asked in turn, a failed lookup is skipped, and owners without a name are not asked.
	 *
	 * @return void
	 */
	public function testEachMailboxOwnerIsAskedInTurn(): void {
		$service = new class() {
			/** @var array<int, string> */
			public array $asked = [];

			public function isTrusted(string $owner, string $address): bool {
				$this->asked[] = $owner;
				if ($owner === 'ann') {
					throw new RuntimeException('no list');
				}

				return $owner === 'bob' && $address === 'x@y.nl';
			}
		};
		$log = new MailCollaboratorLog();
		$checks = $this->checks([1 => 'ann', 2 => '', 3 => 'bob', 4 => 'cas'], $log);

		self::assertTrue($checks->trusted(service: $service, address: 'x@y.nl'));
		self::assertSame(['ann', 'bob'], $service->asked);
		self::assertSame([['debug', 'Dossiq: the trusted-sender lookup failed']], $log->records);
		self::assertFalse($checks->trusted(service: $service, address: 'z@y.nl'));
	}//end testEachMailboxOwnerIsAskedInTurn()
}//end class
