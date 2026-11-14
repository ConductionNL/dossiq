<?php

/**
 * The addresses on a case: the applicant's own, and everyone the case names as a contact.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseContactDirectory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseContactDirectory
 */
class CaseContactDirectoryTest extends TestCase {

	/**
	 * A case naming its applicant twice and several contacts, some unusable.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseData(): array {
		return [
			'email' => ' Aanvrager@Example.nl ',
			'initiator' => ['email' => 'aanvrager@example.nl'],
			'betrokkenen' => [
				['email' => 'Gemachtigde@Kantoor.nl'],
				['emailadres' => 'buurman@example.nl'],
				['email' => 'not-an-address'],
				'not a contact',
			],
			'contacts' => [
				['email' => 'aanvrager@example.nl'],
				['email' => '', 'emailadres' => 'ignored@example.nl'],
				['name' => 'no address'],
				['email' => 'Derde@Example.nl'],
			],
		];
	}//end caseData()

	public function testApplicantAddressesAreTheTopLevelAndInitiatorOnesDeduplicated(): void {
		self::assertSame(['aanvrager@example.nl'], (new CaseContactDirectory())->applicantAddresses(caseData: self::caseData()));
	}//end testApplicantAddressesAreTheTopLevelAndInitiatorOnesDeduplicated()

	public function testAnInitiatorWithoutAUsableAddressAddsNothing(): void {
		$directory = new CaseContactDirectory();

		self::assertSame(['jan@example.nl'], $directory->applicantAddresses(caseData: ['initiator' => ['email' => 'Jan@Example.nl'], 'email' => 'nope']));
		self::assertSame([], $directory->applicantAddresses(caseData: ['initiator' => 'jan@example.nl']));
	}//end testAnInitiatorWithoutAUsableAddressAddsNothing()

	public function testCollectAddressesAddsEveryValidContactLowerCasedOnce(): void {
		$addresses = (new CaseContactDirectory())->collectAddresses(caseData: self::caseData());

		// An empty `email` is still the key read, so its `emailadres` fallback is not used.
		self::assertSame(['aanvrager@example.nl', 'gemachtigde@kantoor.nl', 'buurman@example.nl', 'derde@example.nl'], array_values($addresses));
	}//end testCollectAddressesAddsEveryValidContactLowerCasedOnce()

	public function testACaseWithoutContactsHasNoAddresses(): void {
		self::assertSame([], (new CaseContactDirectory())->collectAddresses(caseData: ['betrokkenen' => 'x', 'contacts' => null]));
	}//end testACaseWithoutContactsHasNoAddresses()
}//end class
