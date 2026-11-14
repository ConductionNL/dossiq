<?php

/**
 * Which slug renames go ahead and which collide with a slug already taken.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SlugRenamePlan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\SlugRenamePlan
 */
class SlugRenamePlanTest extends TestCase {

	/**
	 * A slug present on the install is renamed; an absent one is not mentioned.
	 *
	 * @return void
	 */
	public function testOnlySlugsPresentOnTheInstallAreRenamed(): void {
		$plan = (new SlugRenamePlan())->plan(
			map: ['bezwaar' => 'objectionProceeding', 'kanaal' => 'notificationChannel'],
			existing: ['bezwaar', 'case']
		);

		self::assertSame(['bezwaar' => 'objectionProceeding'], $plan['renames']);
		self::assertSame([], $plan['refused']);
	}//end testOnlySlugsPresentOnTheInstallAreRenamed()

	/**
	 * A target slug that already exists refuses the rename and says why.
	 *
	 * @return void
	 */
	public function testATakenTargetSlugIsRefused(): void {
		$plan = (new SlugRenamePlan())->plan(
			map: ['catalogus' => 'zgwCatalogus'],
			existing: ['catalogus', 'zgwCatalogus']
		);

		self::assertSame([], $plan['renames']);
		self::assertSame(['catalogus' => "target slug 'zgwCatalogus' already exists"], $plan['refused']);
	}//end testATakenTargetSlugIsRefused()

	/**
	 * Two renames onto the same target: the first claims it, the second is refused.
	 *
	 * @return void
	 */
	public function testAnEarlierRenameClaimsItsTargetForTheRest(): void {
		$plan = (new SlugRenamePlan())->plan(
			map: ['a' => 'shared', 'b' => 'shared'],
			existing: ['a', 'b']
		);

		self::assertSame(['a' => 'shared'], $plan['renames']);
		self::assertSame(['b' => "target slug 'shared' already exists"], $plan['refused']);
	}//end testAnEarlierRenameClaimsItsTargetForTheRest()

	/**
	 * Slugs are read from rows, a row without one giving an empty string.
	 *
	 * @return void
	 */
	public function testSlugsFromReadsEachRowsSlug(): void {
		self::assertSame(
			['case', ''],
			(new SlugRenamePlan())->slugsFrom(rows: [['slug' => 'case', 'id' => 3], ['id' => 4]])
		);
	}//end testSlugsFromReadsEachRowsSlug()

	/**
	 * One question mark per value, none for zero or a negative count.
	 *
	 * @return void
	 */
	public function testPlaceholdersMatchTheCount(): void {
		$plan = new SlugRenamePlan();

		self::assertSame('?,?,?', $plan->placeholders(count: 3));
		self::assertSame('?', $plan->placeholders(count: 1));
		self::assertSame('', $plan->placeholders(count: 0));
		self::assertSame('', $plan->placeholders(count: -2));
	}//end testPlaceholdersMatchTheCount()
}//end class
