<?php

/**
 * Unit tests for the filter verdict value object.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email\Filters;

use OCA\Dossiq\Service\Email\Filters\FilterOutcome;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The named constructors and the serialised form of a verdict.
 *
 * @covers \OCA\Dossiq\Service\Email\Filters\FilterVerdict
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterOutcome
 */
class FilterVerdictTest extends TestCase {

	/**
	 * A pass carries only the filter name and is not decisive.
	 *
	 * @return void
	 */
	public function testAPassIsNotDecisiveAndCarriesNoReason(): void {
		$verdict = FilterVerdict::pass(filterName: 'junk');

		self::assertSame(FilterOutcome::PASS, $verdict->outcome);
		self::assertFalse($verdict->isDecisive());
		self::assertSame(
			['outcome' => 'pass', 'filter' => 'junk', 'reason' => '', 'forwardTo' => ''],
			$verdict->toArray()
		);
	}//end testAPassIsNotDecisiveAndCarriesNoReason()

	/**
	 * Reject and quarantine keep their reason and are decisive.
	 *
	 * @return void
	 */
	public function testRejectAndQuarantineKeepTheirReason(): void {
		$reject = FilterVerdict::reject(filterName: 'bounce', reason: 'null return-path');
		$quarantine = FilterVerdict::quarantine(filterName: 'junk', reason: 'spam flag');

		self::assertTrue($reject->isDecisive());
		self::assertSame(
			['outcome' => 'reject', 'filter' => 'bounce', 'reason' => 'null return-path', 'forwardTo' => ''],
			$reject->toArray()
		);
		self::assertTrue($quarantine->isDecisive());
		self::assertSame(
			['outcome' => 'quarantine', 'filter' => 'junk', 'reason' => 'spam flag', 'forwardTo' => ''],
			$quarantine->toArray()
		);
	}//end testRejectAndQuarantineKeepTheirReason()

	/**
	 * A forward records where the message goes.
	 *
	 * @return void
	 */
	public function testAForwardRecordsItsDestination(): void {
		$verdict = FilterVerdict::forward(filterName: 'route', reason: 'belongs elsewhere', forwardTo: 'desk@example.org');

		self::assertTrue($verdict->isDecisive());
		self::assertSame('desk@example.org', $verdict->forwardTo);
		self::assertSame(
			['outcome' => 'forward', 'filter' => 'route', 'reason' => 'belongs elsewhere', 'forwardTo' => 'desk@example.org'],
			$verdict->toArray()
		);
	}//end testAForwardRecordsItsDestination()

	/**
	 * Accept defaults to an empty name and reason and is decisive.
	 *
	 * @return void
	 */
	public function testAnAcceptWithoutArgumentsIsStillDecisive(): void {
		$verdict = FilterVerdict::accept();

		self::assertSame(FilterOutcome::ACCEPT, $verdict->outcome);
		self::assertTrue($verdict->isDecisive());
		self::assertSame(
			['outcome' => 'accept', 'filter' => '', 'reason' => '', 'forwardTo' => ''],
			$verdict->toArray()
		);
	}//end testAnAcceptWithoutArgumentsIsStillDecisive()

	/**
	 * A verdict built with an unknown outcome is not decisive.
	 *
	 * @return void
	 */
	public function testAnUnknownOutcomeIsNotDecisive(): void {
		self::assertFalse((new FilterVerdict(outcome: 'drop'))->isDecisive());
	}//end testAnUnknownOutcomeIsNotDecisive()
}//end class
