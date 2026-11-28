<?php

/**
 * CaseTeamFillRun unit tests
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseTeamFillRun;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Narrowing a case team fill pass to one case type leaves the pass itself untouched.
 *
 * @covers \OCA\Dossiq\Repair\CaseTeamFillRun
 */
final class CaseTeamFillRunTest extends TestCase {

	/**
	 * The narrowed run carries the case type and group and shares the services; the pass keeps none.
	 *
	 * @return void
	 */
	public function testForCaseTypeNarrowsACopyAndLeavesThePassAlone(): void {
		$objectService = new stdClass();
		$pass = new CaseTeamFillRun(objectService: $objectService, register: 'dossiq');
		$pass->caseTypeSchema = 'case-type';
		$pass->caseSchema = 'case';

		$run = $pass->forCaseType(caseTypeId: 'ct-1', group: 'vergunningen');

		self::assertNotSame($pass, $run);
		self::assertSame('ct-1', $run->caseTypeId);
		self::assertSame('vergunningen', $run->group);
		self::assertSame('case-type', $run->caseTypeSchema);
		self::assertSame('case', $run->caseSchema);
		self::assertSame('dossiq', $run->register);
		self::assertSame($objectService, $run->objectService);
		self::assertSame('', $pass->caseTypeId);
		self::assertSame('', $pass->group);
	}//end testForCaseTypeNarrowsACopyAndLeavesThePassAlone()

	/**
	 * Two case types narrowed from one pass do not share their narrowing.
	 *
	 * @return void
	 */
	public function testTwoNarrowingsAreIndependent(): void {
		$pass = new CaseTeamFillRun(objectService: new stdClass(), register: 'dossiq');

		$first = $pass->forCaseType(caseTypeId: 'ct-1', group: 'a');
		$second = $pass->forCaseType(caseTypeId: 'ct-2', group: 'b');

		self::assertSame(['ct-1', 'a'], [$first->caseTypeId, $first->group]);
		self::assertSame(['ct-2', 'b'], [$second->caseTypeId, $second->group]);
	}//end testTwoNarrowingsAreIndependent()
}//end class
