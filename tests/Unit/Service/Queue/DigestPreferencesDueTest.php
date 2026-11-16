<?php

/**
 * When a person's daily digest is due, and how sending it is recorded.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The digest is due once a day, at the person's own hour, while it is switched on.
 */
#[CoversClass(DigestPreferences::class)]
class DigestPreferencesDueTest extends TestCase {

	/**
	 * The stored settings.
	 *
	 * @var IUserConfig&MockObject
	 */
	private IUserConfig&MockObject $userConfig;

	/**
	 * Build the preferences with nothing routed by the platform.
	 *
	 * @param bool   $enabled  The stored switch.
	 * @param int    $hour     The stored hour.
	 * @param string $lastSent The stored last-sent day.
	 *
	 * @return DigestPreferences The preferences under test.
	 */
	private function build(bool $enabled, int $hour, string $lastSent): DigestPreferences {
		$routing = $this->createMock(originalClassName: NotificationRouting::class);
		$routing->method('digestEnabledFor')->willReturn(null);

		$this->userConfig = $this->createMock(originalClassName: IUserConfig::class);
		$this->userConfig->method('getValueBool')->willReturn($enabled);
		$this->userConfig->method('getValueInt')->willReturn($hour);
		$this->userConfig->method('getValueString')
			->with('anna', AppIdentity::APP_ID, DigestPreferences::PREF_LAST_SENT, '')
			->willReturn($lastSent);

		return new DigestPreferences(userConfig: $this->userConfig, routing: $routing, logger: new NullLogger());
	}//end build()

	/**
	 * At the person's hour, with nothing sent today, the digest is due.
	 *
	 * @return void
	 */
	public function testDueAtTheOwnHourWhenNotYetSentToday(): void {
		$preferences = $this->build(enabled: true, hour: 9, lastSent: '2026-11-15');

		$this->assertTrue($preferences->isDue(userId: 'anna', hour: 9, today: '2026-11-16'));
	}//end testDueAtTheOwnHourWhenNotYetSentToday()

	/**
	 * Once today's digest went out it is not due again the same day.
	 *
	 * @return void
	 */
	public function testNotDueTwiceOnTheSameDay(): void {
		$preferences = $this->build(enabled: true, hour: 9, lastSent: '2026-11-16');

		$this->assertFalse($preferences->isDue(userId: 'anna', hour: 9, today: '2026-11-16'));
	}//end testNotDueTwiceOnTheSameDay()

	/**
	 * Another hour than the person's own is not their moment.
	 *
	 * @return void
	 */
	public function testNotDueAtAnotherHour(): void {
		$preferences = $this->build(enabled: true, hour: 9, lastSent: '');

		$this->assertFalse($preferences->isDue(userId: 'anna', hour: 10, today: '2026-11-16'));
	}//end testNotDueAtAnotherHour()

	/**
	 * A switched-off digest is never due, not even at the person's hour.
	 *
	 * @return void
	 */
	public function testNeverDueWhenSwitchedOff(): void {
		$preferences = $this->build(enabled: false, hour: 9, lastSent: '');

		$this->assertFalse($preferences->isDue(userId: 'anna', hour: 9, today: '2026-11-16'));
	}//end testNeverDueWhenSwitchedOff()

	/**
	 * Marking the digest sent stores today as the person's last-sent day.
	 *
	 * @return void
	 */
	public function testMarkSentStoresToday(): void {
		$preferences = $this->build(enabled: true, hour: 9, lastSent: '');
		$this->userConfig->expects($this->once())
			->method('setValueString')
			->with('anna', AppIdentity::APP_ID, DigestPreferences::PREF_LAST_SENT, '2026-11-16');

		$preferences->markSent(userId: 'anna', today: '2026-11-16');
	}//end testMarkSentStoresToday()
}//end class
