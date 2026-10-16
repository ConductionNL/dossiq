<?php

/**
 * Case Type Private Records Test
 *
 * Every record a seeded case type keeps private to the case
 * (`reviewerAccess.records`) must have a schema that is private and declares
 * no `x-openregister-hierarchy`, read from the merged register the importer
 * reads. A record listed there whose schema reads for every authenticated
 * user is not private at all, and a hierarchy that points at another schema
 * makes OpenRegister refuse the whole schema at import
 * (`hierarchy.foreign-reference`). And the other way round: a private schema
 * no case type lists is never reconciled, so nobody but its owner reads it.
 *
 * Generic over every case type (decision 182); the Woo request type is the
 * first that lists records (woo-review-triage REQ-WRT-006).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-a-woo-request-is-an-authorisation-container-req-wrt-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CaseTypePrivateRecordsTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged = [];

	protected function setUp(): void {
		parent::setUp();
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$this->merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
	}//end setUp()

	/**
	 * The schema slugs every seeded case type lists under `reviewerAccess.records`, per case type slug.
	 *
	 * @return array<string, list<string>>
	 */
	private function privateRecordSchemas(): array {
		$slugByKey = array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY);
		$out = [];
		foreach (($this->merged['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') !== 'caseType') {
				continue;
			}

			foreach ((array)($object['reviewerAccess']['records'] ?? []) as $record) {
				$key = (string)($record['schemaKey'] ?? '');
				self::assertArrayHasKey($key, $slugByKey, 'reviewerAccess names a schema setting no schema answers to: ' . $key);
				$out[(string)($object['@self']['slug'] ?? '?')][] = $slugByKey[$key];
			}
		}

		return $out;
	}//end privateRecordSchemas()

	/**
	 * Every listed record's schema is private and declares no hierarchy.
	 *
	 * @return void
	 */
	public function testEveryPrivateRecordSchemaIsPrivateAndDeclaresNoHierarchy(): void {
		$declared = $this->privateRecordSchemas();
		self::assertNotSame([], ($declared['woo-verzoek'] ?? []), 'The Woo request type lists its private records.');

		foreach ($declared as $caseType => $slugs) {
			foreach ($slugs as $slug) {
				$schema = ($this->merged['components']['schemas'][$slug] ?? null);
				self::assertIsArray($schema, $caseType . ' lists ' . $slug . ', which the register does not ship');
				self::assertSame('private', ($schema['authorization']['scope'] ?? null), $slug . ' is listed by ' . $caseType . ' but not private');
				self::assertArrayNotHasKey('x-openregister-hierarchy', $schema, $slug . ' declares a hierarchy');
				self::assertSame(['dossiq-coordinators'], ($schema['authorization']['delete'] ?? null), $slug . ': only the supervisors delete');
			}
		}
	}//end testEveryPrivateRecordSchemaIsPrivateAndDeclaresNoHierarchy()

	/**
	 * The Woo request type lists the records of REQ-WRT-006 whose creators reconcile, and none reads for all users.
	 *
	 * @return void
	 */
	public function testNoListedRecordIsReadableByAllUsers(): void {
		$listed = ($this->privateRecordSchemas()['woo-verzoek'] ?? []);
		foreach (['wooDocumentAssessment', 'reviewBatch', 'triageRule', 'documentReview', 'wooSearchPlan', 'wooExclusion', 'wooCollectionQuery'] as $slug) {
			self::assertContains($slug, $listed);
		}

		foreach ($this->merged['components']['schemas'] as $slug => $schema) {
			$auth = (array)($schema['authorization'] ?? []);
			if (in_array('authenticated', (array)($auth['read'] ?? []), true) === true && in_array($slug, $listed, true) === true) {
				self::assertSame('private', ($auth['scope'] ?? null), $slug . ' reads for every authenticated user');
			}
		}
	}//end testNoListedRecordIsReadableByAllUsers()
}//end class
