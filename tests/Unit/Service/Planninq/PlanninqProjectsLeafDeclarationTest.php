<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Planninq;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The case declares planninq's projects leaf (dossiq#3190).
 *
 * Read through the real fragment merger, so the assertion is about the
 * register OpenRegister imports, not about one fragment file.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/specs/case-linked-projects/spec.md#requirement-the-case-page-shows-the-projects-planninq-links-to-the-case-req-clp-001
 */
class PlanninqProjectsLeafDeclarationTest extends TestCase {

	/**
	 * The merged case schema lists planninq-projects among its linked types, once.
	 *
	 * @return void
	 */
	public function testTheCaseDeclaresTheProjectsLeaf(): void {
		$root = dirname(__DIR__, 4);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		$linkedTypes = ($merged['components']['schemas']['case']['configuration']['linkedTypes'] ?? []);
		$this->assertContains('planninq-projects', $linkedTypes);
		$this->assertSame(1, count(array_keys($linkedTypes, 'planninq-projects', true)));
	}//end testTheCaseDeclaresTheProjectsLeaf()
}//end class
