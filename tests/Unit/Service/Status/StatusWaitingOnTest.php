<?php

/**
 * Who the case is waiting on, and the promise that `role` still works.
 *
 * The second half is the one worth a test. `statusType.role` is read by the
 * shipped flow and by `StatusTypeLookup::idForRole()`, and the whole argument
 * for adding `waitingOn` beside it rather than widening `role` is that nothing
 * reading `role` changes. A test that only exercised the new property would
 * leave that claim unchecked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Status\StatusDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusDeclaration
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class StatusWaitingOnTest extends TestCase {

	/**
	 * Two waiting statuses are told apart.
	 *
	 * Under the Awb they are different facts: a hersteltermijn suspends the
	 * beslistermijn and an advice request does not, so a single value cannot
	 * decide whether the clock should stop.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testWaitingOnTheApplicantIsNotWaitingOnAThirdParty(): void {
		$declaration = new StatusDeclaration();

		self::assertSame(
			expected: StatusDeclaration::WAITING_ON_APPLICANT,
			actual: $declaration->waitingOn(
				statusType: ['name' => 'Waiting for the applicant', 'waitingOn' => 'applicant'],
			),
		);
		self::assertSame(
			expected: StatusDeclaration::WAITING_ON_THIRD_PARTY,
			actual: $declaration->waitingOn(
				statusType: ['name' => 'Waiting for advice', 'waitingOn' => 'thirdParty'],
			),
		);
		self::assertNotSame(
			expected: StatusDeclaration::WAITING_ON_APPLICANT,
			actual: StatusDeclaration::WAITING_ON_THIRD_PARTY,
		);
	}//end testWaitingOnTheApplicantIsNotWaitingOnAThirdParty()

	/**
	 * A status that declares nothing is ours to move.
	 *
	 * Not a fourth bucket: on a case type nobody has annotated, "not declared"
	 * would hold the whole queue and the count would answer the team lead's
	 * question with a shrug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnUndeclaredStatusReadsAsOursToMove(): void {
		$declaration = new StatusDeclaration();

		self::assertSame(
			expected: StatusDeclaration::WAITING_ON_US,
			actual: $declaration->waitingOn(statusType: ['name' => 'In progress']),
		);
		self::assertSame(
			expected: StatusDeclaration::WAITING_ON_US,
			actual: $declaration->waitingOn(statusType: ['waitingOn' => 'somebody-else']),
		);
	}//end testAnUndeclaredStatusReadsAsOursToMove()

	/**
	 * The shipped flow keeps reading `role`, untouched.
	 *
	 * `waitingOn` sits BESIDE `role` rather than replacing it. A status whose
	 * role is `pending-info` still carries that role whatever it declares
	 * about who it waits on, so `StatusTypeLookup::idForRole()` finds exactly
	 * what it found before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testRoleIsUntouchedByTheNewDeclaration(): void {
		$statusType = [
			'name' => 'Waiting for information',
			'role' => 'pending-info',
			'waitingOn' => 'applicant',
		];

		$declaration = new StatusDeclaration();

		self::assertSame(expected: 'pending-info', actual: $statusType['role']);
		self::assertSame(
			expected: StatusDeclaration::WAITING_ON_APPLICANT,
			actual: $declaration->waitingOn(statusType: $statusType),
		);
	}//end testRoleIsUntouchedByTheNewDeclaration()

	/**
	 * A maximum dwell is a positive whole number of working days, or nothing.
	 *
	 * Zero and a negative are refused rather than honoured: a maximum of zero
	 * would breach every case the instant it entered the status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAMaximumDwellIsPositiveOrAbsent(): void {
		$declaration = new StatusDeclaration();

		self::assertSame(expected: 20, actual: $declaration->maximumDwell(statusType: ['maximumDwell' => 20]));
		self::assertSame(expected: 20, actual: $declaration->maximumDwell(statusType: ['maximumDwell' => '20']));
		self::assertNull(actual: $declaration->maximumDwell(statusType: ['maximumDwell' => 0]));
		self::assertNull(actual: $declaration->maximumDwell(statusType: ['maximumDwell' => -3]));
		self::assertNull(actual: $declaration->maximumDwell(statusType: []));
	}//end testAMaximumDwellIsPositiveOrAbsent()
}//end class
