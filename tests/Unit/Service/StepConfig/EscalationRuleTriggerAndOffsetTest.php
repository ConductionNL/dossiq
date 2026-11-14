<?php

/**
 * The trigger, offset and offsetUnit of an escalation rule, and the rules that let a rule pass.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\StepConfig
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

namespace OCA\Dossiq\Tests\Unit\Service\StepConfig;

use OCA\Dossiq\Service\StepConfig\EscalationRuleValidator;
use PHPUnit\Framework\TestCase;

/**
 * Every allowed trigger and unit passes; every other value is refused on its own path.
 *
 * @covers \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator
 */
class EscalationRuleTriggerAndOffsetTest extends TestCase {

	private const SLA = ['value' => 10, 'unit' => 'days'];

	/**
	 * Validate a rule against a ten-day sla under path `r`.
	 *
	 * @param array<string, mixed> $rule      The rule.
	 * @param array<string, mixed> $roleTypes The case type's roles.
	 *
	 * @return array<int, array{path: string, code: string, message: string}>
	 */
	private function validate(array $rule, array $roleTypes = []): array {
		return (new EscalationRuleValidator())->validate($rule, self::SLA, $roleTypes, 'r');
	}//end validate()

	/**
	 * A complete rule on every allowed trigger and unit has no errors.
	 *
	 * @return void
	 */
	public function testEveryAllowedTriggerAndUnitPasses(): void {
		foreach (EscalationRuleValidator::TRIGGERS as $trigger) {
			foreach (EscalationRuleValidator::OFFSET_UNITS as $unit) {
				$this->assertSame(
					[],
					$this->validate(['trigger' => $trigger, 'offset' => 0, 'offsetUnit' => $unit, 'openIncident' => false]),
					$trigger . '/' . $unit
				);
			}
		}

		$this->assertSame(['preBreach', 'slaBreached'], EscalationRuleValidator::TRIGGERS);
		$this->assertSame(['hours', 'businessDays'], EscalationRuleValidator::OFFSET_UNITS);
	}//end testEveryAllowedTriggerAndUnitPasses()

	/**
	 * A trigger outside the enum, or not a string, is refused with the allowed list.
	 *
	 * @return void
	 */
	public function testAnUnknownTriggerIsRefused(): void {
		foreach (['onBreach', 'PREBREACH', 1, true] as $trigger) {
			$this->assertSame(
				[['path' => 'r.trigger', 'code' => 'unknown_trigger', 'message' => 'escalationRule.trigger must be one of: preBreach, slaBreached']],
				$this->validate(['trigger' => $trigger, 'offset' => 1, 'offsetUnit' => 'hours'])
			);
		}
	}//end testAnUnknownTriggerIsRefused()

	/**
	 * A negative, fractional, numeric-string or missing offset is out of range.
	 *
	 * @return void
	 */
	public function testAnOffsetMustBeANonNegativeInteger(): void {
		$refused = [['path' => 'r.offset', 'code' => 'out_of_range', 'message' => 'escalationRule.offset must be a non-negative integer']];

		foreach ([-1, 1.5, '3', null] as $offset) {
			$this->assertSame($refused, $this->validate(['trigger' => 'slaBreached', 'offset' => $offset, 'offsetUnit' => 'hours']), var_export($offset, true));
		}

		$this->assertSame([], $this->validate(['trigger' => 'slaBreached', 'offset' => 0, 'offsetUnit' => 'hours']));
	}//end testAnOffsetMustBeANonNegativeInteger()

	/**
	 * A unit outside the enum, including plain `days`, is refused with the allowed list.
	 *
	 * @return void
	 */
	public function testAnUnknownOffsetUnitIsRefused(): void {
		foreach (['days', 'minutes', '', 24] as $unit) {
			$this->assertSame(
				[['path' => 'r.offsetUnit', 'code' => 'unknown_offset_unit', 'message' => 'escalationRule.offsetUnit must be one of: hours, businessDays']],
				$this->validate(['trigger' => 'slaBreached', 'offset' => 2, 'offsetUnit' => $unit])
			);
		}
	}//end testAnUnknownOffsetUnitIsRefused()

	/**
	 * A malformed preBreach offset is reported once, as out of range, not also as exceeding the sla.
	 *
	 * @return void
	 */
	public function testAMalformedPreBreachOffsetIsNotComparedWithTheSla(): void {
		$codes = array_column($this->validate(['trigger' => 'preBreach', 'offset' => '99', 'offsetUnit' => 'hours']), 'code');

		$this->assertSame(['out_of_range'], $codes);
	}//end testAMalformedPreBreachOffsetIsNotComparedWithTheSla()

	/**
	 * Every failing part is reported, in the order sla, timing, offset bound, roles, openIncident.
	 *
	 * @return void
	 */
	public function testAllFailuresOfOneRuleAreReportedInOrder(): void {
		$errors = (new EscalationRuleValidator())->validate(
			['trigger' => 'preBreach', 'offset' => 11, 'offsetUnit' => 'weeks', 'notifyRole' => 'ghost', 'openIncident' => 1],
			self::SLA,
			['behandelaar' => []],
			'r'
		);

		$this->assertSame(
			['r.offsetUnit unknown_offset_unit', 'r.offset offset_exceeds_sla', 'r.notifyRole unknown_role_reference', 'r.openIncident malformed_open_incident'],
			array_map(static fn (array $e): string => $e['path'] . ' ' . $e['code'], $errors)
		);
	}//end testAllFailuresOfOneRuleAreReportedInOrder()

	/**
	 * Absent role keys are skipped; present ones that resolve pass.
	 *
	 * @return void
	 */
	public function testAbsentRolesAreSkippedAndResolvingRolesPass(): void {
		$roles = ['behandelaar' => [], 'teamleider' => []];

		$this->assertSame([], $this->validate(['trigger' => 'slaBreached', 'offset' => 1, 'offsetUnit' => 'hours', 'notifyRole' => null], $roles));
		$this->assertSame([], $this->validate(['trigger' => 'slaBreached', 'offset' => 1, 'offsetUnit' => 'hours', 'notifyRole' => 'behandelaar', 'escalateToRole' => 'teamleider'], $roles));
	}//end testAbsentRolesAreSkippedAndResolvingRolesPass()
}//end class
