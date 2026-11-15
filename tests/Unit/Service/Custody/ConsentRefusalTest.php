<?php

/**
 * ConsentRefusal test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
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
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-hand-off-across-organisations-needs-recorded-consent-req-cst-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Custody\ConsentRefusal;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The refusal of a hand-off is worded through IL10N, and reads in Dutch as the board draws it.
 *
 * @covers \OCA\Dossiq\Service\Custody\ConsentRefusal
 */
class ConsentRefusalTest extends TestCase {

	/**
	 * Each scan picks its rule and names the receiver, and the lapsed one names the date.
	 *
	 * @return void
	 */
	public function testEachScanPicksItsRuleAndSentence(): void {
		$refusal = new ConsentRefusal(l10n: new SourceL10n());
		$cases = [
			[['none' => true, 'lapsedOn' => '', 'withdrawn' => false], CaseTransferConsentGate::NO_CONSENT, 'There is no recorded consent for Zorg BV, so'],
			[['none' => false, 'lapsedOn' => '2026-04-01', 'withdrawn' => true], CaseTransferConsentGate::CONSENT_LAPSED, 'ran to 2026-04-01 and has ended'],
			[['none' => false, 'lapsedOn' => '', 'withdrawn' => true], CaseTransferConsentGate::CONSENT_WITHDRAWN, 'for Zorg BV was withdrawn'],
			[['none' => false, 'lapsedOn' => '', 'withdrawn' => false], CaseTransferConsentGate::NO_CONSENT, 'for Zorg BV covering today'],
		];

		foreach ($cases as [$scan, $rule, $words]) {
			$outcome = $refusal->uncovered(scan: $scan + ['covering' => null], receiver: 'Zorg BV');
			self::assertFalse(condition: $outcome['allowed']);
			self::assertSame(expected: $rule, actual: $outcome['rule']);
			self::assertStringContainsString(needle: $words, haystack: $outcome['sentence']);
			self::assertNull(actual: $outcome['consent']);
		}
	}//end testEachScanPicksItsRuleAndSentence()

	/**
	 * A Dutch user reads the board's sentence when the consent rule could not be read.
	 *
	 * @return void
	 */
	public function testUncheckedReadsAsTheBoardInDutch(): void {
		$outcome = (new ConsentRefusal(l10n: $this->dutch()))->unchecked();

		self::assertSame(expected: CaseTransferConsentGate::CONSENT_UNCHECKED, actual: $outcome['rule']);
		self::assertSame(
			expected: 'De toestemming kon niet worden gecontroleerd, dus de zaak is niet overgedragen.',
			actual: $outcome['sentence'],
		);
	}//end testUncheckedReadsAsTheBoardInDutch()

	/**
	 * A Dutch user reads every other refusal in Dutch, with the receiver filled in.
	 *
	 * @return void
	 */
	public function testEveryRefusalHasADutchSentence(): void {
		$refusal = new ConsentRefusal(l10n: $this->dutch());
		$scans = [
			['none' => true, 'lapsedOn' => '', 'withdrawn' => false],
			['none' => false, 'lapsedOn' => '2026-04-01', 'withdrawn' => false],
			['none' => false, 'lapsedOn' => '', 'withdrawn' => true],
			['none' => false, 'lapsedOn' => '', 'withdrawn' => false],
		];

		foreach ($scans as $scan) {
			$sentence = $refusal->uncovered(scan: $scan + ['covering' => null], receiver: 'Zorg BV')['sentence'];
			self::assertStringContainsString(needle: 'Zorg BV', haystack: $sentence);
			self::assertStringEndsWith(suffix: 'dus de zaak is niet overgedragen.', string: $sentence);
		}
	}//end testEveryRefusalHasADutchSentence()

	/**
	 * An IL10N that answers from the app's Dutch catalogue.
	 *
	 * @return IL10N The localisation.
	 */
	private function dutch(): IL10N {
		$catalogue = json_decode((string)file_get_contents(__DIR__ . '/../../../../l10n/nl.json'), true)['translations'];
		$l10n = $this->createStub(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(($catalogue[$text] ?? $text), $parameters)
		);

		return $l10n;
	}//end dutch()
}//end class
