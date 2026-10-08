<?php

/**
 * A Woo decision keeps what dossiq writes onto it.
 *
 * dossiq#3162. dossiq stores decisions in OpenRegister's per-schema table
 * (`RegisterStorageDeclaration` declares `magicMapping` for every dossiq
 * schema), and `MagicMapper::prepareObjectDataForTable()` maps only the
 * schema's DECLARED properties to columns. The `decision` schema declared none
 * of the Woo fields, so:
 *
 *   - `WooPublicationService::publish()` wrote `wooPublication` and the save
 *     dropped it. `withdraw()` then read no `publicationId` and answered
 *     `no_publication`, and a second publish created a second publication
 *     instead of updating the first;
 *   - `WOODecisionService::assembleDecision()` wrote `wooSummary`,
 *     `weigeringsgronden`, `assessmentCount` and `decidedBy`, and all four were
 *     dropped the same way.
 *
 * The second test goes through the services with a store that keeps only the
 * declared properties of the SHIPPED register, the rule the magic table
 * applies. A double that kept every key would have passed the whole time,
 * which is why the existing withdraw test, fed a decision that already carries
 * a publication, never saw this.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The decision schema declares the Woo fields, and a publication survives a save.
 *
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @uses \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 * @uses \OCA\Dossiq\Woo\WooCaseLedger
 */
class WooPublicationFieldsShippedTest extends TestCase {

	/**
	 * The rows the store keeps, by schema slug and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The `decision` schema as the shipped register declares it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function decisionSchema(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw, 'the register could not be read');

		return (array)(json_decode((string)$raw, true)['components']['schemas']['decision'] ?? []);
	}//end decisionSchema()

	/**
	 * Every field the Woo code writes onto a decision is declared, with its type.
	 *
	 * @return void
	 */
	public function testTheDecisionDeclaresEveryFieldTheWooCodeWrites(): void {
		$properties = (array)($this->decisionSchema()['properties'] ?? []);

		$expected = [
			'wooPublication' => 'object',
			'wooSummary' => 'object',
			'weigeringsgronden' => 'array',
			'assessmentCount' => 'integer',
			'decidedBy' => 'string',
		];
		foreach ($expected as $name => $type) {
			$this->assertArrayHasKey($name, $properties, sprintf('decision.%s is not declared, so the magic table drops it on save', $name));
			$this->assertSame($type, ($properties[$name]['type'] ?? null), sprintf('decision.%s has the wrong type', $name));
		}

		// What publish() and withdraw() write inside the object.
		$this->assertSame(
			['category', 'publicationId', 'publicationUrl', 'publishedAt', 'status', 'withdrawnAt'],
			$this->sortedKeys((array)($properties['wooPublication']['properties'] ?? []))
		);
		// What summariseAssessments() counts.
		$this->assertSame(
			['deels_openbaar', 'niet_openbaar', 'openbaar'],
			$this->sortedKeys((array)($properties['wooSummary']['properties'] ?? []))
		);
		$this->assertSame('string', ($properties['weigeringsgronden']['items']['type'] ?? null));
	}//end testTheDecisionDeclaresEveryFieldTheWooCodeWrites()

	/**
	 * A published decision can be withdrawn, through a store that keeps what the schema declares.
	 *
	 * @return void
	 */
	public function testAPublishedDecisionCanBeWithdrawn(): void {
		$declared = array_keys((array)($this->decisionSchema()['properties'] ?? []));

		$this->rows = [
			'case' => ['case-001' => ['id' => 'case-001', 'title' => 'Woo-verzoek']],
			'decision' => ['decision-001' => ['id' => 'decision-001', 'case' => 'case-001', 'decisionDate' => '2026-09-28']],
			'document' => [
				'doc-001' => [
					'id' => 'doc-001',
					'title' => 'Besluit',
					'fileName' => 'besluit.pdf',
					'format' => 'application/pdf',
					'content' => base64_encode('public content'),
				],
			],
		];

		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): ?ObjectEntityInterface {
				$row = ($this->rows[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);
		$store->method('searchObjectsBySlug')->willReturn([['documentRef' => 'doc-001', 'classification' => 'openbaar']]);
		$store->method('saveObject')->willReturnCallback(
			function (array|ObjectEntityInterface $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($declared): ObjectEntityInterface {
				// The magic table's rule: a key the schema does not declare has no
				// column, and is gone.
				$kept = array_intersect_key((array)$object, array_flip(array_merge($declared, ['id'])));
				$this->rows[(string)$schema][(string)$uuid] = $kept;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($kept);
				return $entity;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['case_schema', '', 'case'],
				['decision_schema', '', 'decision'],
				['woo_assessment_schema', '', 'wooAssessment'],
				['document_schema', '', 'document'],
				['woo_publication_catalog_slug', 'publication', 'publication'],
			]
		);
		$settings->method('getWooPublicationConfigValue')->willReturnMap(
			[
				['woo_publication_register', 'publication'],
				['woo_publication_schema', 'publication'],
				['woo_publication_document_schema', 'document'],
			]
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		$catalogi = $this->createMock(OpenCatalogiApiClient::class);
		$catalogi->method('createPublication')->willReturn(['id' => 'pub-001']);
		$catalogi->method('attachDocument')->willReturn(['id' => 'ocdoc-001']);
		$catalogi->expects($this->once())->method('updatePublication')->willReturn(['id' => 'pub-001']);

		$service = new WooPublicationService($settings, $catalogi, new WooCategoryMapper(), $apps, $this->createMock(LoggerInterface::class));

		$published = $service->publish('case-001', 'decision-001');
		$this->assertTrue($published['available'], 'the publish itself should succeed');
		$this->assertSame('pub-001', ($this->rows['decision']['decision-001']['wooPublication']['publicationId'] ?? null), 'the publication reference did not survive the save');

		$withdrawn = $service->withdraw('decision-001');
		$this->assertSame(['available' => true], $withdrawn, 'withdraw found no publication on the decision');
		$this->assertSame('withdrawn', ($this->rows['decision']['decision-001']['wooPublication']['status'] ?? null));
	}//end testAPublishedDecisionCanBeWithdrawn()

	/**
	 * The keys of a map, sorted.
	 *
	 * @param array<string, mixed> $map The map.
	 *
	 * @return array<int, string> The keys.
	 */
	private function sortedKeys(array $map): array {
		$keys = array_keys($map);
		sort($keys);

		return $keys;
	}//end sortedKeys()
}//end class
