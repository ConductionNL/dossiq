<?php

/**
 * MixedCaseTypeVersionsException tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bulk
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

namespace OCA\Dossiq\Tests\Unit\Service\Bulk;

use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException
 */
class MixedCaseTypeVersionsExceptionTest extends TestCase {

	/**
	 * The refusal carries the stable message code and what the caller needs to explain it.
	 *
	 * @return void
	 */
	public function testItCarriesTheTitleVersionsAndCounts(): void {
		$e = new MixedCaseTypeVersionsException(caseTypeTitle: 'Bezwaar', versions: [2, 3], counts: [3 => 4, 2 => 1]);

		self::assertInstanceOf(RuntimeException::class, $e);
		self::assertSame(expected: 'mixed_case_type_versions', actual: $e->getMessage());
		self::assertSame(expected: 'Bezwaar', actual: $e->getCaseTypeTitle());
		self::assertSame(expected: [2, 3], actual: $e->getVersions());
		self::assertSame(expected: [3 => 4, 2 => 1], actual: $e->getCounts());
	}//end testItCarriesTheTitleVersionsAndCounts()
}//end class
