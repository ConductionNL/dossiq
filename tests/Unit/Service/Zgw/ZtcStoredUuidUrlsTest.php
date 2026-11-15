<?php

/**
 * Ztc Stored Uuid Urls Test
 *
 * A stored uuid list, as an array or a JSON string, becomes the URLs the
 * Catalogi API answers; an empty or unreadable list is none.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcStoredUuidUrls;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ZtcStoredUuidUrls
 */
class ZtcStoredUuidUrlsTest extends TestCase {

	private const PREFIX = 'https://nc/apps/dossiq/api/zgw/catalogi/v1/zaaktypen/';

	/**
	 * An array and a JSON string give the URL of each non-empty string uuid, in order.
	 *
	 * @return void
	 */
	public function testAStoredListBecomesUrls(): void {
		$urls = new ZtcStoredUuidUrls();

		$this->assertSame(
			expected: [self::PREFIX.'a', self::PREFIX.'b'],
			actual: $urls->urls(stored: ['a', '', null, 7, 'b'], prefix: self::PREFIX)
		);
		$this->assertSame(expected: [self::PREFIX.'c'], actual: $urls->urls(stored: '["c", ""]', prefix: self::PREFIX));

	}//end testAStoredListBecomesUrls()

	/**
	 * An empty list, an empty string, a JSON null, a number and a list of only blanks.
	 *
	 * @return void
	 */
	public function testAnEmptyOrUnreadableListIsNone(): void {
		$urls = new ZtcStoredUuidUrls();

		$this->assertNull(actual: $urls->urls(stored: [], prefix: self::PREFIX));
		$this->assertNull(actual: $urls->urls(stored: '', prefix: self::PREFIX));
		$this->assertNull(actual: $urls->urls(stored: 'null', prefix: self::PREFIX));
		$this->assertNull(actual: $urls->urls(stored: 5, prefix: self::PREFIX));
		$this->assertSame(expected: [], actual: $urls->urls(stored: ['', null], prefix: self::PREFIX));

	}//end testAnEmptyOrUnreadableListIsNone()
}//end class
