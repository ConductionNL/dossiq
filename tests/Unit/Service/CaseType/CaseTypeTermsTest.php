<?php

/**
 * A case type's terms read as words, as board DqZaaktype writes them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeTerms;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the terms a case type page shows.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeTerms
 */
class CaseTypeTermsTest extends TestCase {

	/**
	 * The service over one effective case type, with an English translator.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return CaseTypeTerms The service.
	 */
	private function terms(array $caseType): CaseTypeTerms {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(
				str_replace('%n', (string) $count, ($count === 1 ? $singular : $plural)),
				$parameters
			)
		);

		return new CaseTypeTerms(resolver: $resolver, l10n: $l10n);
	}//end terms()

	/**
	 * Whole weeks read as weeks, with a single extension.
	 *
	 * @return void
	 */
	public function testATermWithOneExtensionReadsInWeeks(): void {
		$result = $this->terms(caseType: [
			'processingDeadline' => 'P56D',
			'extensionAllowed'   => true,
			'extensionPeriod'    => 'P42D',
			'maxExtensions'      => 1,
		])->termsFor(caseTypeId: 'ct');

		$this->assertSame('8 weeks, extendable once by 6 weeks', $result['decisionTerm']);
	}//end testATermWithOneExtensionReadsInWeeks()

	/**
	 * No extension when the case type allows none, and days that are not whole weeks stay days.
	 *
	 * @return void
	 */
	public function testATermWithoutExtensionStaysPlain(): void {
		$result = $this->terms(caseType: [
			'processingDeadline' => 'P10D',
			'extensionAllowed'   => false,
			'extensionPeriod'    => 'P14D',
		])->termsFor(caseTypeId: 'ct');

		$this->assertSame('10 days', $result['decisionTerm']);
	}//end testATermWithoutExtensionStaysPlain()

	/**
	 * Several extensions name how many; an unbounded one names none.
	 *
	 * @return void
	 */
	public function testTheNumberOfExtensionsIsNamed(): void {
		$twice = $this->terms(caseType: ['processingDeadline' => 'P1M', 'extensionPeriod' => 'P1M', 'maxExtensions' => 2])->termsFor(caseTypeId: 'ct');
		$open  = $this->terms(caseType: ['processingDeadline' => 'P1M', 'extensionPeriod' => 'P1M'])->termsFor(caseTypeId: 'ct');

		$this->assertSame('1 month, extendable 2 times by 1 month', $twice['decisionTerm']);
		$this->assertSame('1 month, extendable by 1 month', $open['decisionTerm']);
	}//end testTheNumberOfExtensionsIsNamed()

	/**
	 * Suspension reads allowed, with its maximum, or not allowed; unset stays empty.
	 *
	 * @return void
	 */
	public function testSuspensionReadsAsASentence(): void {
		$capped  = $this->terms(caseType: ['suspensionAllowed' => true, 'maxSuspensionDays' => 28])->termsFor(caseTypeId: 'ct');
		$free    = $this->terms(caseType: ['suspensionAllowed' => true])->termsFor(caseTypeId: 'ct');
		$refused = $this->terms(caseType: ['suspensionAllowed' => false])->termsFor(caseTypeId: 'ct');
		$unset   = $this->terms(caseType: ['processingDeadline' => 'P1W'])->termsFor(caseTypeId: 'ct');

		$this->assertSame('Allowed, at most 4 weeks', $capped['suspension']);
		$this->assertSame('Allowed', $free['suspension']);
		$this->assertSame('Not allowed', $refused['suspension']);
		$this->assertSame('', $unset['suspension']);
	}//end testSuspensionReadsAsASentence()

	/**
	 * A service target that is a duration reads as one; other text passes as written.
	 *
	 * @return void
	 */
	public function testTheServiceTargetReadsAsADuration(): void {
		$duration = $this->terms(caseType: ['serviceTarget' => 'P2W'])->termsFor(caseTypeId: 'ct');
		$text     = $this->terms(caseType: ['serviceTarget' => 'Within a working day'])->termsFor(caseTypeId: 'ct');

		$this->assertSame('2 weeks', $duration['serviceTarget']);
		$this->assertSame('Within a working day', $text['serviceTarget']);
	}//end testTheServiceTargetReadsAsADuration()

	/**
	 * Whether a late decision costs a penalty (Wet dwangsom), after a notice
	 * of default, with the most it can come to when set; unset stays empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-says-whether-a-late-decision-costs-a-penalty
	 */
	public function testThePenaltyOnALateDecisionReadsAsASentence(): void {
		$capped = $this->terms(caseType: ['penaltyOnLateDecision' => true, 'penaltyMaxAmount' => 1442])->termsFor(caseTypeId: 'ct');
		$plain  = $this->terms(caseType: ['penaltyOnLateDecision' => true])->termsFor(caseTypeId: 'ct');
		$none   = $this->terms(caseType: ['penaltyOnLateDecision' => false])->termsFor(caseTypeId: 'ct');
		$unset  = $this->terms(caseType: ['processingDeadline' => 'P1W'])->termsFor(caseTypeId: 'ct');

		$this->assertSame(expected: 'Yes, after a notice of default, at most € 1442', actual: $capped['penalty']);
		$this->assertSame(expected: 'Yes, after a notice of default', actual: $plain['penalty']);
		$this->assertSame(expected: 'No', actual: $none['penalty']);
		$this->assertSame(expected: '', actual: $unset['penalty']);
		$this->assertTrue(condition: $plain['penaltyApplies']);
		$this->assertFalse(condition: $none['penaltyApplies']);
		$this->assertFalse(condition: $unset['penaltyApplies']);
	}//end testThePenaltyOnALateDecisionReadsAsASentence()

	/**
	 * An unreadable case type answers null, not empty words.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeAnswersNull(): void {
		$this->assertNull($this->terms(caseType: [])->termsFor(caseTypeId: 'gone'));
	}//end testAnUnreadableCaseTypeAnswersNull()
}//end class
