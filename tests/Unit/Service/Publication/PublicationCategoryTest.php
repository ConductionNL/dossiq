<?php

/**
 * Unit tests for the publication category a decision type names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Publication
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\PublicationCategory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The category is read from the decision type row, with the former default as fallback.
 *
 * @covers \OCA\Dossiq\Service\Publication\PublicationCategory
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class PublicationCategoryTest extends TestCase {

	/**
	 * The seeded Woo decision type.
	 */
	private const WOO_TYPE = '3c0f5a00-0000-4000-a000-00000000d001';

	/**
	 * A category another procedure could name.
	 */
	private const DECISIONS = [
		'code' => 'infocat016',
		'label' => 'Beschikkingen',
		'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_46a81018',
	];

	/**
	 * The decision types.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * A reader over the store.
	 *
	 * @return PublicationCategory
	 */
	private function categories(): PublicationCategory {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'decision_type_schema' => 'decisionType'][$key] ?? $default)
		);

		return new PublicationCategory(settingsService: $settings);
	}//end categories()

	/**
	 * The decision type row names the category its decisions are filed under.
	 *
	 * @return void
	 */
	public function testTheDecisionTypeRowNamesTheCategory(): void {
		$this->store->seed(schema: 'decisionType', uuid: 'type-1', row: ['name' => 'Subsidiebesluit', 'publicationCategory' => self::DECISIONS]);

		$this->assertSame(self::DECISIONS, $this->categories()->forDecision(decision: ['decisionType' => 'type-1']));
	}//end testTheDecisionTypeRowNamesTheCategory()

	/**
	 * The shipped Woo decision type carries the category Woo decisions were always filed under, valid on the schema.
	 *
	 * @return void
	 */
	public function testTheShippedWooDecisionTypeCarriesItsCategory(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/81-woo-verzoek.json'), true);
		$rows = array_values(array_filter(
			$fragment['components']['objects'],
			static fn (array $row): bool => ($row['id'] ?? '') === self::WOO_TYPE
		));
		$this->assertCount(1, $rows);

		$this->store->seed(schema: 'decisionType', uuid: self::WOO_TYPE, row: $rows[0]);
		$this->assertSame(PublicationCategory::FALLBACK, $this->categories()->forDecision(decision: ['decisionType' => self::WOO_TYPE]));
		$this->assertSame(
			[],
			(new RealSchemaValidator())->errors(slug: 'decisionType', payload: ['publicationCategory' => $rows[0]['publicationCategory']], creating: false)
		);
	}//end testTheShippedWooDecisionTypeCarriesItsCategory()

	/**
	 * A legacy type name, an unknown or incomplete row, no type, or no store all fall back.
	 *
	 * @return void
	 */
	public function testEverythingElseFallsBack(): void {
		$this->store->seed(schema: 'decisionType', uuid: 'bare', row: ['name' => 'Zonder categorie']);
		$this->store->seed(schema: 'decisionType', uuid: 'half', row: ['publicationCategory' => ['code' => 'infocat016', 'label' => '', 'uri' => 'x']]);
		$this->store->seed(schema: 'decisionType', uuid: 'odd', row: ['publicationCategory' => 'infocat016']);
		$categories = $this->categories();

		foreach (['WOO-besluit', 'unknown', 'bare', 'half', 'odd', ''] as $type) {
			$this->assertSame(PublicationCategory::FALLBACK, $categories->forDecision(decision: ['decisionType' => $type]), $type);
		}

		$this->assertSame(PublicationCategory::FALLBACK, $categories->forDecision(decision: []));
		$this->assertSame(PublicationCategory::FALLBACK, (new PublicationCategory())->forDecision(decision: ['decisionType' => 'type-1']));
	}//end testEverythingElseFallsBack()

	/**
	 * The fallback is the category the code mapped every decision to before.
	 *
	 * @return void
	 */
	public function testTheFallbackIsTheFormerDefault(): void {
		$this->assertSame(
			[
				'code' => 'infocat014',
				'label' => 'Woo-verzoeken en -besluiten',
				'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
			],
			PublicationCategory::FALLBACK
		);
	}//end testTheFallbackIsTheFormerDefault()
}//end class
