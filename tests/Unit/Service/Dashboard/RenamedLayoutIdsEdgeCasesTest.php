<?php

/**
 * The edges of rewriting renamed ids in a stored layout delta.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\RenamedLayoutIds;
use PHPUnit\Framework\TestCase;

/**
 * Values that are not ids, or ids in places that are not id keys, pass through untouched.
 *
 * @covers \OCA\Dossiq\Service\Dashboard\RenamedLayoutIds
 */
final class RenamedLayoutIdsEdgeCasesTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var RenamedLayoutIds
	 */
	private RenamedLayoutIds $ids;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->ids = new RenamedLayoutIds();
	}//end setUp()

	/**
	 * Scalars that are not strings come back as they are.
	 *
	 * @return void
	 */
	public function testNonStringScalarsPassThrough(): void {
		self::assertSame(3, $this->ids->rewrite(value: 3, key: 'id'));
		self::assertNull($this->ids->rewrite(value: null, key: 'widgetId'));
		self::assertTrue($this->ids->rewrite(value: true));
	}//end testNonStringScalarsPassThrough()

	/**
	 * An old id is renamed under an id key, kept under any other key or with no key.
	 *
	 * @return void
	 */
	public function testAStringIsRenamedOnlyUnderAnIdKey(): void {
		self::assertSame('deadline-report-running', $this->ids->rewrite(value: 'td-running', key: 'route'));
		self::assertSame('td-running', $this->ids->rewrite(value: 'td-running', key: 'title'));
		self::assertSame('td-running', $this->ids->rewrite(value: 'td-running'));
		self::assertSame('unknown-id', $this->ids->rewrite(value: 'unknown-id', key: 'id'));
	}//end testAStringIsRenamedOnlyUnderAnIdKey()

	/**
	 * A visible.<id> field is renamed only for a renamed id, and only under the field key.
	 *
	 * @return void
	 */
	public function testAVisibleFieldIsRenamedOnlyForARenamedId(): void {
		self::assertSame('visible.goal-plan', $this->ids->rewrite(value: 'visible.family-plan', key: 'field'));
		self::assertSame('visible.own-tile', $this->ids->rewrite(value: 'visible.own-tile', key: 'field'));
		self::assertSame('visible.family-plan', $this->ids->rewrite(value: 'visible.family-plan', key: 'value'));
		self::assertSame('family-plan', $this->ids->rewrite(value: 'family-plan', key: 'field'));
	}//end testAVisibleFieldIsRenamedOnlyForARenamedId()

	/**
	 * An id list keeps its non-string entries and its keys, renaming only known string ids.
	 *
	 * @return void
	 */
	public function testAnIdListKeepsNonStringEntriesAndKeys(): void {
		self::assertSame(
			['a' => 'deadline-report-overdue', 'b' => 7, 'c' => null, 'd' => 'own-tile'],
			$this->ids->rewrite(value: ['a' => 'td-overdue', 'b' => 7, 'c' => null, 'd' => 'own-tile'], key: 'widgetIds')
		);
	}//end testAnIdListKeepsNonStringEntriesAndKeys()

	/**
	 * Under __order, each list is renamed and a value that is not a list passes through.
	 *
	 * @return void
	 */
	public function testOrderListsAreRenamedAndNonListsKept(): void {
		self::assertSame(
			['widgets' => ['deadline-report-penalty'], 'note' => 'td-penalty'],
			$this->ids->rewrite(value: ['widgets' => ['td-penalty'], 'note' => 'td-penalty'], key: '__order')
		);
	}//end testOrderListsAreRenamedAndNonListsKept()

	/**
	 * A map keyed by an old id gets the new key; integer keys stay and are rewritten without a key.
	 *
	 * @return void
	 */
	public function testMapKeysFollowTheRename(): void {
		self::assertSame(
			['deadline-report-notices' => ['gridX' => 1], 'other' => 2, 0 => 'td-notices'],
			$this->ids->rewrite(value: ['td-notices' => ['gridX' => 1], 'other' => 2, 0 => 'td-notices'])
		);
	}//end testMapKeysFollowTheRename()
}//end class
