<?php

/**
 * ZGW Resource Map Consistency Tests
 *
 * Holds the three lists that describe one ZGW resource together: the route
 * lookup, the repair step that writes the mapping, and the mapping service's
 * own inventory of keys.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ZGW route table, the default mappings and the mapping key inventory
 * describe the same resources and must agree.
 *
 * A value in {@see ZgwService::RESOURCE_MAP} is the suffix of the
 * `zgw_mapping_<key>` appconfig entry {@see LoadDefaultZgwMappings} writes.
 * When the two disagree the endpoint does not error in any way a gate sees: it
 * answers 404 "No ZGW mapping configured for <api>/<resource>", which reads
 * exactly like an unimplemented route.
 *
 * That is not hypothetical. `zaken/zaken` said `case` and
 * `documenten/verzendingen` said `dispatch` — schema names, not mapping keys —
 * while the repair step writes `zaak` and `verzending`. The whole ZRC zaken
 * surface was dark, and with it every VNG contract assertion that needs a zaak.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwResourceMapConsistencyTest extends TestCase {

	/**
	 * Every mapping key the default repair step writes.
	 *
	 * @return string[]
	 */
	private function defaultMappingKeys(): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn([]);

		$repair = new LoadDefaultZgwMappings(
			$this->createMock(ZgwMappingService::class),
			$settings,
			$this->createMock(LoggerInterface::class),
		);

		return array_keys($repair->getDefaultMappings('1'));
	}//end defaultMappingKeys()

	/**
	 * Every value in the route table resolves to a mapping the repair step
	 * actually writes.
	 *
	 * @return void
	 */
	public function testEveryRoutedResourceHasADefaultMapping(): void {
		$written = $this->defaultMappingKeys();
		$this->assertNotEmpty($written, 'The repair step wrote no default mappings at all.');

		$missing = [];
		foreach (ZgwService::RESOURCE_MAP as $api => $resources) {
			foreach ($resources as $resource => $mappingKey) {
				if (in_array($mappingKey, $written, true) === false) {
					$missing[] = sprintf('%s/%s -> zgw_mapping_%s', $api, $resource, $mappingKey);
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			"These ZGW routes point at a mapping key no repair step writes, so every request to them\n"
			. "answers 404 'No ZGW mapping configured':\n  " . implode("\n  ", $missing)
		);
	}//end testEveryRoutedResourceHasADefaultMapping()

	/**
	 * Every value in the route table is a key the mapping service knows about.
	 *
	 * `getResourceKeys()` is what the admin mapping API enumerates, so a key
	 * absent from it is a mapping no operator can inspect or repair.
	 *
	 * @return void
	 */
	public function testEveryRoutedResourceIsAKnownMappingKey(): void {
		$known = (new ZgwMappingService(
			$this->createMock(\OCP\IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		))->getResourceKeys();

		$unknown = [];
		foreach (ZgwService::RESOURCE_MAP as $api => $resources) {
			foreach ($resources as $resource => $mappingKey) {
				if (in_array($mappingKey, $known, true) === false) {
					$unknown[] = sprintf('%s/%s -> %s', $api, $resource, $mappingKey);
				}
			}
		}

		$this->assertSame(
			[],
			$unknown,
			"These ZGW routes name a mapping key ZgwMappingService does not list:\n  "
			. implode("\n  ", $unknown)
		);
	}//end testEveryRoutedResourceIsAKnownMappingKey()

	/**
	 * The mapping service's inventory and the repair step's output are the
	 * same set, in both directions.
	 *
	 * `listMappings()` walks the inventory, so a key the repair step writes but
	 * the inventory omits is a mapping the admin screen cannot show, and a key
	 * the inventory holds but the repair step never writes reads back as null.
	 * Neither raises anything.
	 *
	 * @return void
	 */
	public function testTheMappingInventoryAndTheDefaultsAreTheSameSet(): void {
		$written = $this->defaultMappingKeys();
		$known = (new ZgwMappingService(
			$this->createMock(\OCP\IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		))->getResourceKeys();

		sort($written);
		sort($known);

		$this->assertSame(
			$written,
			$known,
			'ZgwMappingService::RESOURCE_KEYS and LoadDefaultZgwMappings::getDefaultMappings() '
			. 'must name the same zgw_mapping_* keys.'
		);
	}//end testTheMappingInventoryAndTheDefaultsAreTheSameSet()

	/**
	 * The two resources whose keys were wrong, named explicitly.
	 *
	 * The loops above would also pass if somebody deleted the entries. These
	 * two assertions are what stops that.
	 *
	 * @return void
	 */
	public function testZaakAndVerzendingKeepTheirMappingKeys(): void {
		$this->assertSame(
			'zaak',
			ZgwService::RESOURCE_MAP['zaken']['zaken'],
			'zaken/zaken must resolve to zgw_mapping_zaak, not to the case schema name.'
		);
		$this->assertSame(
			'verzending',
			ZgwService::RESOURCE_MAP['documenten']['verzendingen'],
			'documenten/verzendingen must resolve to zgw_mapping_verzending, not to the dispatch schema name.'
		);
	}//end testZaakAndVerzendingKeepTheirMappingKeys()
}//end class
