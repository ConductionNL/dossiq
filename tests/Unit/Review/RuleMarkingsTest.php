<?php

/**
 * RuleMarkings unit tests.
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

use OCA\Dossiq\Review\RuleMarkings;
use PHPUnit\Framework\TestCase;

/**
 * What the triage rules of a case have marked.
 *
 * @covers \OCA\Dossiq\Review\RuleMarkings
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RuleMarkingsTest extends TestCase {

	/**
	 * A rule counts the documents it set and the markings a reviewer overturned; a rule with neither reads zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testEachRuleIsListedWithWhatItDecidedAndWhatWasOverturned(): void {
		$markings = new RuleMarkings(
			reviews: [
				'd1' => ['rule' => 'Bijlagen', 'relevanceSource' => 'rule'],
				'd2' => ['rule' => 'Bijlagen', 'relevanceSource' => 'rule'],
				'd3' => ['rule' => 'Bijlagen', 'relevanceSource' => 'reviewer', 'overturnedRule' => 'Bijlagen'],
				'd4' => ['rule' => '', 'relevanceSource' => 'rule'],
			]
		);

		$this->assertSame(
			expected: [
				['name' => 'Bijlagen', 'order' => 1, 'decided' => 2, 'overturned' => 1],
				['name' => 'Concepten', 'order' => 2, 'decided' => 0, 'overturned' => 0],
			],
			actual: $markings->onRules(rules: [['name' => 'Bijlagen', 'order' => 1], ['name' => 'Concepten', 'order' => 2]])
		);
	}//end testEachRuleIsListedWithWhatItDecidedAndWhatWasOverturned()

	/**
	 * A marked review carries the rule's effect, the rule as its source and name, and the moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testARuleMarksAReviewWithItsEffectAndName(): void {
		$marked = (new RuleMarkings(reviews: []))->marked(
			review: ['document' => 'd1', 'relevance' => null],
			rule: ['name' => 'Bijlagen', 'effect' => 'outOfScope']
		);

		$this->assertSame(expected: 'd1', actual: $marked['document']);
		$this->assertSame(expected: 'outOfScope', actual: $marked['relevance']);
		$this->assertSame(expected: 'rule', actual: $marked['relevanceSource']);
		$this->assertSame(expected: 'Bijlagen', actual: $marked['rule']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', string: $marked['markedAt']);
	}//end testARuleMarksAReviewWithItsEffectAndName()
}//end class
