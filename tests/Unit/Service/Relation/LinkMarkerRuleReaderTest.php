<?php

/**
 * The stored list of link marker rules is read, and a list that is not JSON is refused.
 * cannot evaluate is refused when it is read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\LinkMarkerRuleReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\LinkMarkerRuleReader
 * @uses \OCA\Dossiq\Service\Relation\LinkMarkerRule
 */
class LinkMarkerRuleReaderTest extends TestCase {

	/**
	 * An empty setting holds no rules; a setting that is not JSON is refused.
	 *
	 * @return void
	 */
	public function testTheStoredList(): void {
		self::assertSame([], (new LinkMarkerRuleReader())->fromJson(json: ''));
		self::assertCount(1, (new LinkMarkerRuleReader())->fromJson(json: '[{"source":"s","field":"f","target":"t","marker":"m"}]'));

		$this->expectException(InvalidArgumentException::class);
		(new LinkMarkerRuleReader())->fromJson(json: '{not json');
	}//end testTheStoredList()
}//end class
