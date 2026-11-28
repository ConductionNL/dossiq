<?php

/**
 * Unit tests for ExtensionLimits.
 *
 * An extension's checks: the new end after the current one, the ceiling of
 * extensions that a supervisor may pass, and the period the case type
 * declares, measured to the end asked for rather than the rolled one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Service\ExtensionLimits
 * @uses \OCA\Dossiq\Service\ExtensionRun
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Exception\RefusedException
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\ExtensionLimits;
use OCA\Dossiq\Service\ExtensionRun;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExtensionLimitsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The checks over a case type that declares `$declared`, or no declarations at all.
	 *
	 * @param array<string, mixed>|null $declared The declared extension rule, or null for no reader.
	 *
	 * @return ExtensionLimits
	 */
	private function limits(?array $declared = null): ExtensionLimits {
		$declarations = null;
		if ($declared !== null) {
			$declarations = $this->createMock(originalClassName: TermDeclarationReader::class);
			$declarations->method('forCase')->willReturn($declared);
		}

		return new ExtensionLimits(
			termService: $this->createMock(originalClassName: TermijnService::class),
			dates: $this->caseDates(),
			declarations: $declarations
		);
	}//end limits()

	/**
	 * An extension of a term ending 1 March, to `$newEnd`, asked as `$askedEnd`.
	 *
	 * @param string $newEnd     The end after the roll.
	 * @param string $askedEnd   The end asked for.
	 * @param string $mode       The mode.
	 * @param int    $extensions The extensions the term already had.
	 *
	 * @return ExtensionRun
	 */
	private function extension(
		string $newEnd,
		string $askedEnd,
		string $mode = DeadlineExtensionService::MODE_STANDARD,
		int $extensions = 0,
	): ExtensionRun {
		$run = new ExtensionRun(termInstanceId: 'term-1', mode: $mode);
		$run->instance = ['endDateCurrent' => '2027-03-01', 'countExtensions' => $extensions, 'case' => 'case-1'];
		$run->newEndDate = $newEnd;
		$run->requestedEndDate = $askedEnd;

		return $run;
	}//end extension()

	/**
	 * An allowed extension is measured to its rolled end; an end not after the current one is refused.
	 *
	 * @return void
	 */
	public function testAnAllowedExtensionIsMeasuredAndAnEarlierEndRefused(): void {
		$run = $this->extension(newEnd: '2027-03-15', askedEnd: '2027-03-14');
		$this->limits()->assertAllowed(run: $run);
		$this->assertSame(expected: 14, actual: $run->daysImpact);
		$this->assertSame(expected: 14, actual: $this->limits()->daysImpact(current: '2027-03-01', newEndDate: '2027-03-15'));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'newEinddatum must be later than current einddatumActueel');
		$this->limits()->assertAllowed(run: $this->extension(newEnd: '2027-03-01', askedEnd: '2027-03-01'));
	}//end testAnAllowedExtensionIsMeasuredAndAnEarlierEndRefused()

	/**
	 * The ceiling of one extension holds, but a supervisor passes it.
	 *
	 * @return void
	 */
	public function testTheCeilingHoldsButASupervisorPassesIt(): void {
		$supervised = $this->extension(
			newEnd: '2027-03-08',
			askedEnd: '2027-03-08',
			mode: DeadlineExtensionService::MODE_SUPERVISOR,
			extensions: 1
		);
		$this->limits()->assertAllowed(run: $supervised);
		$this->assertSame(expected: 7, actual: $supervised->daysImpact);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'maximum aantal verlengingen al verbruikt (1)');
		$this->limits()->assertAllowed(run: $this->extension(newEnd: '2027-03-08', askedEnd: '2027-03-08', extensions: 1));
	}//end testTheCeilingHoldsButASupervisorPassesIt()

	/**
	 * The declared period is measured to the end asked for, and a case type may forbid extending.
	 *
	 * @return void
	 */
	public function testTheDeclaredPeriodIsMeasuredToTheEndAskedFor(): void {
		$week = ['extensionAllowed' => true, 'extensionPeriodDays' => 7];
		$this->limits(declared: $week)->assertAllowed(run: $this->extension(newEnd: '2027-03-09', askedEnd: '2027-03-08'));

		try {
			$this->limits(declared: $week)->assertAllowed(run: $this->extension(newEnd: '2027-03-15', askedEnd: '2027-03-15'));
			$this->fail(message: 'An extension past the declared period was allowed.');
		} catch (RefusedException $refused) {
			$this->assertSame(expected: 'extension-beyond-declared-period', actual: $refused->getRule());
		}

		$this->expectException(exception: RefusedException::class);
		$this->limits(declared: ['extensionAllowed' => false, 'extensionPeriodDays' => 0])
			->assertAllowed(run: $this->extension(newEnd: '2027-03-02', askedEnd: '2027-03-02'));
	}//end testTheDeclaredPeriodIsMeasuredToTheEndAskedFor()
}//end class
