<?php

/**
 * The page hands the frontend whether the support note may show.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/the-support-note-can-be-switched-off/specs/first-load-onboarding/spec.md#requirement-an-administrator-can-switch-the-support-note-off-req-flo-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\SupportNoteInitialStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SupportNoteInitialStateListener.
 *
 * @covers \OCA\Dossiq\Listener\SupportNoteInitialStateListener
 */
final class SupportNoteInitialStateListenerTest extends TestCase {

	/**
	 * Run the listener for one page with the switch at a value, and collect what it provided.
	 *
	 * @param string|null $value    The stored switch, null when it was never set.
	 * @param string      $app      The app whose page renders.
	 * @param bool        $loggedIn Whether the page is a logged-in one.
	 *
	 * @return array<string, mixed> The initial state provided.
	 */
	private function provided(?string $value, string $app='dossiq', bool $loggedIn=true): array {
		$provided     = [];
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $data) use (&$provided): void {
					$provided[$key] = $data;
				}
			);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($value ?? $default)
		);

		$response = $this->createMock(originalClassName: TemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn($loggedIn);
		$event->method('getResponse')->willReturn($response);

		(new SupportNoteInitialStateListener(initialState: $initialState, appConfig: $appConfig))->handle(event: $event);
		return $provided;
	}//end provided()

	/**
	 * Unset, the note shows as it always did.
	 *
	 * @return void
	 */
	public function testTheNoteShowsByDefault(): void {
		$this->assertSame(expected: ['support_note_shown' => true], actual: $this->provided(value: null));
		$this->assertSame(expected: ['support_note_shown' => true], actual: $this->provided(value: 'true'));
	}//end testTheNoteShowsByDefault()

	/**
	 * An administrator switches it off.
	 *
	 * @return void
	 */
	public function testAnAdministratorSwitchesItOff(): void {
		foreach (['false', 'FALSE', '0', 'no', 'off'] as $off) {
			$this->assertSame(expected: ['support_note_shown' => false], actual: $this->provided(value: $off), message: $off);
		}
	}//end testAnAdministratorSwitchesItOff()

	/**
	 * A switch stored as a typed boolean is read as one.
	 *
	 * @return void
	 */
	public function testATypedBooleanIsRead(): void {
		$provided     = [];
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $data) use (&$provided): void {
				$provided[$key] = $data;
			}
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new AppConfigTypeConflictException('typed'));
		$appConfig->method('getValueBool')->willReturn(false);
		$response = $this->createMock(originalClassName: TemplateResponse::class);
		$response->method('getApp')->willReturn('dossiq');
		$event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn(true);
		$event->method('getResponse')->willReturn($response);

		(new SupportNoteInitialStateListener(initialState: $initialState, appConfig: $appConfig))->handle(event: $event);

		$this->assertSame(expected: ['support_note_shown' => false], actual: $provided);
	}//end testATypedBooleanIsRead()

	/**
	 * Another app's page, a public page and another event get nothing.
	 *
	 * @return void
	 */
	public function testOtherPagesGetNothing(): void {
		$this->assertSame(expected: [], actual: $this->provided(value: 'false', app: 'files'));
		$this->assertSame(expected: [], actual: $this->provided(value: 'false', loggedIn: false));

		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');
		(new SupportNoteInitialStateListener(initialState: $initialState, appConfig: $this->createMock(originalClassName: IAppConfig::class)))
			->handle(event: new Event());
	}//end testOtherPagesGetNothing()
}//end class
