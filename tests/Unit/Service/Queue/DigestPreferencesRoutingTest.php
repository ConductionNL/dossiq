<?php

/**
 * Whose answer decides that a digest is sent.
 *
 * THE POINT OF THIS FILE IS THE PRECEDENCE, not the storage. dossiq still
 * writes a local value, so an instance without the routing keeps working. The
 * moment the platform routes, the platform's answer has to win every read, the
 * background job's included: a local mirror that could win a read is exactly
 * how a settings screen and a nightly job come to disagree about whether
 * somebody is being told, with neither of them wrong on its own terms.
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
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The digest switch: the platform decides, the local value is the fallback.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
 */
class DigestPreferencesRoutingTest extends TestCase {

	/**
	 * Build the settings under test.
	 *
	 * @param bool|null $routed  What the platform says, or null when nothing routes.
	 * @param bool      $local   What dossiq stored itself.
	 * @param integer   $hour    The stored hour.
	 *
	 * @return array{0: DigestPreferences, 1: NotificationRouting} The settings and the routing double.
	 */
	private function build(?bool $routed, bool $local = true, int $hour = 8): array {
		$routing = $this->createMock(NotificationRouting::class);
		$routing->method('digestEnabledFor')->willReturn($routed);
		$routing->method('digestDecidedBy')->willReturn(
			($routed === null ? null : ['source' => 'group-default', 'scope' => 'global'])
		);

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueBool')->willReturn($local);
		$userConfig->method('getValueInt')->willReturn($hour);
		$userConfig->method('getValueString')->willReturn('');

		return [
			new DigestPreferences(userConfig: $userConfig, routing: $routing, logger: new NullLogger()),
			$routing,
		];
	}//end build()

	/**
	 * The platform's answer wins over the local mirror.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheRoutedAnswerWinsOverTheLocalMirror(): void {
		[$settings] = $this->build(routed: false, local: true);

		$this->assertFalse(
			condition: $settings->forUser(userId: 'alice')['enabled'],
			message: 'A team default that switched the digest off must win over a stale local true.'
		);
	}//end testTheRoutedAnswerWinsOverTheLocalMirror()

	/**
	 * The routed answer wins in the other direction too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheRoutedAnswerWinsWhenItSwitchesOn(): void {
		[$settings] = $this->build(routed: true, local: false);

		$this->assertTrue(
			condition: $settings->forUser(userId: 'alice')['enabled'],
			message: 'A person who switched it back on must be sent one, whatever the local mirror holds.'
		);
	}//end testTheRoutedAnswerWinsWhenItSwitchesOn()

	/**
	 * Without routing the local value still answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheLocalValueAnswersWhenNothingRoutes(): void {
		[$settings] = $this->build(routed: null, local: false);

		$this->assertFalse(condition: $settings->forUser(userId: 'alice')['enabled']);
		$this->assertSame(
			expected: 'dossiq',
			actual: $settings->forUser(userId: 'alice')['source'],
			message: 'An instance that does not route must say so rather than name a layer it does not have.'
		);
	}//end testTheLocalValueAnswersWhenNothingRoutes()

	/**
	 * The deciding layer travels with the value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheDecidingLayerTravelsWithTheValue(): void {
		[$settings] = $this->build(routed: false);

		$this->assertSame(
			expected: 'group-default',
			actual: $settings->forUser(userId: 'alice')['source'],
			message: 'The screen has to be able to say why the switch reads the way it does.'
		);
	}//end testTheDecidingLayerTravelsWithTheValue()

	/**
	 * The background job is answered by the same precedence as the screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheJobHonoursTheTeamDefault(): void {
		[$settings] = $this->build(routed: false, local: true, hour: 8);

		$this->assertFalse(
			condition: $settings->isDue(userId: 'alice', hour: 8, today: '2026-09-16'),
			message: 'A team default the screen honours and the job ignores is the drift this change removes.'
		);
	}//end testTheJobHonoursTheTeamDefault()

	/**
	 * Saving routes the switch and keeps the hour local.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testSavingRoutesTheSwitchAndKeepsTheHour(): void {
		[$settings, $routing] = $this->build(routed: true, local: true, hour: 8);

		$routing->expects($this->once())
			->method('setDigestEnabled')
			->with('alice', true);

		$saved = $settings->save(userId: 'alice', enabled: true, hour: 19);

		$this->assertSame(
			expected: 19,
			actual: $saved['hour'],
			message: 'The hour is dossiq\'s own: the platform routes whether a notice is sent, not when.'
		);
	}//end testSavingRoutesTheSwitchAndKeepsTheHour()
}//end class
