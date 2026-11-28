<?php

/**
 * The listener's answer runs through the service account, and an unavailable
 * account ends the answer quietly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Listener\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\AnswersAsServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\Support\AnswersAsServiceAccount
 */
class AnswersAsServiceAccountTest extends TestCase {

	/**
	 * A listener over the given service account.
	 *
	 * @param BackgroundServiceAccount $account The account.
	 *
	 * @return object
	 */
	private function listener(BackgroundServiceAccount $account): object {
		return new class ($account) {
			use AnswersAsServiceAccount;

			/**
			 * @var Event[]
			 */
			public array $answered = [];

			public function __construct(private readonly BackgroundServiceAccount $serviceAccount) {
			}

			public function handle(Event $event): void {
				$this->answerAsServiceAccount(answer: fn () => $this->answer(event: $event));
			}

			private function answer(Event $event): void {
				$this->answered[] = $event;
			}
		};
	}

	public function testTheAnswerRunsThroughTheAccount(): void {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->expects($this->once())->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(fn (callable $operation) => $operation());
		$listener = $this->listener(account: $account);
		$event = new Event();

		$listener->handle($event);

		$this->assertSame([$event], $listener->answered);
	}

	public function testAnUnavailableAccountEndsQuietly(): void {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willThrowException(new ServiceAccountUnavailableException('none'));
		$listener = $this->listener(account: $account);

		$listener->handle(new Event());

		$this->assertSame([], $listener->answered);
	}
}
