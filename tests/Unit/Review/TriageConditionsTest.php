<?php

/**
 * Tests for TriageConditions: a triage rule's conditions, checked and matched.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
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

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\TriageConditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Review\TriageConditions
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageConditionsTest extends TestCase {

	/**
	 * Facts of a mail from the newsletter domain, held by A. de Wit, with text.
	 *
	 * @param array<string, mixed> $over Facts to replace.
	 *
	 * @return array{custodian: string, sourceSystem: string, mimeType: string, date: string, senderDomain: string, text: ?string}
	 */
	private static function facts(array $over = []): array {
		return ($over + [
			'custodian' => 'A. de Wit',
			'sourceSystem' => 'exchange',
			'mimeType' => 'message/rfc822',
			'date' => '2025-03-03',
			'senderDomain' => 'mail.nieuwsbrief.example.nl',
			'text' => 'Nieuwsbrief Buitenruimte oktober',
		]);
	}//end facts()

	/**
	 * Stored conditions keep the known fields, trimmed, and drop the empty ones.
	 *
	 * @return void
	 */
	public function testNormaliseKeepsTheKnownFieldsTrimmed(): void {
		$conditions = (new TriageConditions())->normalise(raw: ['custodian' => ' A. de Wit ', 'mimeType' => '', 'dateFrom' => '2025-01-01']);

		$this->assertSame(['custodian' => 'A. de Wit', 'dateFrom' => '2025-01-01'], $conditions);
	}//end testNormaliseKeepsTheKnownFieldsTrimmed()

	/**
	 * An unknown field, a date that is no date and no condition at all are refused.
	 *
	 * @return void
	 */
	public function testNormaliseRefusesWhatCannotMatch(): void {
		foreach ([
			'triage-rule-condition-unknown' => ['department' => 'X'],
			'triage-rule-date-invalid' => ['dateTo' => '31-12-2024'],
			'triage-rule-condition-required' => ['custodian' => '  '],
		] as $rule => $raw) {
			try {
				(new TriageConditions())->normalise(raw: $raw);
				$this->fail('Expected '.$rule);
			} catch (RefusedException $e) {
				$this->assertSame($rule, $e->getRule());
			}
		}
	}//end testNormaliseRefusesWhatCannotMatch()

	/**
	 * Every condition must hold; a sender domain matches its subdomains, a mime kind ends in a star.
	 *
	 * @return void
	 */
	public function testEveryConditionMustHold(): void {
		$conditions = new TriageConditions();

		$this->assertTrue($conditions->matches(conditions: ['senderDomain' => 'nieuwsbrief.example.nl', 'mimeType' => 'message/*'], facts: self::facts()));
		$this->assertTrue($conditions->matches(conditions: ['dateFrom' => '2025-01-01', 'dateTo' => '2025-12-31', 'custodian' => 'A. de Wit'], facts: self::facts()));
		$this->assertFalse($conditions->matches(conditions: ['senderDomain' => 'nieuwsbrief.example.nl', 'custodian' => 'B. Smit'], facts: self::facts()));
		$this->assertFalse($conditions->matches(conditions: ['senderDomain' => 'example.org'], facts: self::facts()));
		$this->assertFalse($conditions->matches(conditions: ['dateTo' => '2024-12-31'], facts: self::facts()));
		$this->assertFalse($conditions->matches(conditions: [], facts: self::facts()), 'no condition matches nothing');
	}//end testEveryConditionMustHold()

	/**
	 * A fact that is not known never matches: no text, no sender domain, no date.
	 *
	 * @return void
	 */
	public function testAnUnknownFactNeverMatches(): void {
		$conditions = new TriageConditions();
		$unknown = self::facts(['text' => null, 'senderDomain' => '', 'date' => '', 'mimeType' => '']);

		$this->assertFalse($conditions->matches(conditions: ['textContains' => 'nieuwsbrief'], facts: $unknown));
		$this->assertFalse($conditions->matches(conditions: ['textNotContains' => 'fietspad'], facts: $unknown));
		$this->assertFalse($conditions->matches(conditions: ['senderDomain' => 'example.nl'], facts: $unknown));
		$this->assertFalse($conditions->matches(conditions: ['dateFrom' => '2000-01-01'], facts: $unknown));
		$this->assertFalse($conditions->matches(conditions: ['mimeType' => 'message/*'], facts: $unknown));
		$this->assertTrue($conditions->matches(conditions: ['textNotContains' => 'fietspad'], facts: self::facts()));
		$this->assertTrue($conditions->matches(conditions: ['textContains' => 'BUITENRUIMTE'], facts: self::facts()), 'text matches regardless of case');
	}//end testAnUnknownFactNeverMatches()

	/**
	 * Only the text conditions need the text read.
	 *
	 * @return void
	 */
	public function testOnlyTextConditionsNeedTheText(): void {
		$conditions = new TriageConditions();

		$this->assertTrue($conditions->needsText(conditions: ['custodian' => 'A', 'textNotContains' => 'x']));
		$this->assertFalse($conditions->needsText(conditions: ['custodian' => 'A', 'senderDomain' => 'x.nl']));
	}//end testOnlyTextConditionsNeedTheText()

	/**
	 * Only active rules with an effect and conditions run, by order and then by name.
	 *
	 * @return void
	 */
	public function testRunnableRulesAreActiveAndInOrder(): void {
		$rules = [
			['name' => 'B', 'order' => 2, 'effect' => 'in-scope', 'conditions' => ['custodian' => 'A']],
			['name' => 'A', 'order' => 2, 'effect' => 'in-scope', 'conditions' => ['custodian' => 'A']],
			['name' => 'First', 'order' => 1, 'effect' => 'out-of-scope', 'conditions' => ['custodian' => 'A']],
			['name' => 'Off', 'order' => 0, 'effect' => 'in-scope', 'conditions' => ['custodian' => 'A'], 'active' => false],
			['name' => 'No effect', 'order' => 0, 'effect' => 'maybe', 'conditions' => ['custodian' => 'A']],
			['name' => 'No conditions', 'order' => 0, 'effect' => 'in-scope', 'conditions' => []],
		];

		$names = array_column((new TriageConditions())->runnable(rules: $rules, effects: ['in-scope', 'out-of-scope']), 'name');

		$this->assertSame(['First', 'A', 'B'], $names);
	}//end testRunnableRulesAreActiveAndInOrder()
}//end class
