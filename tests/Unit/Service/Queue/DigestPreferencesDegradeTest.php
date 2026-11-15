<?php

/**
 * DigestPreferences degraded-read unit tests.
 *
 * The digest settings have one deliberately-degrading seam: an unreadable
 * preference, or a routing layer that throws, reads as the named default
 * rather than silently flipping the digest off for a week. The hour is kept
 * inside the day, and the last-sent day reads empty when it cannot be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The seam degrades to the default; the hour is clamped; last-sent reads empty on failure.
 *
 * @covers \OCA\Dossiq\Service\Queue\DigestPreferences
 */
class DigestPreferencesDegradeTest extends TestCase {

	/** @var IUserConfig&MockObject */
	private IUserConfig $userConfig;

	/** @var NotificationRouting&MockObject */
	private NotificationRouting $routing;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->routing = $this->createMock(NotificationRouting::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The settings under test.
	 *
	 * @return DigestPreferences The preferences.
	 */
	private function prefs(): DigestPreferences {
		return new DigestPreferences(userConfig: $this->userConfig, routing: $this->routing, logger: $this->logger);
	}//end prefs()

	public function testAnUnreadablePreferenceReadsAsTheDefaultAndIsLogged(): void {
		$this->userConfig->method('getValueBool')->willThrowException(new \InvalidArgumentException('bad app id'));
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq: the digest settings could not be read: bad app id');

		$settings = $this->prefs()->forUser(userId: 'alice');

		$this->assertTrue($settings['enabled'], 'A config blip reads as on, not off.');
		$this->assertSame(DigestPreferences::DEFAULT_HOUR, $settings['hour']);
		$this->assertSame('dossiq', $settings['source']);
	}//end testAnUnreadablePreferenceReadsAsTheDefaultAndIsLogged()

	public function testARoutingFaultIsCaughtAndTheLocalValueStands(): void {
		$this->userConfig->method('getValueBool')->willReturn(false);
		$this->userConfig->method('getValueInt')->willReturn(9);
		$this->routing->method('digestEnabledFor')->willThrowException(new RuntimeException('register timeout'));
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq: the routed digest switch could not be read: register timeout');

		$settings = $this->prefs()->forUser(userId: 'alice');

		$this->assertFalse($settings['enabled'], 'The local value read before the fault stands.');
		$this->assertSame(9, $settings['hour']);
		$this->assertSame('dossiq', $settings['source']);
	}//end testARoutingFaultIsCaughtAndTheLocalValueStands()

	public function testAStoredHourOutsideTheDayFallsBackToTheDefault(): void {
		$this->userConfig->method('getValueBool')->willReturn(true);
		$this->userConfig->method('getValueInt')->willReturn(42);
		$this->routing->method('digestEnabledFor')->willReturn(null);

		$this->assertSame(DigestPreferences::DEFAULT_HOUR, $this->prefs()->forUser(userId: 'alice')['hour']);
	}//end testAStoredHourOutsideTheDayFallsBackToTheDefault()

	public function testSaveClampsAnHourOutsideTheDay(): void {
		$this->userConfig->method('getValueBool')->willReturn(true);
		$this->userConfig->method('getValueInt')->willReturn(DigestPreferences::DEFAULT_HOUR);
		$this->routing->method('digestEnabledFor')->willReturn(null);
		$this->userConfig->expects($this->once())->method('setValueInt')
			->with('alice', 'dossiq', DigestPreferences::PREF_HOUR, DigestPreferences::DEFAULT_HOUR);

		$settings = $this->prefs()->save(userId: 'alice', enabled: true, hour: -5);

		$this->assertSame(DigestPreferences::DEFAULT_HOUR, $settings['hour']);
	}//end testSaveClampsAnHourOutsideTheDay()

	public function testAnUnreadableLastSentDayReadsEmptyAndIsLogged(): void {
		$this->userConfig->method('getValueString')->willThrowException(new \InvalidArgumentException('no key'));
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq: the last digest day could not be read: no key');

		$this->assertSame('', $this->prefs()->lastSentOn(userId: 'alice'));
	}//end testAnUnreadableLastSentDayReadsEmptyAndIsLogged()
}//end class
