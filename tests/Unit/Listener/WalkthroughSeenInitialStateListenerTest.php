<?php

/**
 * The page hands the frontend whether the welcome tour is seen (first-load-onboarding).
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
 * @spec openspec/specs/first-load-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\WalkthroughSeenInitialStateListener;
use OCA\Dossiq\Service\Onboarding\WalkthroughCompletion;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WalkthroughSeenInitialStateListener.
 */
final class WalkthroughSeenInitialStateListenerTest extends TestCase {

	/**
	 * Run the listener for one page and collect what it provided.
	 *
	 * @param string $app      The app whose page renders.
	 * @param bool   $loggedIn Whether the page is a logged-in one.
	 *
	 * @return array<string, mixed> The initial state provided.
	 */
	private function provided(string $app, bool $loggedIn): array {
		$provided     = [];
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $data) use (&$provided): void {
					$provided[$key] = $data;
				}
			);
		$completion = $this->createMock(originalClassName: WalkthroughCompletion::class);
		$completion->method('seen')->willReturn(false);

		$response = $this->createMock(originalClassName: TemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn($loggedIn);
		$event->method('getResponse')->willReturn($response);

		(new WalkthroughSeenInitialStateListener(initialState: $initialState, completion: $completion))->handle(event: $event);
		return $provided;
	}//end provided()

	/**
	 * Dossiq's own page gets the answer.
	 *
	 * @return void
	 */
	public function testDossiqsPageGetsTheAnswer(): void {
		$this->assertSame(expected: ['walkthrough_seen' => false], actual: $this->provided(app: 'dossiq', loggedIn: true));
	}//end testDossiqsPageGetsTheAnswer()

	/**
	 * Another app's page and a public page get nothing.
	 *
	 * @return void
	 */
	public function testOtherPagesGetNothing(): void {
		$this->assertSame(expected: [], actual: $this->provided(app: 'files', loggedIn: true));
		$this->assertSame(expected: [], actual: $this->provided(app: 'dossiq', loggedIn: false));
	}//end testOtherPagesGetNothing()

	/**
	 * An unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$initialState = $this->createMock(originalClassName: IInitialState::class);
		$initialState->expects($this->never())->method('provideInitialState');
		$listener = new WalkthroughSeenInitialStateListener(
			initialState: $initialState,
			completion: $this->createMock(originalClassName: WalkthroughCompletion::class),
		);
		$listener->handle(event: new Event());
	}//end testAnotherEventIsIgnored()
}//end class
