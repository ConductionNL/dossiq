<?php

/**
 * Guards the schema declarations the case Actions menu rests on.
 *
 * Both are DECLARATIONS, so there is no dossiq code to test instead: the
 * Start entry is gated by a boolean OpenRegister computes, and the list it
 * reads is a property on the case type. This reads
 * `lib/Settings/dossiq_register.json`, the file that actually ships.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * `caseType.startableFlows` and the case's `hasStartableFlows` gate.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class CaseActionsSchemaTest extends TestCase {

	/**
	 * The shipped schemas, by slug.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas = [];

	/**
	 * Load the shipped register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw, 'the shipped register must be readable');

		$register = json_decode($raw, true);
		$this->assertIsArray($register, 'the shipped register must be valid JSON');

		$this->schemas = $register['components']['schemas'];
	}//end setUp()

	/**
	 * A case type lists the flows a handler may start.
	 *
	 * @return void
	 */
	public function testTheCaseTypeCarriesStartableFlows(): void {
		$property = $this->schemas['caseType']['properties']['startableFlows'];

		$this->assertSame('array', $property['type']);
		$this->assertSame('string', $property['items']['type']);
	}//end testTheCaseTypeCarriesStartableFlows()

	/**
	 * It is NOT a `$ref`.
	 *
	 * A `$ref` addresses a schema in a register. A flow is a row in
	 * `oc_openregister_flows` read through FlowService, and there is no flow
	 * schema for a `$ref` to name, so declaring one would render every entry
	 * as a broken reference and resolve nothing.
	 *
	 * @return void
	 */
	public function testStartableFlowsIsNotAReference(): void {
		$property = $this->schemas['caseType']['properties']['startableFlows'];

		$this->assertArrayNotHasKey('$ref', $property);
		$this->assertArrayNotHasKey('$ref', $property['items']);
	}//end testStartableFlowsIsNotAReference()

	/**
	 * The case carries the gate the Start action reads.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheStartGate(): void {
		$property = $this->schemas['case']['properties']['hasStartableFlows'];

		$this->assertSame('boolean', $property['type']);
		$this->assertTrue($property['readOnly']);
		$this->assertFalse($property['default']);
	}//end testTheCaseCarriesTheStartGate()

	/**
	 * The gate is materialised, not virtual.
	 *
	 * A cross-object `@ref` lookup resolves in OpenRegister's save-time
	 * listener and nowhere else, so a virtual calculation over
	 * `@ref.caseType.startableFlows` answers null at read time and the Start
	 * entry would be hidden on every case.
	 *
	 * @return void
	 */
	public function testTheGateIsMaterialised(): void {
		$calculation = $this->schemas['case']['configuration']['x-openregister-calculations']['hasStartableFlows'];

		$this->assertTrue($calculation['materialise']);
		$this->assertSame('boolean', $calculation['type']);
	}//end testTheGateIsMaterialised()

	/**
	 * The gate compares against null rather than counting.
	 *
	 * The calculation engine has no array operator at all (no `count`, no
	 * `length`), so an empty list cannot be counted. It CAN be compared: an
	 * empty array is loosely equal to null and a filled one is not, which is
	 * exactly the question. This test exists because a later author reaching
	 * for `count` would find the schema silently unevaluable.
	 *
	 * @return void
	 */
	public function testTheGateComparesAgainstNullBecauseThereIsNoCountOperator(): void {
		$calculation = $this->schemas['case']['configuration']['x-openregister-calculations']['hasStartableFlows'];

		$this->assertArrayHasKey('ne', $calculation['expression']);
		$this->assertSame(
			'@ref.caseType.startableFlows',
			$calculation['expression']['ne'][0]['prop']
		);
		$this->assertNull($calculation['expression']['ne'][1]);
	}//end testTheGateComparesAgainstNullBecauseThereIsNoCountOperator()

	/**
	 * Both schemas moved their version.
	 *
	 * OpenRegister fast-skips a schema whose `version` did not change, so a
	 * property added without a bump is stored in this file and absent from
	 * every install. Asserted against the versions this change shipped, so a
	 * later edit that forgets the bump fails here rather than in production.
	 *
	 * @return void
	 */
	public function testBothSchemasMovedTheirVersion(): void {
		$this->assertTrue(
			version_compare((string)$this->schemas['caseType']['version'], '1.3.0', '>='),
			'caseType must be at least 1.3.0, the version that introduced startableFlows'
		);
		$this->assertTrue(
			version_compare((string)$this->schemas['case']['version'], '1.18.0', '>='),
			'case must be at least 1.18.0, the version that introduced hasStartableFlows'
		);
	}//end testBothSchemasMovedTheirVersion()
}//end class
