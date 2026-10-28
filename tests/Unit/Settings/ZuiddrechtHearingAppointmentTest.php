<?php

/**
 * The hearing the task "Hoorzitting inplannen" planned, on the task page's
 * Afspraken card: an hour in room 2.04, the objector invited.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example hearing
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The hearing appointment on the task.
 */
class ZuiddrechtHearingAppointmentTest extends TestCase {

	/**
	 * The appointment sits on the task, owned by Pieter, with the objector invited.
	 *
	 * @return void
	 */
	public function testTheHearingIsOnTheTask(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$rows = array_column($set['appointments'] ?? [], 'data', 'key');
		$hearing = $rows['hoorzitting-bezwaar-parkeerboete'] ?? null;

		$this->assertNotNull($hearing);
		$this->assertSame('{{task:hoorzitting-inplannen.uuid}}', $hearing['object']);
		$this->assertSame(['Hoorzitting bezwaar parkeerboete', '{{cast:pieter.uid}}', 'Stadskantoor, zaal 2.04'], [$hearing['summary'], $hearing['owner'], $hearing['location']]);
		$this->assertSame(['{{datetime:14 14:00}}', '{{datetime:14 15:00}}'], [$hearing['start'], $hearing['end']]);
		$this->assertSame([['name' => '{{cast:fatima.displayName}}', 'email' => '{{cast:fatima.email}}', 'status' => 'invited']], $hearing['attendees']);
	}//end testTheHearingIsOnTheTask()
}//end class
