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
		$tables = $this->createMock(originalClassName: MailMessageSource::class);
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
		self::assertSame(expected: AuthenticationResult::PASS, actual: MailSenderChecks::dkimVerdictOf(valid: true));
		self::assertSame(expected: AuthenticationResult::FAIL, actual: MailSenderChecks::dkimVerdictOf(valid: false));
		self::assertSame(expected: AuthenticationResult::UNAVAILABLE, actual: MailSenderChecks::dkimVerdictOf(valid: 'yes'));

		$log = new MailCollaboratorLog();
		$checks = $this->checks(owners: [], log: $log);
		$validator = new class() {
			/**
			 * Stand-in for Mail's validateRaw().
			 *
			 * @param string $source The source.
			 * @return bool
			 */
			public function validateRaw(string $source): bool {
				if ($source === 'broken') {
					throw new RuntimeException('no key');
				}

				return $source === 'signed';
			}
		};
		self::assertSame(expected: AuthenticationResult::PASS, actual: $checks->dkim(validator: $validator, source: 'signed'));
		self::assertSame(expected: AuthenticationResult::FAIL, actual: $checks->dkim(validator: $validator, source: 'tampered'));
		self::assertSame(expected: AuthenticationResult::UNAVAILABLE, actual: $checks->dkim(validator: $validator, source: 'broken'));
		self::assertSame(expected: [['debug', 'Dossiq: the DKIM check could not run']], actual: $log->records);
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

			/**
			 * Stand-in for Mail's isTrusted().
			 *
			 * @param string $owner The owner.
			 * @param string $address The address.
			 * @return bool
			 */
			public function isTrusted(string $owner, string $address): bool {
				$this->asked[] = $owner;
				if ($owner === 'ann') {
					throw new RuntimeException('no list');
				}

				return $owner === 'bob' && $address === 'x@y.nl';
			}
		};
		$log = new MailCollaboratorLog();
		$checks = $this->checks(owners: [1 => 'ann', 2 => '', 3 => 'bob', 4 => 'cas'], log: $log);

		self::assertTrue(condition: $checks->trusted(service: $service, address: 'x@y.nl'));
		self::assertSame(expected: ['ann', 'bob'], actual: $service->asked);
		self::assertSame(expected: [['debug', 'Dossiq: the trusted-sender lookup failed']], actual: $log->records);
		self::assertFalse(condition: $checks->trusted(service: $service, address: 'z@y.nl'));
	}//end testEachMailboxOwnerIsAskedInTurn()

	/**
	 * An empty or unsigned source, or an absent verifier, answers before Mail is asked; a signed one is checked.
	 *
	 * @return void
	 */
	public function testTheDkimGuardsAnswerBeforeMailIsAsked(): void {
		$checks = $this->checks(owners: [], log: new MailCollaboratorLog());
		$asked = 0;
		$validator = new class() {
			/**
			 * Stand-in for Mail's validateRaw().
			 *
			 * @param string $source The source.
			 * @return bool
			 */
			public function validateRaw(string $source): bool {
				return true;
			}
		};
		$resolve = static function () use (&$asked, $validator): object {
			$asked++;
			return $validator;
		};

		self::assertSame(expected: AuthenticationResult::UNAVAILABLE, actual: $checks->dkimResult(source: '', validator: $resolve));
		self::assertSame(expected: AuthenticationResult::NONE, actual: $checks->dkimResult(source: "Subject: x\r\n", validator: $resolve));
		self::assertSame(expected: 0, actual: $asked);
		self::assertSame(expected: AuthenticationResult::PASS, actual: $checks->dkimResult(source: "DKIM-Signature: v=1\r\n", validator: $resolve));
		self::assertSame(expected: 1, actual: $asked);
		self::assertSame(
			expected: AuthenticationResult::UNAVAILABLE,
			actual: $checks->dkimResult(source: "DKIM-Signature: v=1\r\n", validator: static fn (): ?object => null)
		);
	}//end testTheDkimGuardsAnswerBeforeMailIsAsked()

	/**
	 * An empty address or an absent service is not trusted; the address is asked in lower case, trimmed.
	 *
	 * @return void
	 */
	public function testTheTrustedSenderGuards(): void {
		$checks = $this->checks(owners: [1 => 'bob'], log: new MailCollaboratorLog());
		$service = new class() {
			/**
			 * Stand-in for Mail's isTrusted().
			 *
			 * @param string $owner   The owner.
			 * @param string $address The address.
			 * @return bool
			 */
			public function isTrusted(string $owner, string $address): bool {
				return $address === 'x@y.nl';
			}
		};
		$asked = 0;
		$resolve = static function () use (&$asked, $service): object {
			$asked++;
			return $service;
		};

		self::assertFalse(condition: $checks->isTrustedSender(email: '  ', service: $resolve));
		self::assertSame(expected: 0, actual: $asked);
		self::assertTrue(condition: $checks->isTrustedSender(email: ' X@Y.nl ', service: $resolve));
		self::assertFalse(condition: $checks->isTrustedSender(email: 'x@y.nl', service: static fn (): ?object => null));
	}//end testTheTrustedSenderGuards()
}//end class
