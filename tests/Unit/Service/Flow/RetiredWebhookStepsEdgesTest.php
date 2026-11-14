<?php

/**
 * Unit tests for the edge cases of the webhook translations.
 *
 * The translation, the template syntax and integriq's SourceRequestedEvent
 * are real. The dispatcher (integriq's listener) and the user session are
 * doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Integriq\Event\SourceRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredWebhookSteps
 * @uses \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @uses \OCA\Dossiq\Service\Flow\UnmappableStep
 */
class RetiredWebhookStepsEdgesTest extends TestCase {

	/**
	 * The timeouts integriq was asked to give new Sources.
	 *
	 * @var array<int, int>
	 */
	private array $timeouts = [];

	/**
	 * The translation, with integriq answering every Source request, or failing.
	 *
	 * @param bool $fail Throw from the listener instead of answering.
	 *
	 * @return RetiredWebhookSteps The translation.
	 */
	private function steps(bool $fail = false): RetiredWebhookSteps {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($fail): void {
				if ($fail === true) {
					throw new RuntimeException('integriq is down');
				}

				if ($event instanceof SourceRequestedEvent) {
					$this->timeouts[] = $event->getTimeoutSeconds();
					$event->setSource(sourceId: 'src-1', sourceSlug: 'url-x', created: false);
				}
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new RetiredWebhookSteps($events, $session, new RetiredTemplateSyntax());
	}//end steps()

	/**
	 * A callWebhook without a positive timeout gets the default one.
	 *
	 * @return void
	 */
	public function testANonPositiveTimeoutFallsBackToTheDefault(): void {
		$this->steps()->action(config: ['url' => 'https://hooks.example.org', 'timeoutSec' => 0]);
		$this->steps()->action(config: ['url' => 'https://hooks.example.org', 'timeoutSec' => -5]);

		self::assertSame([10, 10], $this->timeouts);
	}//end testANonPositiveTimeoutFallsBackToTheDefault()

	/**
	 * A listener that throws becomes a refusal carrying its message.
	 *
	 * @return void
	 */
	public function testAFailingListenerIsRefusedWithItsMessage(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('Integriq could not provide a source for https://hooks.example.org: integriq is down');

		$this->steps(fail: true)->transition(config: ['url' => 'https://hooks.example.org/x']);
	}//end testAFailingListenerIsRefusedWithItsMessage()

	/**
	 * Headers that are not a map, or entries with a numeric name or a non-scalar value, are dropped.
	 *
	 * @return void
	 */
	public function testUnusableHeadersAreDropped(): void {
		$plain = $this->steps()->transition(config: ['url' => 'https://hooks.example.org', 'headers' => 'X-Bron: dossiq']);
		self::assertArrayNotHasKey('headers', $plain['config']);

		$mixed = $this->steps()->transition(
			config: ['url' => 'https://hooks.example.org', 'headers' => ['dossiq', 'X-List' => ['a'], 'X-Bron' => 'dossiq', 'X-Nr' => 3]]
		);
		self::assertSame(['X-Bron' => 'dossiq', 'X-Nr' => '3'], $mixed['config']['headers']);
	}//end testUnusableHeadersAreDropped()

	/**
	 * A transition the old step carried is posted alongside the case.
	 *
	 * @return void
	 */
	public function testATransitionIsPostedWithTheCase(): void {
		$step = $this->steps()->transition(
			config: ['url' => 'https://hooks.example.org', RetiredWebhookSteps::TRANSITION_KEY => ['from' => 'nieuw', 'to' => 'in behandeling']]
		);

		self::assertSame(
			['case' => '{{ @item }}', 'transition' => ['from' => 'nieuw', 'to' => 'in behandeling']],
			$step['config']['body']
		);
	}//end testATransitionIsPostedWithTheCase()
}//end class
