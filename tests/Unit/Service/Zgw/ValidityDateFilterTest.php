<?php

/**
 * Unit tests for ValidityDateFilter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ValidityDateFilter;
use PHPUnit\Framework\TestCase;

/**
 * A Catalogi list asked with datumGeldigheid keeps the types valid on that date.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ValidityDateFilter
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ValidityDateFilterTest extends TestCase {
	/**
	 * A type is valid from its start through its end, both inclusive; an empty or absent bound is open.
	 *
	 * @return void
	 */
	public function testATypeIsValidFromItsStartThroughItsEnd(): void {
		$types = [
			['id' => 'before', 'startValidity' => '2026-02-01'],
			['id' => 'starts', 'startValidity' => '2026-01-01', 'endValidity' => '2026-12-31'],
			['id' => 'ended', 'startValidity' => '2025-01-01', 'endValidity' => '2025-12-31'],
			['id' => 'ends', 'startValidity' => '2025-01-01', 'endValidity' => '2026-01-01'],
			['id' => 'open', 'startValidity' => '', 'endValidity' => null],
			['id' => 'bare'],
		];

		$kept = (new ValidityDateFilter())->keep(results: $types, date: '2026-01-01');

		$this->assertSame(['starts', 'ends', 'open', 'bare'], array_column($kept, 'id'));
		$this->assertSame([0, 1, 2, 3], array_keys($kept), 're-indexed');
	}//end testATypeIsValidFromItsStartThroughItsEnd()
}//end class
