<?php

/**
 * The intake mailbox sweep, recorded call by call.
 *
 * Every early return of the sweep (no OpenRegister, no Mail app, no intake
 * account, an empty mailbox), the batch it hands to the intake and a sweep
 * that fails are recorded and compared with a golden file.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\InboundEmailJob;
use OCA\Dossiq\BackgroundJob\MailIntakeSource;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeAccount;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The sweep matches its recording in every world.
 *
 * @covers \OCA\Dossiq\BackgroundJob\InboundEmailJob
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class InboundEmailJobSweepGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every world matches the recording.
	 *
	 * @return void
	 */
	public function testEverySweepMatchesTheRecording(): void {
		$worlds = [
			'no openregister' => ['openregister' => false],
			'no mail app' => ['mail' => false],
			'no intake account' => ['account' => false],
			'an empty mailbox' => ['messages' => []],
			'a batch' => [],
			'a batch size of zero falls back' => ['batchSize' => '0'],
			'a failing intake' => ['intakeFails' => true],
		];

		$recorded = [];
		foreach ($worlds as $name => $world) {
			$this->log = [];
			$job = $this->job(world: $world + ['openregister' => true, 'mail' => true, 'account' => true, 'messages' => [['id' => 11], ['id' => 12]], 'batchSize' => '25', 'intakeFails' => false]);
			(new ReflectionMethod($job, 'work'))->invoke($job);
			$recorded[$name] = $this->log;
		}

		$this->assertMatchesGolden(name: 'inbound-email-job/sweeps', value: $recorded);
	}//end testEverySweepMatchesTheRecording()

	/**
	 * The job over recording collaborators.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return InboundEmailJob
	 */
	private function job(array $world): InboundEmailJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'email_poll_batch_size') ? $world['batchSize'] : $default
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			function (string $app) use ($world): bool {
				$this->log[] = ['isInstalled' => $app];
				return $world['openregister'];
			}
		);

		$gateway = $this->createMock(MailGatewayInterface::class);
		$gateway->method('isAvailable')->willReturnCallback(
			function () use ($world): bool {
				$this->log[] = ['isAvailable' => $world['mail']];
				return $world['mail'];
			}
		);
		$gateway->method('messages')->willReturnCallback(
			function (int $accountId, string $folder, int $limit) use ($world): array {
				$this->log[] = ['messages' => [$accountId, $folder, $limit]];
				return $world['messages'];
			}
		);
		$account = $this->createMock(IntakeAccount::class);
		$account->method('isConfigured')->willReturnCallback(
			function () use ($world): bool {
				$this->log[] = ['isConfigured' => $world['account']];
				return $world['account'];
			}
		);
		$account->method('accountId')->willReturn(7);
		$account->method('folder')->willReturn('INBOX');
		$intake = $this->createMock(InboundMailIntake::class);
		$intake->method('processBatch')->willReturnCallback(
			function (array $rows, int $accountId, string $mailbox) use ($world): array {
				$this->log[] = ['processBatch' => [count($rows), $accountId, $mailbox]];
				if ($world['intakeFails'] === true) {
					throw new RuntimeException('register down');
				}

				return ['matched' => 1, 'unmatched' => 1];
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

		return new InboundEmailJob(
			$this->createMock(ITimeFactory::class),
			$config,
			$apps,
			new MailIntakeSource(gateway: $gateway, account: $account, intake: $intake),
			$logger,
			$this->createMock(BackgroundServiceAccount::class)
		);
	}//end job()
}//end class
