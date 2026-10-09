<?php

/**
 * The refusal grounds as other apps read them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\Dossiq\Woo\WooRefusalGroundsUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WRG-007 over the seeded rows of the real fragment.
 *
 * @covers \OCA\Dossiq\Woo\WooRefusalGrounds
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooRefusalGroundsTest extends TestCase {

	/**
	 * The grounds service over a store.
	 *
	 * @param RefusalGroundStore $store The store.
	 *
	 * @return WooRefusalGrounds The service.
	 */
	private function grounds(RefusalGroundStore $store): WooRefusalGrounds {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);

		return new WooRefusalGrounds(settingsService: $settings, logger: new NullLogger());
	}//end grounds()

	/**
	 * Every active ground, in code order, with every key, read as the system.
	 *
	 * @return void
	 */
	public function testItAnswersActiveGroundsWithAllTenKeys(): void {
		$store = RefusalGroundStore::seeded();
		$list = $this->grounds(store: $store)->list();

		$this->assertCount(25, $list);
		foreach ($list as $ground) {
			$this->assertSame(WooRefusalGrounds::KEYS, array_keys($ground));
		}

		$codes = array_column($list, 'code');
		$this->assertSame('5.1', $codes[0]);
		$this->assertLessThan(array_search('5.1.2.e', $codes, true), array_search('5.1.1.e', $codes, true));
		$this->assertLessThan(array_search('5.4', $codes, true), array_search('5.2.2', $codes, true));
		$this->assertSame(['rbac' => false, 'multitenancy' => false], $store->reads[0]);
	}//end testItAnswersActiveGroundsWithAllTenKeys()

	/**
	 * A retired ground is left out unless asked for, and byCode still finds it.
	 *
	 * @return void
	 */
	public function testRetiredGroundsOnlyOnRequest(): void {
		$store = RefusalGroundStore::seeded();
		foreach ($store->rows as $index => $row) {
			if ($row['code'] === '5.1.6') {
				$store->rows[$index]['status'] = 'retired';
			}
		}

		$grounds = $this->grounds(store: $store);

		$this->assertNotContains('5.1.6', array_column($grounds->list(), 'code'));
		$this->assertContains('5.1.6', array_column($grounds->list(includeRetired: true), 'code'));
		$this->assertSame('retired', $grounds->byCode(code: '5.1.6')['status']);
		$this->assertNull($grounds->byCode(code: '5.2.5'));
	}//end testRetiredGroundsOnlyOnRequest()

	/**
	 * A failed read throws; so does an empty register. Neither answers [].
	 *
	 * @return void
	 */
	public function testAFailedReadThrowsAndNeverAnswersEmpty(): void {
		$store = RefusalGroundStore::seeded();
		$store->fails = true;

		try {
			$this->grounds(store: $store)->list();
			$this->fail('a failed read answered a list');
		} catch (WooRefusalGroundsUnavailable $e) {
			$this->assertStringContainsString('cannot be read', $e->getMessage());
		}

		$this->expectException(WooRefusalGroundsUnavailable::class);
		$this->grounds(store: new RefusalGroundStore())->list();
	}//end testAFailedReadThrowsAndNeverAnswersEmpty()

	/**
	 * A narrower ground names its broader one, up to the top of the tree.
	 *
	 * @return void
	 */
	public function testANarrowerGroundSitsUnderABroaderOne(): void {
		$grounds = $this->grounds(store: RefusalGroundStore::seeded());

		$this->assertSame('5.1.2', $grounds->byCode(code: '5.1.2.e')['parent']);
		$this->assertSame('5.1', $grounds->byCode(code: '5.1.2')['parent']);
		$this->assertNull($grounds->byCode(code: '5.1')['parent']);
	}//end testANarrowerGroundSitsUnderABroaderOne()
}//end class
