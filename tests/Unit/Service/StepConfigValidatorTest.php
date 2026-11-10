<?php

/**
 * The step config validator, every refusal with its path and code
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\StepConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * What the validator answers per malformed or dangling config entry.
 *
 * @covers \OCA\Dossiq\Service\StepConfigValidator
 * @uses \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class StepConfigValidatorTest extends TestCase {
	/**
	 * The path and code of each error, in order.
	 *
	 * @param array $errors The validator's answer.
	 *
	 * @return array<int, string>
	 */
	private static function codes(array $errors): array {
		return array_map(static fn (array $e): string => $e['path'] . ' ' . $e['code'], $errors);
	}//end codes()

	/**
	 * No config, a config that is no object, and an empty config.
	 *
	 * @return void
	 */
	public function testAMissingOrMalformedConfig(): void {
		$this->assertSame([], StepConfigValidator::validate(step: []));
		$this->assertSame([], StepConfigValidator::validate(step: ['config' => []]));
		$this->assertSame(
			[['path' => 'steps[2].config', 'code' => 'malformed_config', 'message' => 'config must be an object']],
			StepConfigValidator::validate(step: ['config' => 'x'], stepIndex: 2)
		);
	}//end testAMissingOrMalformedConfig()

	/**
	 * Every sla, requiredFields and autoActions refusal, in validation order.
	 *
	 * @return void
	 */
	public function testEveryEntryRefusalInOrder(): void {
		$step = [
			'config' => [
				'sla' => ['value' => 0, 'unit' => 'fortnights'],
				'requiredFields' => ['title', '', 7, 'ghost'],
				'autoActions' => ['x', ['parameters' => []], ['key' => 'nope'], ['key' => 'ok']],
			],
		];
		$errors = StepConfigValidator::validate(step: $step, caseTypeSchema: ['properties' => ['title' => []]], actionCatalog: ['ok'], stepIndex: 1);

		$this->assertSame(
			[
				'steps[1].config.sla.value out_of_range',
				'steps[1].config.sla.unit unknown_sla_unit',
				'steps[1].config.requiredFields[1] malformed_required_field',
				'steps[1].config.requiredFields[2] malformed_required_field',
				'steps[1].config.requiredFields[3] unknown_field_reference',
				'steps[1].config.autoActions[0] malformed_action_ref',
				'steps[1].config.autoActions[1].key malformed_action_key',
				'steps[1].config.autoActions[2].key unknown_action_key',
			],
			self::codes($errors)
		);
		$this->assertSame('requiredFields entries must be non-empty strings', $errors[2]['message']);
		$this->assertSame('autoActions[i].key is not registered in the action catalog', $errors[7]['message']);
	}//end testEveryEntryRefusalInOrder()

	/**
	 * Entries that are no list, and a field list without a schema to check against.
	 *
	 * @return void
	 */
	public function testMalformedListsAndAnUncheckedSchema(): void {
		$this->assertSame(
			['steps[0].config.sla malformed_sla', 'steps[0].config.requiredFields malformed_required_fields', 'steps[0].config.autoActions malformed_auto_actions'],
			self::codes(StepConfigValidator::validate(step: ['config' => ['sla' => 'x', 'requiredFields' => 'x', 'autoActions' => 'x']]))
		);
		$this->assertSame([], StepConfigValidator::validate(step: ['config' => ['requiredFields' => ['ghost'], 'sla' => ['value' => 3, 'unit' => StepConfigValidator::SLA_UNITS[0]]]]));
	}//end testMalformedListsAndAnUncheckedSchema()
}//end class
