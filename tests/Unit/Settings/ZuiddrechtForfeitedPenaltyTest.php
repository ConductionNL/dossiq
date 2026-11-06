<?php

/**
 * Example data for DqTermijnen: a permit decided too late, whose notice of
 * default forfeited a penalty of 1,442 euros that went to finance.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Kapvergunning Parkstraat's forfeited penalty.
 */
class ZuiddrechtForfeitedPenaltyTest extends TestCase {

	/**
	 * The chain from case to payout holds together and adds up.
	 *
	 * @return void
	 */
	public function testThePenaltyAddsUpAndReachedFinance(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$term   = $objects['termijn-kapvergunning-parkstraat']['data'];
		$notice = $objects['ingebrekestelling-kapvergunning-parkstraat']['data'];
		$calc   = $objects['dwangsom-kapvergunning-parkstraat']['data'];
		$payout = $objects['uitbetaling-kapvergunning-parkstraat']['data'];

		$this->assertSame(expected: '{{object:kapvergunning-parkstraat.id}}', actual: $term['case']);
		$this->assertSame(expected: 'exceeded', actual: $term['status']);
		$this->assertSame(expected: '{{object:termijn-kapvergunning-parkstraat.id}}', actual: $notice['deadlineInstance']);
		$this->assertSame(expected: 'valid', actual: $notice['validityStatus']);
		$this->assertSame(expected: '{{object:ingebrekestelling-kapvergunning-parkstraat.id}}', actual: $calc['noticeOfDefault']);
		$this->assertSame(expected: '{{object:dwangsom-kapvergunning-parkstraat.id}}', actual: $payout['penaltyPaymentCalculation']);

		// Awb 4:17: 14 days at 23, 14 at 35 and 14 at 45 euros.
		$this->assertSame(expected: 42, actual: $calc['currentDag']);
		$this->assertSame(expected: (14 * 2300) + (14 * 3500) + (14 * 4500), actual: $calc['definitiveAmount']);
		$this->assertSame(expected: [$calc['definitiveAmount'], 'gestopt-wegens-decision'], actual: [$payout['amount'], $calc['status']]);
		$this->assertSame(expected: $objects['kapvergunning-parkstraat']['data']['endDate'], actual: $calc['stoppedOn']);
	}//end testThePenaltyAddsUpAndReachedFinance()
}//end class
