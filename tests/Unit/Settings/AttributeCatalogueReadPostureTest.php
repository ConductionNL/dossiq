<?php

/**
 * The attribute catalogue's read posture, pinned.
 *
 * WHAT WAS ASKED. An e2e run on 2026-09-19 reported that any account can read
 * the attribute catalogue, and its spec said the catalogue is an administrative
 * surface whose rows are the organisation's data model. Measured with curl:
 *
 * ```
 * GET /apps/openregister/api/objects/dossiq/propertyDefinition/29590203-...
 *   no credentials               -> 404 {"error":"Not Found"}
 *   e2e-other (ordinary account) -> 200 {"caseType":"...","category":"Uncategorised",
 *                                        "defaultValue":"2500000","name":"plafond..."}
 * ```
 *
 * WHAT WAS DECIDED, AND WHY. The read is intended and stays open to every
 * authenticated user. A property definition is a case type's field vocabulary,
 * not a record about anybody. `CaseTypeFieldFilters.loadDefinitions()`
 * (`src/components/search/CaseTypeFieldFilters.vue`) fetches these rows from the
 * handler's own browser to build the case list filter bar, and it answers a
 * refusal with an empty filter set. So scoping the read to administrators would
 * not show handlers a refusal. It would show them a case type that declares no
 * fields, which is the silent failure this fleet keeps paying for. The boundary
 * that matters, the one without a session, is already enforced.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT. OpenRegister enforces a schema's
 * `authorization` block, and this register already uses it: `dossiqIntegration`
 * is read by administrators only. The catalogue's openness is therefore a
 * decision and not an omission, and the second test below is the control that
 * says so. Anyone who scopes the read later has to come here, read the reason,
 * and change the decision on purpose.
 *
 * STILL OPEN, AND DELIBERATELY NOT DONE HERE. Nothing scopes who may WRITE the
 * catalogue. Scoping it needs a live probe first, because the shipped seeders
 * write these rows through the same RBAC-checked path with no session user
 * (`TemplateBundleSeeder.php:474`, `VthSeedDataRepairStep.php:448`), so a create
 * rule that refuses Anonymous could refuse `occ maintenance:repair` too.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/property-definition-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class AttributeCatalogueReadPostureTest extends TestCase {

	/**
	 * The shipped register, decoded.
	 *
	 * @return array<string, mixed> The register.
	 */
	private function register(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/dossiq_register.json';
		$this->assertFileExists($path);

		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, 'the shipped register must be valid JSON');

		return $decoded;
	}//end register()

	/**
	 * One schema from the shipped register.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $slug): array {
		$schemas = ($this->register()['components']['schemas'] ?? []);
		$this->assertArrayHasKey($slug, $schemas, 'the register must still ship ' . $slug);

		return $schemas[$slug];
	}//end schema()

	/**
	 * The decision: the attribute catalogue is readable by every signed-in user.
	 *
	 * @return void
	 */
	public function testTheAttributeCatalogueDeclaresNoReadRestriction(): void {
		$authorization = ($this->schema('propertyDefinition')['authorization'] ?? []);

		$this->assertArrayNotHasKey(
			'read',
			$authorization,
			'the attribute catalogue is a case type\'s field vocabulary and every handler '
			. 'reads it to filter their own case list, so scoping the read hides fields '
			. 'rather than refusing them. Read the docblock before changing this.'
		);
	}//end testTheAttributeCatalogueDeclaresNoReadRestriction()

	/**
	 * The control: scoping a read IS available in this register, and used.
	 *
	 * Without this, the test above says nothing. An absent rule and an
	 * unavailable mechanism look identical.
	 *
	 * @return void
	 */
	public function testTheRegisterDoesScopeAnAdministrativeSchema(): void {
		$authorization = ($this->schema('dossiqIntegration')['authorization'] ?? []);

		$this->assertSame(
			['admin'],
			($authorization['read'] ?? []),
			'the integration register is administrators only, which is what makes the '
			. 'catalogue\'s open read a decision rather than an omission'
		);
	}//end testTheRegisterDoesScopeAnAdministrativeSchema()
}//end class
