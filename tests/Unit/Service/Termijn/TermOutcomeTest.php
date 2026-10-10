<?php

/**
 * What a term came to, and the share of decided terms that were met.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\TermOutcome;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WTO-003: met, missed, running, suspended, and no share of nothing.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermOutcome
 */
class TermOutcomeTest extends TestCase {
	/**
	 * Each status lands in its outcome, and a late decision is missed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function testEveryStatusHasOneOutcome(): void {
		$outcome = new TermOutcome();

		self::assertSame(TermOutcome::MET, $outcome->classify(['status' => 'completed', 'endDateCurrent' => '2026-11-02', 'voltooiDatum' => '2026-11-02']));
		self::assertSame(TermOutcome::MET, $outcome->classify(['status' => 'completed', 'endDateCurrent' => '2026-11-02']));
		self::assertSame(TermOutcome::MISSED, $outcome->classify(['status' => 'completed', 'endDateCurrent' => '2026-11-02', 'voltooiDatum' => '2026-11-03']));
		self::assertSame(TermOutcome::MISSED, $outcome->classify(['status' => 'exceeded']));
		self::assertSame(TermOutcome::SUSPENDED, $outcome->classify(['status' => 'paused']));
		self::assertSame(TermOutcome::RUNNING, $outcome->classify(['status' => 'lopend']));
		self::assertSame(TermOutcome::RUNNING, $outcome->classify(['status' => 'verlengd']));
		self::assertSame(TermOutcome::RUNNING, $outcome->classify([]));
	}//end testEveryStatusHasOneOutcome()

	/**
	 * The share counts decided terms only, and nothing decided is no figure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function testTheShareIsOverDecidedTermsOnly(): void {
		$outcome = new TermOutcome();

		self::assertSame(75.0, $outcome->shareMet(met: 3, missed: 1));
		self::assertSame(0.0, $outcome->shareMet(met: 0, missed: 2));
		self::assertNull($outcome->shareMet(met: 0, missed: 0));
	}//end testTheShareIsOverDecidedTermsOnly()
}//end class
