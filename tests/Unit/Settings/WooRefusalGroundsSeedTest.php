<?php

/**
 * The seeded refusal grounds are exactly the settled list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-the-grounds-are-settled-against-the-law-before-they-are-seeded-req-wrg-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WRG-001, REQ-WRG-002 and REQ-WRG-003 against the merged register.
 *
 * @coversNothing
 */
class WooRefusalGroundsSeedTest extends TestCase {

	/**
	 * The seeded ground objects of the merged register.
	 *
	 * @param RealSchemaValidator $register The merged register.
	 *
	 * @return list<array<string, mixed>> The ground objects.
	 */
	private function seeded(RealSchemaValidator $register): array {
		return array_values(
			array_filter(
				$register->objects,
				static fn (array $object): bool => (($object['@self']['schema'] ?? '') === 'wooRefusalGround')
			)
		);
	}//end seeded()

	/**
	 * The seed carries the settled entries of D-2, no more and no fewer.
	 *
	 * @return void
	 */
	public function testTheSeedIsExactlyTheSettledList(): void {
		$settled = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/woo-refusal-grounds-d2.json'),
			true
		)['grounds'];

		$seeded = [];
		foreach ($this->seeded(register: new RealSchemaValidator()) as $object) {
			$seeded[] = [
				'code' => $object['code'],
				'article' => $object['article'],
				'paragraph' => $object['paragraph'],
				'letter' => $object['letter'],
				'label' => $object['label'],
				'kind' => ($object['kind'] ?? null),
				'parent' => ($object['parent'] ?? null),
				'citable' => $object['citable'],
			];
		}

		$this->assertSame($settled, $seeded);
		$this->assertCount(21, array_filter($seeded, static fn (array $row): bool => $row['citable'] === true));
	}//end testTheSeedIsExactlyTheSettledList()

	/**
	 * Every parent is a seeded group node; every seeded object fits the schema.
	 *
	 * @return void
	 */
	public function testEveryNarrowerGroundNamesAnExistingParent(): void {
		$register = new RealSchemaValidator();
		$byCode = [];
		foreach ($this->seeded(register: $register) as $object) {
			$byCode[$object['code']] = $object;
		}

		foreach ($byCode as $code => $object) {
			$this->assertSame([], $register->errors(slug: 'wooRefusalGround', payload: $object), $code);
			$parent = ($object['parent'] ?? null);
			if ($parent === null) {
				continue;
			}

			$this->assertArrayHasKey($parent, $byCode, $code . ' names a parent that is not seeded');
			$this->assertFalse($byCode[$parent]['citable'], $code . ' sits under a citable ground');
		}
	}//end testEveryNarrowerGroundNamesAnExistingParent()

	/**
	 * Writes are for admins and the beheerders group, reads for every account.
	 *
	 * @return void
	 */
	public function testAHandlerCannotWriteAGround(): void {
		$authorization = (new RealSchemaValidator())->schemas['wooRefusalGround']['authorization'];

		$this->assertSame(['authenticated'], $authorization['read']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin', 'beheerders'], $authorization[$action], $action);
			$this->assertNotContains('behandelaars', $authorization[$action], $action);
		}
	}//end testAHandlerCannotWriteAGround()
}//end class
