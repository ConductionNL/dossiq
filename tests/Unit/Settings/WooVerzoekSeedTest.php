<?php

/**
 * The Woo request case type is seeded, not only templated.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-case-type-is-seeded-req-wri-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Woo\WooRequestIntake;
use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register the importer reads, and checks the Woo request type in it.
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 *
 * @coversNothing
 */
class WooVerzoekSeedTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged = [];

	/**
	 * Merge the base register with its fragments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$this->merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
	}//end setUp()

	/**
	 * The seeded objects of one schema that belong to the Woo request type.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function wooObjects(string $schema): array {
		$out = [];
		foreach (($this->merged['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') !== $schema) {
				continue;
			}

			$owner = (string)($object['caseType'] ?? '');
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($owner === 'woo-verzoek' || $owner === WooRequestIntake::CASE_TYPE_ID || $slug === 'woo-verzoek') {
				$out[] = $object;
			}
		}

		return $out;
	}//end wooObjects()

	/**
	 * The type carries its deadline, extension and initial status under the id the intake uses.
	 *
	 * @return void
	 */
	public function testTheCaseTypeIsSeededUnderTheIdTheIntakeOpens(): void {
		$types = $this->wooObjects(schema: 'caseType');
		self::assertCount(1, $types);
		$type = $types[0];
		self::assertSame(WooRequestIntake::CASE_TYPE_ID, $type['id']);
		self::assertSame('woo-verzoek', $type['identifier']);
		self::assertSame('P28D', $type['processingDeadline']);
		self::assertTrue($type['extensionAllowed']);
		self::assertSame('P14D', $type['extensionPeriod']);
		self::assertSame('3c0f5a00-0000-4000-a000-00000000b001', $type['initialStatus']);
	}//end testTheCaseTypeIsSeededUnderTheIdTheIntakeOpens()

	/**
	 * Eight statuses in order, the last one final.
	 *
	 * @return void
	 */
	public function testEightStatusesFromOntvangstToAfgehandeld(): void {
		$statuses = $this->wooObjects(schema: 'statusType');
		self::assertCount(8, $statuses);
		usort($statuses, fn (array $a, array $b): int => ($a['order'] <=> $b['order']));
		self::assertSame('Ontvangst', $statuses[0]['name']);
		self::assertSame('Afgehandeld', $statuses[7]['name']);
		self::assertTrue($statuses[7]['isFinal']);
		foreach (array_slice($statuses, 0, 7) as $status) {
			self::assertFalse($status['isFinal']);
			self::assertNotSame('', (string)($status['publicLabel'] ?? ''));
		}
	}//end testEightStatusesFromOntvangstToAfgehandeld()

	/**
	 * The four result types woo-case-type requires.
	 *
	 * @return void
	 */
	public function testFourResultTypes(): void {
		$names = array_column($this->wooObjects(schema: 'resultType'), 'name');
		sort($names);
		self::assertSame(['Deels openbaar gemaakt', 'Ingetrokken', 'Niet openbaar gemaakt', 'Openbaar gemaakt'], $names);
	}//end testFourResultTypes()

	/**
	 * The portal windows open in the first two statuses, withdrawal lands on the final one.
	 *
	 * @return void
	 */
	public function testThePortalWindowsOpenBeforeTheAssessment(): void {
		$type = $this->wooObjects(schema: 'caseType')[0];
		// Status uuids, because the case's status is one and portaliq compares them.
		$first = ['3c0f5a00-0000-4000-a000-00000000b001', '3c0f5a00-0000-4000-a000-00000000b002'];
		self::assertSame($first, $type['portalAmendmentWindow']['openStatuses']);
		self::assertSame($first, $type['portalWithdrawal']['openStatuses']);
		self::assertSame('3c0f5a00-0000-4000-a000-00000000b008', $type['portalWithdrawal']['targetStatus']);
		self::assertContains('3c0f5a00-0000-4000-a000-00000000b001', $type['portalDocumentWindow']['openStatuses']);
	}//end testThePortalWindowsOpenBeforeTheAssessment()

	/**
	 * Every key a seeded Woo object writes is declared by its schema, and the case declares wooRequest.
	 *
	 * @return void
	 */
	public function testEverySeededKeyIsDeclared(): void {
		$schemas = $this->merged['components']['schemas'];
		foreach (['caseType', 'statusType', 'resultType', 'propertyDefinition'] as $schema) {
			$declared = array_keys($schemas[$schema]['properties']);
			foreach ($this->wooObjects(schema: $schema) as $object) {
				foreach (array_keys($object) as $key) {
					if (in_array($key, ['@self', 'id', 'uuid'], true) === true) {
						continue;
					}

					self::assertContains($key, $declared, $schema . ' does not declare ' . $key);
				}
			}
		}

		$wooRequest = $schemas['case']['properties']['wooRequest'];
		self::assertSame('object', $wooRequest['type']);
		foreach (['onderwerp', 'omschrijving', 'periodeVan', 'periodeTot', 'origin', 'originReference', 'collectionId'] as $field) {
			self::assertArrayHasKey($field, $wooRequest['properties']);
			self::assertIsString($wooRequest['properties'][$field]['type']);
		}
	}//end testEverySeededKeyIsDeclared()
}//end class
