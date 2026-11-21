<?php

/**
 * SupersededCaseTypes: a case type version leads to the version in use.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 *
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-chosen-case-type-follows-its-version-chain
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\SupersededCaseTypes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseType\SupersededCaseTypes
 */
class SupersededCaseTypesTest extends TestCase {
	/**
	 * A superseded version leads to the end of its chain; a current one to itself.
	 *
	 * @return void
	 */
	public function testFollowsTheChainToTheVersionInUse(): void {
		$chain = new SupersededCaseTypes();

		self::assertSame(expected: 'v3', actual: $chain->currentVersionOf(uuid: 'v1', supersededBy: ['v1' => 'v2', 'v2' => 'v3']));
		self::assertSame(expected: 'v3', actual: $chain->currentVersionOf(uuid: 'v3', supersededBy: ['v1' => 'v2']));
	}//end testFollowsTheChainToTheVersionInUse()

	/**
	 * A cycle in supersededBy ends.
	 *
	 * @return void
	 */
	public function testACycleEnds(): void {
		$uuid = (new SupersededCaseTypes())->currentVersionOf(uuid: 'x', supersededBy: ['x' => 'y', 'y' => 'x']);

		self::assertContains(needle: $uuid, haystack: ['x', 'y']);
	}//end testACycleEnds()
}//end class
