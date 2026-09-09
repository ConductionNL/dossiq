<?php

/**
 * Dossiq Berichtenbox Read Status Job.
 *
 * Daily timed background job that polls Mijn Overheid Berichtenbox for the
 * read status of previously sent citizen messages.
 *
 * 🔴 THIS JOB IS DELIBERATELY NOT REGISTERED, AND THAT IS WHY THIS PARAGRAPH
 * EXISTS. It carries no `<job>` entry in `appinfo/info.xml`, so Nextcloud
 * never schedules it and it has never run on any instance. Left undocumented
 * that is its own small lie: an unregistered job and a job that runs daily and
 * finds nothing produce exactly the same evidence, which is an empty log and a
 * read status that never changes. Anyone auditing the Berichtenbox channel
 * would have had to read `info.xml` to tell the two apart.
 *
 * It stays unregistered because there is nothing on the other end. Integriq
 * ships only `BerichtenboxClientMock`; the live `BerichtenboxClientHttp` its
 * own docblock names does not exist, and it waits on Logius BBK 1.7 OAuth
 * credentials and a PKIoverheid Services-server certificate, which is a
 * procurement item rather than a coding one. Scheduling this job today would
 * poll a mock every 24 hours and write back a read status the mock invents an
 * hour after send. A cron that appears to confirm citizens are reading their
 * post, while no post has left the instance, is worse than no cron.
 *
 * Register it in the same change that binds a real Berichtenbox transport, not
 * before. See `openspec/changes/dossiq-delivers-nothing/proposal.md`, delivery
 * inventory item 5, where this is staged as phase 4.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\BerichtenboxService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that polls Berichtenbox read status for sent messages.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxReadStatusJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param BerichtenboxService $berichtenboxService The Berichtenbox service.
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private BerichtenboxService $berichtenboxService,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		// Daily.
	}//end __construct()

	/**
	 * Run the scheduled read-status poll.
	 *
	 * Retrieves all messages with status 'sent' or 'unread_flagged' that have
	 * been delivered to Berichtenbox and polls the external API for each to
	 * update the local status to 'read', 'unread_flagged', or leave as-is.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$messages = $this->berichtenboxService->getPendingMessages();
		$count = count($messages);

		$this->logger->info(
			'Dossiq: Starting Berichtenbox read-status poll',
			['app' => Application::APP_ID, 'pendingMessages' => $count],
		);

		foreach ($messages as $message) {
			$messageId = (string)($message['uuid'] ?? ($message['id'] ?? ''));
			if ($messageId === '') {
				continue;
			}

			$this->berichtenboxService->pollReadStatus($messageId);
		}//end foreach

		$this->logger->info(
			'Dossiq: Berichtenbox read-status poll completed',
			['app' => Application::APP_ID, 'polled' => $count],
		);
	}//end run()
}//end class
