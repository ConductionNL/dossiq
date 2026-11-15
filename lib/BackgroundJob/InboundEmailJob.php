<?php

/**
 * Dossiq Inbound Email Job
 *
 * The scheduled sweep of the intake mailbox. It reads the Nextcloud Mail
 * account an administrator picked and walks every waiting message through the
 * intake pipeline.
 *
 * 🔴 THIS JOB NO LONGER OPENS IMAP AND NO LONGER READS A PASSWORD. It used to
 * call `imap_open()` with a host, a username and a password out of appconfig,
 * which made dossiq a second place a mailbox credential lived. Decision D12 put
 * the account with Nextcloud Mail, which already owns the credential and the
 * OAuth 2.0 flow, so this job asks the gateway instead and dossiq ships no IMAP
 * client, no OAuth flow and no credential store.
 *
 * 🔴 AND IT IS A SWEEP, NOT THE PRIMARY PATH.
 * {@see \OCA\Dossiq\Listener\NewMessagesSynchronizedListener} runs intake when
 * Mail reports new messages, which is immediate. This job exists because a push
 * that was missed leaves a message sitting in a folder forever, and a statutory
 * term does not pause for a dropped event. Both funnel into the same
 * {@see \OCA\Dossiq\Service\Email\InboundMailIntake}, and the intake log's
 * per-message record is what keeps a message processed twice from being filed
 * twice.
 *
 * WHEN NEXTCLOUD MAIL IS NOT INSTALLED this job logs that intake is unavailable
 * and returns. It does not throw, because a throw on a cron run is an outage
 * nobody is watching.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Sweeps the intake mailbox through the filter pipeline.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class InboundEmailJob extends TimedJob {

	/**
	 * Default poll interval when the appconfig key is unset.
	 */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/**
	 * Default per-run batch size.
	 */
	private const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           Time factory.
	 * @param IAppConfig               $appConfig      App config.
	 * @param IAppManager              $appManager     App manager.
	 * @param MailIntakeSource $mail The mail gateway, the intake account and the inbound mail intake.
	 * @param LoggerInterface          $logger         Logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the run writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly MailIntakeSource $mail,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$interval = (int)$this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'email_poll_interval',
			(string)self::DEFAULT_INTERVAL_SECONDS,
		);
		if ($interval < 60) {
			$interval = self::DEFAULT_INTERVAL_SECONDS;
		}

		$this->setInterval(seconds: $interval);
	}//end __construct()

	/**
	 * Run once as the background service account.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job list hands every job an argument; this one takes none.
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->work());
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// read, sent or written; the next run tries again.
			return;
		}
	}//end run()

	/**
	 * Run a single sweep.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function work(): void {
		try {
			$this->sweep();
		} catch (\Throwable $e) {
			$this->logger->error(
				'InboundEmailJob failed',
				['error' => $e->getMessage(), 'app' => AppIdentity::APP_ID]
			);
		}//end try
	}//end work()

	/**
	 * Read one batch from the intake mailbox and hand it to the intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function sweep(): void {
		if ($this->intakeReady() === false) {
			return;
		}

		$messages = $this->mail->gateway->messages(
			$this->mail->account->accountId(),
			$this->mail->account->folder(),
			$this->batchSize()
		);
		if ($messages === []) {
			return;
		}

		$this->processMessages(messages: $messages);
	}//end sweep()

	/**
	 * Whether OpenRegister and the Mail app are there and an intake account is configured.
	 *
	 * @return bool
	 */
	private function intakeReady(): bool {
		if ($this->appManager->isInstalled('openregister') === false) {
			return false;
		}

		if ($this->mail->gateway->isAvailable() === false) {
			$this->logger->info(
				'Dossiq: mail intake is unavailable because the Mail app is not installed',
				['app' => AppIdentity::APP_ID]
			);
			return false;
		}

		return $this->mail->account->isConfigured();
	}//end intakeReady()

	/**
	 * Hand the batch to the intake and log what it did with it.
	 *
	 * @param array<int, array<string, mixed>> $messages The batch.
	 *
	 * @return void
	 */
	private function processMessages(array $messages): void {
		$counts = $this->mail->intake->processBatch(
			rows: $messages,
			accountId: $this->mail->account->accountId(),
			mailbox: $this->mail->account->folder()
		);

		$this->logger->info(
			'Dossiq: the intake sweep processed {count} messages',
			['count' => count($messages), 'outcomes' => $counts, 'app' => AppIdentity::APP_ID]
		);
	}//end processMessages()

	/**
	 * How many messages one sweep takes.
	 *
	 * @return integer The batch size.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function batchSize(): int {
		$size = (int)$this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'email_poll_batch_size',
			(string)self::DEFAULT_BATCH_SIZE,
		);
		if ($size <= 0) {
			return self::DEFAULT_BATCH_SIZE;
		}

		return $size;
	}//end batchSize()
}//end class
