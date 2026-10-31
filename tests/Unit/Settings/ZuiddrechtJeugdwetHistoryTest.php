<?php

/**
 * The history of the Jeugdwet example case as DqSociaalDomein's Historie card
 * draws it: two acts by Fatma Kaya, the consent she recorded and the medical
 * report she looked at, not rows by System.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example history
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Fatma's two history rows on the Jeugdwet case.
 */
class ZuiddrechtJeugdwetHistoryTest extends TestCase {

	/**
	 * Fatma records the consent on its own date, the day after the report came in, and opens the medical report three days later.
	 *
	 * @return void
	 */
	public function testFatmaRecordsTheConsentAndReadsTheReport(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$entries = array_column($set['auditEntries'] ?? [], 'data', 'key');

		$consent = $entries['jeugdwet-toestemming-vastgelegd'] ?? null;
		$this->assertNotNull($consent);
		$this->assertSame(['3d903a5f-7066-50c8-a952-7e1c775055bb', 'create', '{{cast:fatma.uid}}', '{{datetime:-14 10:40}}'], [$consent['object'], $consent['action'], $consent['user'], $consent['at']]);

		$report = $entries['jeugdwet-inzage-medisch-verslag'] ?? null;
		$this->assertNotNull($report);
		$this->assertSame(['e0b306d2-c523-5938-9b5c-3474d292dc01', 'file.downloaded', '{{cast:fatma.uid}}', '{{datetime:-11 11:05}}'], [$report['object'], $report['action'], $report['user'], $report['at']]);
		$this->assertSame('medisch verslag', $report['changed']['data']['fileName']);
	}//end testFatmaRecordsTheConsentAndReadsTheReport()
}//end class
