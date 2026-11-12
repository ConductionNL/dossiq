<?php

/**
 * The timer-fired listeners' guards and failure handling, recorded.
 *
 * Every listener that acts on an OpenRegister flow timer checks that the timer
 * is this app's, from its own source, names what it is about and (for most)
 * fired on a breach rung, then runs as the background service account. Each
 * listener is driven through the same worlds and its calls and log lines are
 * compared with a golden file; the operation itself is not run here, the
 * listeners' own tests cover it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener;
use OCA\Dossiq\Listener\DsoDeadlineTimerFiredListener;
use OCA\Dossiq\Listener\MilestoneStallTimerFiredListener;
use OCA\Dossiq\Listener\TakeBackTimerFiredListener;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Each timer-fired listener matches its recording.
 *
 * @covers \OCA\Dossiq\Listener\AdviceTimerFiredListener
 * @covers \OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener
 * @covers \OCA\Dossiq\Listener\DsoDeadlineTimerFiredListener
 * @covers \OCA\Dossiq\Listener\MilestoneStallTimerFiredListener
 * @covers \OCA\Dossiq\Listener\TakeBackTimerFiredListener
 * @covers \OCA\Dossiq\Listener\TermijnTimerFiredListener
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class TimerFiredListenersGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Each listener, its timer source and the metadata a fire it acts on carries.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<string, string>}>
	 */
	public static function listeners(): array {
		return [
			'advice' => [AdviceTimerFiredListener::class, 'dossiq-advice', ['adviceId' => 'adv-1']],
			'bezwaar archive' => [BezwaarArchiveTimerFiredListener::class, 'dossiq-bezwaartermijn', ['triggerId' => 'trg-1']],
			'dso deadline' => [DsoDeadlineTimerFiredListener::class, 'dossiq-dso', ['caseId' => 'case-1']],
			'milestone stall' => [MilestoneStallTimerFiredListener::class, 'dossiq-milestone', ['caseId' => 'case-1', 'milestoneIdentifier' => 'besluit']],
			'take back' => [TakeBackTimerFiredListener::class, 'dossiq-take-back', ['caseId' => 'case-1', 'routedTo' => 'team-b', 'routedAt' => '2026-10-01']],
			'termijn' => [TermijnTimerFiredListener::class, 'dossiq-termijn', ['caseId' => 'case-1']],
		];
	}//end listeners()

	/**
	 * Every world of the listener matches the recording.
	 *
	 * @param class-string          $class    The listener.
	 * @param string                $source   Its timer source.
	 * @param array<string, string> $metadata What a fire it acts on carries.
	 *
	 * @dataProvider listeners
	 *
	 * @return void
	 */
	public function testTheListenerMatchesTheRecording(string $class, string $source, array $metadata): void {
		$fire = ['source' => $source] + $metadata;
		$worlds = ['plain event' => [null, 'dossiq', 'slaBreached:0', 'ran']];
		$worlds['acted on'] = [$fire, 'dossiq', 'slaBreached:0', 'ran'];
		$worlds['another app'] = [$fire, 'decidiq', 'slaBreached:0', 'ran'];
		$worlds['another source'] = [['source' => 'dossiq-other'] + $metadata, 'dossiq', 'slaBreached:0', 'ran'];
		$worlds['no source'] = [$metadata, 'dossiq', 'slaBreached:0', 'ran'];
		$worlds['a warning rung'] = [$fire, 'dossiq', 'warning:0', 'ran'];
		$worlds['no rung'] = [$fire, 'dossiq', null, 'ran'];
		$worlds['no account'] = [$fire, 'dossiq', 'slaBreached:0', 'unavailable'];
		$worlds['a failing operation'] = [$fire, 'dossiq', 'slaBreached:0', 'failed'];
		foreach (array_keys($metadata) as $key) {
			$worlds['without ' . $key] = [array_diff_key($fire, [$key => 1]), 'dossiq', 'slaBreached:0', 'ran'];
			$worlds['an empty ' . $key] = [array_replace($fire, [$key => '']), 'dossiq', 'slaBreached:0', 'ran'];
		}

		$recorded = [];
		foreach ($worlds as $world => [$meta, $appId, $rung, $account]) {
			$this->log = [];
			$listener = $this->listener(class: $class, account: $account);
			$listener->handle($this->event(metadata: $meta, appId: $appId, rung: $rung));
			$recorded[$world] = $this->log;
		}

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'timer-fired-listeners/' . $name, value: $recorded);
	}//end testTheListenerMatchesTheRecording()

	/**
	 * The fire, or a plain event when there is no metadata.
	 *
	 * @param array<string, string>|null $metadata The timer's metadata.
	 * @param string                     $appId    The timer's app.
	 * @param string|null                $rung     The rung that fired.
	 *
	 * @return Event
	 */
	private function event(?array $metadata, string $appId, ?string $rung): Event {
		if ($metadata === null) {
			return new Event();
		}

		$timer = new FlowTimer();
		$timer->setAppId($appId);
		$timer->setMetadata($metadata);

		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:' . (string)$rung, rungKey: $rung),
			notice: new EscalationNotice(recipients: [], priority: null, message: 'Reminder')
		);
	}//end event()

	/**
	 * The listener, with a recording account and logger and nothing else.
	 *
	 * @param class-string $class   The listener.
	 * @param string       $account `ran`, `unavailable` or `failed`.
	 *
	 * @return object
	 */
	private function listener(string $class, string $account): object {
		$serviceAccount = $this->createMock(BackgroundServiceAccount::class);
		$serviceAccount->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(
			function (callable $operation) use ($account): mixed {
				$this->log[] = ['runAsWhenNobodyIsSignedIn' => $account];
				if ($account === 'unavailable') {
					throw new ServiceAccountUnavailableException('no account');
				}

				if ($account === 'failed') {
					throw new RuntimeException('store down');
				}

				return null;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(function (BackgroundServiceAccount $serviceAccount, LoggerInterface $logger): void {
			$this->serviceAccount = $serviceAccount;
			$this->logger = $logger;
		})->call($listener, $serviceAccount, $logger);

		return $listener;
	}//end listener()
}//end class
