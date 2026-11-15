<?php

/**
 * LinkMarkerRule edge tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\LinkMarkerRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\LinkMarkerRule
 */
class LinkMarkerRuleEdgesTest extends TestCase {

	/**
	 * A rule over the given conditions.
	 *
	 * @param array<int, array<string, mixed>> $when The conditions.
	 * @return LinkMarkerRule The rule.
	 */
	private static function rule(array $when): LinkMarkerRule {
		return new LinkMarkerRule(config: ['source' => 's', 'field' => 'f', 'target' => 't', 'marker' => 'm', 'when' => $when]);
	}//end rule()

	/**
	 * A configuration that is not an object is refused.
	 *
	 * @return void
	 */
	public function testANonObjectConfigurationIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A marker rule must be an object.');

		new LinkMarkerRule(config: 'followUpOpen');
	}//end testANonObjectConfigurationIsRefused()

	/**
	 * A blank or non-string name is refused like a missing one.
	 *
	 * @return void
	 */
	public function testABlankOrNonStringNameIsRefused(): void {
		foreach ([['source' => '  '], ['field' => 5], ['target' => null]] as $broken) {
			try {
				new LinkMarkerRule(config: array_merge(['source' => 's', 'field' => 'f', 'target' => 't', 'marker' => 'm'], $broken));
				self::fail('A blank or non-string name must be refused.');
			} catch (InvalidArgumentException $e) {
				self::assertSame('A marker rule needs `'.array_key_first($broken).'`.', $e->getMessage());
			}
		}
	}//end testABlankOrNonStringNameIsRefused()

	/**
	 * The names are trimmed, and conditions are kept as a list.
	 *
	 * @return void
	 */
	public function testTheNamesAreTrimmedAndTheConditionsListed(): void {
		$rule = new LinkMarkerRule(
			config: [
				'source' => ' appeal ',
				'field'  => ' record ',
				'target' => ' decision ',
				'marker' => ' open ',
				'when'   => ['first' => ['field' => 'x', 'present' => true]],
			]
		);

		self::assertSame('appeal', $rule->source);
		self::assertSame('record', $rule->field);
		self::assertSame('decision', $rule->target);
		self::assertSame('open', $rule->marker);
		self::assertSame([['field' => 'x', 'present' => true]], $rule->conditions);
	}//end testTheNamesAreTrimmedAndTheConditionsListed()

	/**
	 * A rule without conditions always holds.
	 *
	 * @return void
	 */
	public function testARuleWithoutConditionsAlwaysHolds(): void {
		$rule = new LinkMarkerRule(config: ['source' => 's', 'field' => 'f', 'target' => 't', 'marker' => 'm']);

		self::assertSame([], $rule->conditions);
		self::assertTrue($rule->holds(source: [], target: []));
	}//end testARuleWithoutConditionsAlwaysHolds()

	/**
	 * `present: false` holds exactly when the field is blank, missing or not a scalar.
	 *
	 * @return void
	 */
	public function testPresentFalseHoldsForABlankField(): void {
		$rule = self::rule(when: [['field' => 'subject', 'present' => false]]);

		self::assertTrue($rule->holds(source: [], target: []));
		self::assertTrue($rule->holds(source: ['subject' => '   '], target: []));
		self::assertTrue($rule->holds(source: ['subject' => ['dec-1']], target: []));
		self::assertFalse($rule->holds(source: ['subject' => 'dec-1'], target: []));
		self::assertFalse($rule->holds(source: ['subject' => 0], target: []));
	}//end testPresentFalseHoldsForABlankField()

	/**
	 * Booleans are compared as `true` / `false`, numbers as their text.
	 *
	 * @return void
	 */
	public function testBooleansAndNumbersAreComparedAsText(): void {
		$rule = self::rule(when: [['field' => 'urgent', 'in' => ['true']], ['field' => 'count', 'notIn' => ['0']]]);

		self::assertTrue($rule->holds(source: ['urgent' => true, 'count' => 3], target: []));
		self::assertFalse($rule->holds(source: ['urgent' => false, 'count' => 3], target: []));
		self::assertFalse($rule->holds(source: ['urgent' => true, 'count' => 0], target: []));
		self::assertFalse($rule->holds(source: ['urgent' => 'TRUE', 'count' => 3], target: []));
	}//end testBooleansAndNumbersAreComparedAsText()

	/**
	 * A date that cannot be read never blocks the marker.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateDoesNotBlock(): void {
		$rule = self::rule(when: [['field' => 'filedOn', 'notAfter' => 'deadline']]);

		self::assertTrue($rule->holds(source: ['filedOn' => 'soon', 'deadline' => '2026-03-10'], target: []));
		self::assertTrue($rule->holds(source: ['filedOn' => '2026-03-11', 'deadline' => 'whenever'], target: []));
		self::assertFalse($rule->holds(source: ['filedOn' => '2026-03-11', 'deadline' => '2026-03-10'], target: []));
	}//end testAnUnreadableDateDoesNotBlock()

	/**
	 * A condition on the target reads the target, never the source.
	 *
	 * @return void
	 */
	public function testATargetConditionReadsTheTarget(): void {
		$rule = self::rule(when: [['on' => 'target', 'field' => 'status', 'in' => ['closed']]]);

		self::assertTrue($rule->holds(source: ['status' => 'open'], target: ['status' => 'closed']));
		self::assertFalse($rule->holds(source: ['status' => 'closed'], target: ['status' => 'open']));
	}//end testATargetConditionReadsTheTarget()

	/**
	 * `present` and `notAfter` of the wrong type are refused.
	 *
	 * @return void
	 */
	public function testPresentAndNotAfterOfTheWrongTypeAreRefused(): void {
		foreach ([['field' => 'x', 'present' => 'yes'], ['field' => 'x', 'notAfter' => 5], ['field' => 'x', 'notIn' => 'a']] as $condition) {
			try {
				self::rule(when: [$condition]);
				self::fail('A condition of the wrong type must be refused.');
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString('condition of the wrong type', $e->getMessage());
				self::assertStringContainsString("Marker rule 'm'", $e->getMessage());
			}
		}
	}//end testPresentAndNotAfterOfTheWrongTypeAreRefused()

	/**
	 * A condition that is not an object is refused for its missing field.
	 *
	 * @return void
	 */
	public function testANonObjectConditionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Marker rule 'm' has a condition without a `field`.");

		self::rule(when: ['status']);
	}//end testANonObjectConditionIsRefused()
}//end class
