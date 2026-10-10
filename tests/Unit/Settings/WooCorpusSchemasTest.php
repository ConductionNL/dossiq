<?php

/**
 * Woo Corpus Schemas Test
 *
 * The corpus of a Woo request is held in four schemas. OpenRegister drops an
 * undeclared property in silence, so every key the spec names is asserted as
 * declared in the merged register, and the slug map gives each a config key.
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
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

class WooCorpusSchemasTest extends TestCase {

	/**
	 * The keys each schema must declare, from REQ-WRC-001 to REQ-WRC-005.
	 */
	private const KEYS = [
		'wooSearchPlan' => ['case', 'custodians', 'systems', 'periodFrom', 'periodTo', 'terms', 'recordedBy', 'recordedAt'],
		'wooRequestConfiguration' => ['case', 'custodians', 'systems', 'terms', 'copiedFrom'],
		'wooExclusion' => ['case', 'documentRef', 'source', 'location', 'fileName', 'sha256', 'reason', 'note', 'excludedBy', 'excludedAt'],
		'wooCollectionQuery' => ['case', 'source', 'terms', 'periodFrom', 'periodTo', 'filters', 'runBy', 'runAt', 'resultKeys'],
	];

	public function testThePlanDeclaresEveryKey(): void {
		$real = new RealSchemaValidator();
		foreach (self::KEYS as $slug => $keys) {
			self::assertArrayHasKey($slug, $real->schemas, $slug . ' ships');
			foreach ($keys as $key) {
				self::assertArrayHasKey($key, $real->schemas[$slug]['properties'], $slug . '.' . $key . ' is declared');
			}
		}

		self::assertArrayNotHasKey('periodFrom', $real->schemas['wooRequestConfiguration']['properties'], 'the configuration carries no period');
		self::assertSame(['duplicate', 'out-of-period', 'out-of-scope', 'unreadable'], $real->schemas['wooExclusion']['properties']['reason']['enum']);
	}//end testThePlanDeclaresEveryKey()

	public function testTheProvenanceCarriesCustodianAndSystem(): void {
		$real = new RealSchemaValidator();
		foreach (['informatieobject', 'zaakinformatieobject'] as $slug) {
			$provenance = $real->schemas[$slug]['properties']['provenance']['properties'];
			self::assertArrayHasKey('custodian', $provenance);
			self::assertArrayHasKey('sourceSystem', $provenance);
		}
	}//end testTheProvenanceCarriesCustodianAndSystem()

	public function testEverySchemaHasAConfigKey(): void {
		foreach (array_keys(self::KEYS) as $slug) {
			self::assertArrayHasKey($slug, SchemaSlugMap::SLUG_TO_CONFIG_KEY, $slug . ' has a config key');
			self::assertLessThan(64, strlen(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug]));
		}
	}//end testEverySchemaHasAConfigKey()
}//end class
