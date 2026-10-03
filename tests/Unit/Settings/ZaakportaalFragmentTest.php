<?php

/**
 * Zaakportaal Register Fragment Unit Tests
 *
 * Verifies that the register.d/50-zaakportaal.json fragment unions its citizen
 * portal schemas, register membership and seed objects onto the dossiq
 * monolith via the ADR-037 deep-merge loader, without disturbing the existing
 * schemas (case, document, decision) the portal reads from.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style unit tests for the zaakportaal register fragment.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class ZaakportaalFragmentTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * Load the monolith and merge the real register.d fragments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);

		$this->merged = $merged;
	}//end setUp()

	/**
	 * The portal schemas are present after the merge.
	 *
	 * @return void
	 */
	public function testPortalSchemasPresent(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey(key: 'portaalBericht', array: $schemas);
		$this->assertArrayHasKey(key: 'portaalVerzoek', array: $schemas);
		$this->assertArrayHasKey(key: 'portaalNotificatieVoorkeur', array: $schemas);
	}//end testPortalSchemasPresent()

	/**
	 * The portal does not introduce a citizen/contact schema; it reads cases
	 * and documents from the existing schemas.
	 *
	 * @return void
	 */
	public function testExistingReadSchemasUntouched(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey(key: 'case', array: $schemas);
		$this->assertArrayHasKey(key: 'document', array: $schemas);
		$this->assertArrayNotHasKey(key: 'portaalContact', array: $schemas, message: 'Portal must reuse identity, not invent a contact schema');
	}//end testExistingReadSchemasUntouched()

	/**
	 * The portal schemas are unioned into the dossiq register membership,
	 * keeping the pre-existing membership intact.
	 *
	 * @return void
	 */
	public function testPortalSchemasJoinDossiqRegister(): void {
		$schemas = $this->merged['components']['registers']['dossiq']['schemas'];
		$this->assertContains(needle: 'portaalBericht', haystack: $schemas);
		$this->assertContains(needle: 'portaalVerzoek', haystack: $schemas);
		$this->assertContains(needle: 'portaalNotificatieVoorkeur', haystack: $schemas);
		// KCC fragment membership still present (additive union, not overwrite).
		$this->assertContains(needle: 'callbackRequest', haystack: $schemas);
	}//end testPortalSchemasJoinDossiqRegister()

	/**
	 * The portal seed objects are concatenated onto the objects list.
	 *
	 * @return void
	 */
	public function testPortalSeedObjectsUnioned(): void {
		$objects = $this->merged['components']['objects'];
		$slugs = array_map(
			static fn (array $object): string => (string)($object['@self']['slug'] ?? ''),
			array_filter($objects, 'is_array')
		);

		$this->assertContains(needle: 'portaal-pref-demo-burger', haystack: $slugs);
		$this->assertContains(needle: 'portaal-bericht-demo-1', haystack: $slugs);
	}//end testPortalSeedObjectsUnioned()

	/**
	 * The portaalNotificatieVoorkeur schema marks Berichtenbox support.
	 *
	 * @return void
	 */
	public function testPreferenceSchemaHasBerichtenbox(): void {
		$properties = $this->merged['components']['schemas']['portaalNotificatieVoorkeur']['properties'];
		$this->assertArrayHasKey(key: 'messageBoxActive', array: $properties);
		$this->assertArrayHasKey(key: 'subjectRef', array: $properties);
	}//end testPreferenceSchemaHasBerichtenbox()

	/**
	 * The portal intake is scored by the same declared rules as the desk.
	 *
	 * 🔴 THIS IS WHAT CARRIES THE RULES INTO THE PORTAL, AND IT IS THE ONLY
	 * THING THAT DOES. `PortalContributionProvider` contributes no dedup key,
	 * because Portaliq reads none and an unknown key on a contribution is
	 * passed through untouched: it would sit there looking enforced for ever.
	 * What is real is the write. `PortalObjectWriter` creates through
	 * OpenRegister's `saveObject()`, the same path the desk uses, and that is
	 * where `x-openregister-dedup` is evaluated. So a declaration on this schema
	 * IS the portal half, and its absence would be a silent no-op.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function testPortalRequestsDeclareTheSameKindOfDedupRules(): void {
		$dedup = $this->merged['components']['schemas']['portaalVerzoek']['configuration']['x-openregister-dedup'] ?? null;

		$this->assertIsArray(actual: $dedup, message: 'portaalVerzoek must declare dedup rules for the portal intake');
		$this->assertSame(expected: ['submitterRef', 'kind'], actual: $dedup['blockingKeys']);
		$this->assertSame(expected: 'warn', actual: $dedup['onCreate'], message: 'a citizen is warned, never refused, by the schema');

		$properties = $this->merged['components']['schemas']['portaalVerzoek']['properties'];
		foreach ($dedup['matchRules'] as $rule) {
			$this->assertArrayHasKey(
				key: $rule['field'],
				array: $properties,
				message: 'a rule naming a property the schema does not declare compares nothing and reports nothing'
			);
		}

		$total = array_sum(array_column($dedup['matchRules'], 'weight'));
		$this->assertSame(expected: 1.0, actual: round($total, 4));
	}//end testPortalRequestsDeclareTheSameKindOfDedupRules()
}//end class
