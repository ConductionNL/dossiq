<?php

/**
 * EscalationRuleValidator tests.
 *
 * Pins the order of the errors an escalation rule collects: the missing SLA
 * first, then the timing, the pre-breach offset and the roles, and the
 * openIncident flag last; no errors without a rule, one for a rule that is
 * not an object.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\StepConfig
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\StepConfig;

use OCA\Dossiq\Service\StepConfig\EscalationRuleValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EscalationRuleValidator::validate().
 */
class EscalationRuleValidatorTest extends TestCase {
	/**
	 * The codes and paths of a rule's errors, in order.
	 *
	 * @param array<int, array<string, string>> $errors The errors.
	 *
	 * @return array<int, string> "path code" per error.
	 */
	private static function codes(array $errors): array {
		return array_map(static fn (array $error): string => $error['path'] . ' ' . $error['code'], $errors);
	}//end codes()

	/**
	 * Every error of a bad rule, in order; none without a rule; one for a rule that is not an object.
	 *
	 * @return void
	 */
	public function testTheErrorsComeInOrder(): void {
		$validator = new EscalationRuleValidator();

		self::assertSame(
			[
				'steps.0.escalationRule escalation_requires_sla',
				'steps.0.escalationRule.trigger unknown_trigger',
				'steps.0.escalationRule.offset out_of_range',
				'steps.0.escalationRule.offsetUnit unknown_offset_unit',
				'steps.0.escalationRule.openIncident malformed_open_incident',
			],
			self::codes($validator->validate(['openIncident' => 'yes', 'afterMinutes' => -5, 'notifyRoles' => ['ghost']], null, ['behandelaar'], 'steps.0.escalationRule'))
		);
		self::assertSame(['p malformed_escalation_rule'], self::codes($validator->validate('x', null, [], 'p')));
		self::assertSame([], $validator->validate(null, null, [], 'p'));
		self::assertNotContains('p.openIncident malformed_open_incident', self::codes($validator->validate(['openIncident' => true], ['hours' => 8], [], 'p')));
		self::assertNotContains('p escalation_requires_sla', self::codes($validator->validate(['openIncident' => true], ['hours' => 8], [], 'p')));
	}//end testTheErrorsComeInOrder()
}//end class
