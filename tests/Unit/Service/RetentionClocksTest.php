<?php

/**
 * The two clocks stay two clocks.
 *
 * C-access-and-privacy-65's clause is the whole test file: the AVG says delete
 * when the lawful purpose ends and the Archiefwet says keep for N years, and a
 * product that treats them as one rule is wrong in both directions. So the
 * assertions are about SEPARATION rather than about either date being right:
 * setting one must not move the other, and a case whose purpose has ended is
 * still inside its archive period rather than destroyed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Recycle\RetentionClocks;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The lawful-purpose clock and the archive clock, read apart.
 *
 * @covers \OCA\Dossiq\Service\Recycle\RetentionClocks
 */
class RetentionClocksTest extends TestCase {

	/**
	 * The case type behind the lawful-purpose retention.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver $caseTypes;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return RetentionClocks
	 */
	private function clocks(): RetentionClocks {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
		);

		return new RetentionClocks(
			caseTypes: $this->caseTypes,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end clocks()

	/**
	 * REQ-CRW-03: a case whose lawful purpose has ended and whose retention
	 * runs to 2034 carries both dates, labelled and different.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testBothDatesAreCarriedLabelledAndDifferent(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['lawfulPurposeRetention' => 12]);

		$clocks = $this->clocks()->clocksFor(
			case: [
				'caseType' => 'ct-bezwaar',
				'endDate' => '2023-01-31',
				'archiveActionDate' => '2034-01-31',
			]
		);

		$this->assertSame('2024-01-31', $clocks['lawfulPurpose']['date']);
		$this->assertSame('2034-01-31', $clocks['archive']['date']);
		$this->assertNotSame($clocks['lawfulPurpose']['date'], $clocks['archive']['date']);
		$this->assertNotSame('', $clocks['lawfulPurpose']['label']);
		$this->assertNotSame('', $clocks['archive']['label']);
		$this->assertNotSame($clocks['lawfulPurpose']['label'], $clocks['archive']['label']);
	}//end testBothDatesAreCarriedLabelledAndDifferent()

	/**
	 * REQ-CRW-03: setting the lawful-purpose date leaves the archive retention
	 * date exactly where it was. One rule does not set both dates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testOneRuleDoesNotSetBothDates(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['lawfulPurposeRetention' => 6]);

		$before = [
			'caseType' => 'ct-melding',
			'endDate' => '2026-03-31',
			'archiveActionDate' => '2031-03-31',
		];
		$after = $before;
		$after['lawfulPurposeEndDate'] = $this->clocks()->lawfulPurposeEndDate(case: $before);

		$this->assertSame('2026-09-30', $after['lawfulPurposeEndDate']);
		$this->assertSame($before['archiveActionDate'], $after['archiveActionDate']);
		$this->assertSame(
			'2031-03-31',
			$this->clocks()->clocksFor(case: $after)['archive']['date']
		);
	}//end testOneRuleDoesNotSetBothDates()

	/**
	 * The lawful-purpose clock counts from the case's own end date, never from
	 * the archive action date. A case handed only an archive date gets no
	 * lawful-purpose date at all, rather than a copy of the other one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheLawfulPurposeClockIsNotDerivedFromTheArchiveClock(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['lawfulPurposeRetention' => 24]);

		$clocks = $this->clocks()->clocksFor(
			case: [
				'caseType' => 'ct-bezwaar',
				'archiveActionDate' => '2034-01-31',
			]
		);

		$this->assertNull($clocks['lawfulPurpose']['date']);
		$this->assertSame('2034-01-31', $clocks['archive']['date']);
	}//end testTheLawfulPurposeClockIsNotDerivedFromTheArchiveClock()

	/**
	 * An open case has no lawful-purpose end date, because the purpose has not
	 * ended. A case type stating no retention has none either, and the rule
	 * says so instead of leaving the reader to guess.
	 *
	 * The month-end clamp in testOneRuleDoesNotSetBothDates is the same rule
	 * seen from the other side: six months from 31 March is 30 September, and
	 * an unclamped addition rolls it into October.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnOpenCaseAndAnUnstatedRetentionBothAnswerWithTheirRule(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn([]);

		$open = $this->clocks()->clocksFor(case: ['caseType' => 'ct-melding']);

		$this->assertNull($open['lawfulPurpose']['date']);
		$this->assertStringContainsString('no retention', $open['lawfulPurpose']['rule']);
		$this->assertStringContainsString('No archive action date', $open['archive']['rule']);
	}//end testAnOpenCaseAndAnUnstatedRetentionBothAnswerWithTheirRule()

	/**
	 * A stored lawful-purpose date wins over the derivation, so a decision an
	 * FG took by hand is not quietly recomputed away.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAStoredDateWinsOverTheDerivation(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['lawfulPurposeRetention' => 12]);

		$clocks = $this->clocks()->clocksFor(
			case: [
				'caseType' => 'ct-bezwaar',
				'endDate' => '2023-01-31',
				'lawfulPurposeEndDate' => '2025-06-30',
			]
		);

		$this->assertSame('2025-06-30', $clocks['lawfulPurpose']['date']);
	}//end testAStoredDateWinsOverTheDerivation()

	/**
	 * The two clocks disagree when the purpose has ended and the archive
	 * period has not, and the disagreement is reported rather than resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testADisagreementIsReported(): void {
		$this->caseTypes->method('effectiveCaseType')->willReturn(['lawfulPurposeRetention' => 1]);

		$disagreeing = $this->clocks()->clocksFor(
			case: [
				'caseType' => 'ct-bezwaar',
				'endDate' => '2020-01-31',
				'archiveActionDate' => '2034-01-31',
			]
		);
		$agreeing = $this->clocks()->clocksFor(
			case: [
				'caseType' => 'ct-bezwaar',
				'endDate' => '2020-01-31',
				'archiveActionDate' => '2020-03-31',
			]
		);

		$this->assertTrue($disagreeing['disagree']);
		$this->assertFalse($agreeing['disagree']);
	}//end testADisagreementIsReported()
}//end class
