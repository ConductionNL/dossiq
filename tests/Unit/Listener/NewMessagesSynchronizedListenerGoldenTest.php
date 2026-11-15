<?php

/**
 * Golden record of the Mail synchronisation intake.
 *
 * Records, per world, every call the listener makes and every log line, so a
 * refactor cannot change which synchronised batches reach the intake.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\NewMessagesSynchronizedListener;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for NewMessagesSynchronizedListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\NewMessagesSynchronizedListener
 */
class NewMessagesSynchronizedListenerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the listener distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testIntakeAcrossWorlds(): void {
		$batch = ['accountId' => 4, 'mailbox' => 'INBOX', 'messages' => [['id' => 1], ['id' => 2]]];
		$ready = ['configured' => true, 'unpacked' => $batch, 'matches' => true, 'counts' => ['linked' => 1, 'unmatched' => 1]];
		$worlds = [
			'no intake account configured' => ['configured' => false] + $ready,
			'not a synchronisation' => ['unpacked' => null] + $ready,
			'another account or mailbox' => ['matches' => false] + $ready,
			'intake fails' => ['counts' => 'throw'] + $ready,
			'nothing to report' => ['counts' => []] + $ready,
			'processed' => $ready,
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->events = [];
			$this->listenerFor(world: $world)->handle(new Event());
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'new-messages-synchronized', value: $record);
	}//end testIntakeAcrossWorlds()

	/**
	 * The listener of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return NewMessagesSynchronizedListener
	 */
	private function listenerFor(array $world): NewMessagesSynchronizedListener {
		$gateway = $this->createMock(MailGatewayInterface::class);
		$gateway->method('unpackSynchronisation')->willReturnCallback(
			function () use ($world): ?array {
				$this->events[] = 'unpack';
				return $world['unpacked'];
			}
		);

		$account = $this->createMock(IntakeAccount::class);
		$account->method('isConfigured')->willReturn($world['configured']);
		$account->method('matches')->willReturnCallback(
			function (int $accountId, string $mailbox) use ($world): bool {
				$this->events[] = 'matches ' . $accountId . ' ' . $mailbox;
				return $world['matches'];
			}
		);

		$intake = $this->createMock(InboundMailIntake::class);
		$intake->method('processBatch')->willReturnCallback(
			function (array $rows, int $accountId, string $mailbox) use ($world): array {
				$this->events[] = 'processBatch ' . count($rows) . ' rows ' . $accountId . ' ' . $mailbox;
				if ($world['counts'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return $world['counts'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new NewMessagesSynchronizedListener($gateway, $account, $intake, $logger);
	}//end listenerFor()
}//end class
