<?php

/**
 * Unit tests for the Woo review report switches.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The switches are off unless an administrator said yes, and the throughput needs an existing reader group.
 *
 * @covers \OCA\Dossiq\Woo\WooReportSwitches
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
class WooReportSwitchesTest extends TestCase {

	/**
	 * The switches over a stored configuration and a set of existing groups with their members.
	 *
	 * @param array<string, string> $stored The stored app config.
	 * @param array<string, list<string>> $groups Existing groups and their members.
	 *
	 * @return WooReportSwitches The switches.
	 */
	private function switches(array $stored, array $groups = ['woo-leiding' => ['lead']]): WooReportSwitches {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => isset($groups[$gid]));
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($groups[$gid] ?? []), true)
		);

		return new WooReportSwitches(appConfig: $config, groupManager: $groupManager);
	}//end switches()

	/**
	 * Unset, empty and anything but a yes read as off.
	 *
	 * @return void
	 */
	public function testAnythingButAYesIsOff(): void {
		foreach (['', 'false', '0', 'nee', 'maybe'] as $value) {
			$switches = $this->switches(stored: [WooReportSwitches::PARTIES => $value]);
			$this->assertFalse($switches->isOn(switch: WooReportSwitches::PARTIES), 'value '.$value);
		}

		$this->assertTrue($this->switches(stored: [WooReportSwitches::PARTIES => 'true'])->isOn(switch: WooReportSwitches::PARTIES));
		$this->assertTrue($this->switches(stored: [WooReportSwitches::PARTIES => '1'])->isOn(switch: WooReportSwitches::PARTIES));
		$this->assertFalse($this->switches(stored: ['other' => 'true'])->isOn(switch: 'other'));
	}//end testAnythingButAYesIsOff()

	/**
	 * The throughput switch reads off when its reader group was deleted afterwards.
	 *
	 * @return void
	 */
	public function testThroughputIsOffWhenItsReaderGroupIsGone(): void {
		$on = [WooReportSwitches::THROUGHPUT => 'true', WooReportSwitches::READERS => 'woo-leiding'];
		$this->assertTrue($this->switches(stored: $on)->isOn(switch: WooReportSwitches::THROUGHPUT));
		$this->assertFalse($this->switches(stored: $on, groups: [])->isOn(switch: WooReportSwitches::THROUGHPUT));
		$this->assertFalse(
			$this->switches(stored: [WooReportSwitches::THROUGHPUT => 'true'])->isOn(switch: WooReportSwitches::THROUGHPUT)
		);
	}//end testThroughputIsOffWhenItsReaderGroupIsGone()

	/**
	 * Only a member of the existing reader group is a reader.
	 *
	 * @return void
	 */
	public function testOnlyAMemberOfTheReaderGroupReads(): void {
		$switches = $this->switches(stored: [WooReportSwitches::READERS => ' woo-leiding ']);
		$this->assertSame('woo-leiding', $switches->readerGroup());
		$this->assertTrue($switches->isThroughputReader(userId: 'lead'));
		$this->assertFalse($switches->isThroughputReader(userId: 'admin'));
		$this->assertFalse($switches->isThroughputReader(userId: ''));
		$this->assertFalse($this->switches(stored: [])->isThroughputReader(userId: 'lead'));
	}//end testOnlyAMemberOfTheReaderGroupReads()

	/**
	 * A save that leaves the throughput on without an existing group is refused; other saves pass.
	 *
	 * @return void
	 */
	public function testASaveWithoutAnExistingReaderGroupIsRefused(): void {
		$switches = $this->switches(stored: []);
		$switches->assertSaveAllowed(data: ['register' => '5']);
		$switches->assertSaveAllowed(data: [WooReportSwitches::THROUGHPUT => 'false']);
		$switches->assertSaveAllowed(data: [WooReportSwitches::THROUGHPUT => true, WooReportSwitches::READERS => 'woo-leiding']);

		$stillOn = $this->switches(stored: [WooReportSwitches::THROUGHPUT => 'true', WooReportSwitches::READERS => 'woo-leiding']);
		foreach ([[$switches, [WooReportSwitches::THROUGHPUT => 'true']], [$stillOn, [WooReportSwitches::READERS => '']]] as [$subject, $data]) {
			try {
				$subject->assertSaveAllowed(data: $data);
				$this->fail('Refusal expected for '.json_encode($data));
			} catch (RefusedException $e) {
				$this->assertSame(WooReportSwitches::RULE_NEEDS_READERS, $e->getRule());
				$this->assertSame(422, $e->getStatus());
			}
		}
	}//end testASaveWithoutAnExistingReaderGroupIsRefused()
}//end class
