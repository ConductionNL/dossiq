<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\ParaferingConcludedListener;
use OCA\Dossiq\Service\Parafeer\ParaferingConclusionService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the source-app filter and the projection hand-off.
 *
 * The filter is the load-bearing part: the decision app raises
 * ApprovalRouteConcludedEvent for every consuming app, and a listener that
 * recorded them all would write another app's parafering onto a dossiq case.
 */
class ParaferingConcludedListenerTest extends TestCase {

	/**
	 * A fake conclusion event.
	 *
	 * @param string $sourceApp The source app marker.
	 * @param string $subject The subject.
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return Event The event.
	 */
	private function event(string $sourceApp, string $subject, array $actions = []): Event {
		return new class ($sourceApp, $subject, $actions) extends Event {
			/**
			 * @param string $sourceApp The source app.
			 * @param string $subject The subject.
			 * @param array<int, array<string, mixed>> $actions The actions.
			 */
			public function __construct(private string $sourceApp, private string $subject, private array $actions) {
				parent::__construct();
			}

			/**
			 * @return string The source app.
			 */
			public function getSourceApp(): string {
				return $this->sourceApp;
			}

			/**
			 * @return string The subject.
			 */
			public function getSubject(): string {
				return $this->subject;
			}

			/**
			 * @return string The outcome.
			 */
			public function getOutcome(): string {
				return 'approved';
			}

			/**
			 * @return string The actor.
			 */
			public function getActor(): string {
				return 'carol';
			}

			/**
			 * @return array<int, array<string, mixed>> The actions.
			 */
			public function getActions(): array {
				return $this->actions;
			}
		};
	}

	/**
	 * An event this app raised is recorded.
	 *
	 * @return void
	 */
	public function testItRecordsAnEventForThisApp(): void {
		$seen = [];
		$conclusions = $this->createMock(ParaferingConclusionService::class);
		$conclusions->expects($this->once())->method('recordConclusion')
			->willReturnCallback(
				function (string $proposalId, string $outcome, string $actor, array $actions) use (&$seen): void {
					$seen = compact('proposalId', 'outcome', 'actor', 'actions');
				}
			);

		$listener = new ParaferingConcludedListener($conclusions, $this->createMock(LoggerInterface::class));
		$listener->handle($this->event(sourceApp: 'dossiq', subject: 'v-1', actions: [['step' => 1, 'actor' => 'x', 'action' => 'approved']]));

		$this->assertSame('v-1', $seen['proposalId']);
		$this->assertSame('approved', $seen['outcome']);
		$this->assertCount(1, $seen['actions']);
	}

	/**
	 * An event raised for another app is ignored.
	 *
	 * @return void
	 */
	public function testItIgnoresAnotherAppsEvent(): void {
		$conclusions = $this->createMock(ParaferingConclusionService::class);
		$conclusions->expects($this->never())->method('recordConclusion');

		$listener = new ParaferingConcludedListener($conclusions, $this->createMock(LoggerInterface::class));
		$listener->handle($this->event(sourceApp: 'someone-else', subject: 'v-1'));
	}

	/**
	 * An unrelated event is ignored without erroring.
	 *
	 * @return void
	 */
	public function testItIgnoresAnUnrelatedEvent(): void {
		$conclusions = $this->createMock(ParaferingConclusionService::class);
		$conclusions->expects($this->never())->method('recordConclusion');

		$other = new class extends Event {
		};

		(new ParaferingConcludedListener($conclusions, $this->createMock(LoggerInterface::class)))->handle($other);
		$this->addToAssertionCount(1);
	}

	/**
	 * A projection failure never propagates out of the listener.
	 *
	 * @return void
	 */
	public function testAProjectionFailureIsSwallowed(): void {
		$conclusions = $this->createMock(ParaferingConclusionService::class);
		$conclusions->method('recordConclusion')->willThrowException(new \RuntimeException('register down'));

		$listener = new ParaferingConcludedListener($conclusions, $this->createMock(LoggerInterface::class));
		$listener->handle($this->event(sourceApp: 'dossiq', subject: 'v-1'));

		$this->addToAssertionCount(1);
	}
}
