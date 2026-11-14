<?php

/**
 * The pre-breach offset bound and the role references of an escalation rule.
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
 * Rule 7 (offset within the sla) and rule 5 (roles resolve).
 *
 * @covers \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator
 */
class EscalationRuleTimingAndRolesTest extends TestCase {

	private const SLA = ['value' => 5, 'unit' => 'days'];

	private function errors(array $rule, mixed $sla = self::SLA, array $roleTypes = []): array {
		$errors = (new EscalationRuleValidator())->validate($rule, $sla, $roleTypes, 'p');

		return array_values(array_filter($errors, static fn (array $e): bool => in_array($e['code'], ['offset_exceeds_sla', 'malformed_role_reference', 'unknown_role_reference'], true)));
	}

	public function testAPreBreachOffsetBeyondTheSlaIsRefused(): void {
		$this->assertSame(
			[['path' => 'p.offset', 'code' => 'offset_exceeds_sla', 'message' => 'escalationRule.offset must not exceed sla.value when trigger is preBreach']],
			$this->errors(['trigger' => 'preBreach', 'offset' => 6, 'offsetUnit' => 'days'])
		);
	}

	public function testAnOffsetWithinTheSlaOrOnAnotherTriggerPasses(): void {
		$this->assertSame([], $this->errors(['trigger' => 'preBreach', 'offset' => 5, 'offsetUnit' => 'days']));
		$this->assertSame([], $this->errors(['trigger' => 'slaBreached', 'offset' => 9, 'offsetUnit' => 'days']));
		$this->assertSame([], $this->errors(['trigger' => 'preBreach', 'offset' => 9], ['value' => '5']));
	}

	public function testMalformedRoleReferencesAreRefused(): void {
		$this->assertSame(
			[
				['path' => 'p.notifyRole', 'code' => 'malformed_role_reference', 'message' => 'notifyRole must be a non-empty role reference'],
				['path' => 'p.escalateToRole', 'code' => 'malformed_role_reference', 'message' => 'escalateToRole must be a non-empty role reference'],
			],
			$this->errors(['notifyRole' => '', 'escalateToRole' => ['x']])
		);
	}

	public function testRolesMustResolveOnlyWhenTheCaseTypeDeclaresRoles(): void {
		$this->assertSame(
			[['path' => 'p.escalateToRole', 'code' => 'unknown_role_reference', 'message' => 'escalateToRole does not resolve to a roleType on the linked caseType']],
			$this->errors(['notifyRole' => 'behandelaar', 'escalateToRole' => 'ghost'], roleTypes: ['behandelaar' => ['name' => 'Behandelaar']])
		);
		$this->assertSame([], $this->errors(['notifyRole' => 'anyone', 'escalateToRole' => 'ghost']));
	}
}
