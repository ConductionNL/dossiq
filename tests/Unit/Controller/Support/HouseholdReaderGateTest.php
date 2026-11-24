<?php

/**
 * Household Reader Gate Test
 *
 * Pins who may ask about a case's household: a signed-out reader gets 401, a
 * reader without the social domain's sensitive access or without read access
 * to the case gets 403 (the sensitive check first), everyone else passes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\HouseholdReaderGate;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CitizenLookupGuardInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HouseholdReaderGate.
 */
#[CoversClass(HouseholdReaderGate::class)]
final class HouseholdReaderGateTest extends TestCase {
	/**
	 * A gate over these answers.
	 *
	 * @param boolean $sensitive Whether the reader may see sensitive fields.
	 * @param boolean $readable  Whether the reader may read the case.
	 * @param array<int, string> $asked The case ids the case guard was asked about, by reference.
	 *
	 * @return HouseholdReaderGate
	 */
	private function gate(bool $sensitive, bool $readable, array &$asked = []): HouseholdReaderGate {
		$caseAccess = $this->createMock(originalClassName: CaseAccessGuardInterface::class);
		$caseAccess->method('hasCaseReadAccess')->willReturnCallback(
			function (string $caseId) use ($readable, &$asked): bool {
				$asked[] = $caseId;
				return $readable;
			}
		);
		$guard = $this->createMock(originalClassName: CitizenLookupGuardInterface::class);
		$guard->method('maySeeSensitiveFields')->willReturn($sensitive);

		return new HouseholdReaderGate(caseAccess: $caseAccess, sensitive: $guard);
	}//end gate()

	/**
	 * A reader.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('fatma');
		return $user;
	}//end user()

	/**
	 * Nobody signed in: 401, and no guard is asked.
	 *
	 * @return void
	 */
	public function testASignedOutReaderIsUnauthenticated(): void {
		$asked = [];
		$refusal = $this->gate(sensitive: true, readable: true, asked: $asked)->refusal(user: null, caseId: 'case-1');

		self::assertNotNull(actual: $refusal);
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $refusal->getStatus());
		self::assertSame(expected: 'not-signed-in', actual: $refusal->getData()['error']);
		self::assertSame(expected: [], actual: $asked);
	}//end testASignedOutReaderIsUnauthenticated()

	/**
	 * Without sensitive access: 403, and the case is not asked about.
	 *
	 * @return void
	 */
	public function testAReaderWithoutSensitiveAccessIsRefusedBeforeTheCaseIsRead(): void {
		$asked = [];
		$refusal = $this->gate(sensitive: false, readable: true, asked: $asked)->refusal(user: $this->user(), caseId: 'case-1');

		self::assertNotNull(actual: $refusal);
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refusal->getStatus());
		self::assertSame(expected: 'household-not-allowed', actual: $refusal->getData()['error']);
		self::assertSame(expected: [], actual: $asked);
	}//end testAReaderWithoutSensitiveAccessIsRefusedBeforeTheCaseIsRead()

	/**
	 * Sensitive access but no read access to the case: 403.
	 *
	 * @return void
	 */
	public function testAReaderWhoMayNotReadTheCaseIsRefused(): void {
		$asked = [];
		$refusal = $this->gate(sensitive: true, readable: false, asked: $asked)->refusal(user: $this->user(), caseId: 'case-1');

		self::assertNotNull(actual: $refusal);
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $refusal->getStatus());
		self::assertSame(expected: ['case-1'], actual: $asked);
	}//end testAReaderWhoMayNotReadTheCaseIsRefused()

	/**
	 * Both: no refusal, and mayRead answers the case guard for any case.
	 *
	 * @return void
	 */
	public function testAReaderWithBothPassesAndMayReadAsksTheCaseGuard(): void {
		$asked = [];
		$gate = $this->gate(sensitive: true, readable: true, asked: $asked);

		self::assertNull(actual: $gate->refusal(user: $this->user(), caseId: 'case-1'));
		self::assertTrue(condition: $gate->mayRead(caseId: 'case-2', user: $this->user()));
		self::assertSame(expected: ['case-1', 'case-2'], actual: $asked);
		self::assertFalse(condition: $this->gate(sensitive: true, readable: false)->mayRead(caseId: 'case-3', user: $this->user()));
	}//end testAReaderWithBothPassesAndMayReadAsksTheCaseGuard()
}//end class
