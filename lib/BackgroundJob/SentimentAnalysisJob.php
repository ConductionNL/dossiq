<?php

/**
 * Dossiq KCC Sentiment Analysis Job.
 *
 * Periodically scores unanalysed contactmomenten (those with a transcription
 * but no sentiment record yet), persists a klantSentiment object, and appends a
 * sentiment activity to each related case when escalation is recommended. The
 * job is resilient: a failure on one record is logged and the job continues.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SentimentService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Timed job that scores contactmoment transcriptions for sentiment.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15
 */
class SentimentAnalysisJob extends TimedJob {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param SettingsService $settingsService The settings service.
	 * @param SentimentService $sentimentService The sentiment service.
	 * @param ContactMomentService $contactMomentService The contactmoment service.
	 * @param IAppManager $appManager The app manager.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the pass writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly SentimentService $sentimentService,
		private readonly ContactMomentService $contactMomentService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		// Every 10 minutes.
		$this->setInterval(seconds: 600);
	}//end __construct()

	/**
	 * Run the sentiment analysis pass.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	protected function run($argument): void {
		// Without a usable account this runs nothing: that is already logged as an
		// error and told to the admins. Nothing was read, sent or
		// written; the next run tries again.
		$this->serviceAccount->runAsWhenAvailable(operation: fn () => $this->work());
	}//end run()

	/**
	 * The sentiment analysis pass itself, run as the service account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function work(): void {
		$run = $this->openRun();
		if ($run === null) {
			return;
		}

		$contacts = $this->loadContacts(run: $run);
		if ($contacts === null) {
			return;
		}

		foreach ((array)$contacts as $contact) {
			$this->scoreContact(run: $run, contact: $contact);
		}
	}//end work()

	/**
	 * The run, or null without OpenRegister, its object service, a register or either schema.
	 *
	 * @return SentimentRun|null
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function openRun(): ?SentimentRun {
		$objectService = $this->objects()->objectService();
		if ($objectService === null) {
			return null;
		}

		$run = new SentimentRun();
		$run->objectService = $objectService;
		$run->register = $this->settingsService->getConfigValue('register');
		$run->contactmomentSchema = $this->settingsService->getConfigValue('contactmoment_schema');
		$run->sentimentSchema = $this->settingsService->getConfigValue('klant_sentiment_schema');
		if ($run->register === '' || $run->contactmomentSchema === '' || $run->sentimentSchema === '') {
			return null;
		}

		$run->triggerWords = $this->triggerWords();

		return $run;
	}//end openRun()

	/**
	 * OpenRegister as this job sees it.
	 *
	 * @return OpenRegisterObjects
	 */
	private function objects(): OpenRegisterObjects {
		return new OpenRegisterObjects(appManager: $this->appManager, settingsService: $this->settingsService);
	}//end objects()

	/**
	 * The contactmomenten to score; a failed read is logged and answers null.
	 *
	 * @param SentimentRun $run The run.
	 *
	 * @return array<int, mixed>|null
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function loadContacts(SentimentRun $run): ?array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->contactmomentSchema,
				filters: ['_limit' => 200],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: sentiment job could not load contactmomenten: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}
	}//end loadContacts()

	/**
	 * Score one contactmoment; a failure is logged and the run goes on.
	 *
	 * @param SentimentRun $run     The run.
	 * @param mixed        $contact One search result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function scoreContact(SentimentRun $run, $contact): void {
		try {
			$this->processContact(run: $run, contact: $this->objects()->toArray(result: $contact));
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: sentiment scoring failed for a contactmoment: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
		}
	}//end scoreContact()

	/**
	 * Score a single contactmoment and persist its sentiment.
	 *
	 * A contactmoment without a transcript or an id, or with a sentiment
	 * record already, is skipped.
	 *
	 * @param SentimentRun         $run     The run.
	 * @param array<string, mixed> $contact The contactmoment record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function processContact(SentimentRun $run, array $contact): void {
		$transcript = trim((string)($contact['transcript'] ?? ''));
		$contactId = $this->contactIdOf(contact: $contact);
		if ($transcript === '' || $contactId === '' || $this->isScored(run: $run, contactId: $contactId) === true) {
			return;
		}

		$analysis = $this->sentimentService->analyzeSentiment($transcript, $run->triggerWords);

		$this->saveObjectAsArray(
			objectService: $run->objectService,
			register: $run->register,
			schema: $run->sentimentSchema,
			object: $this->sentimentRecord(contactId: $contactId, analysis: $analysis),
		);

		if ($analysis['escalationRecommended'] === true) {
			$this->escalate(contact: $contact, analysis: $analysis);
		}
	}//end processContact()

	/**
	 * The id of a contactmoment, '' when it has none.
	 *
	 * @param array<string, mixed> $contact The contactmoment record.
	 *
	 * @return string
	 */
	private function contactIdOf(array $contact): string {
		return (string)($contact['id'] ?? ($contact['uuid'] ?? ''));
	}//end contactIdOf()

	/**
	 * Whether a sentiment record already exists for this contact.
	 *
	 * @param SentimentRun $run       The run.
	 * @param string       $contactId The contactmoment.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function isScored(SentimentRun $run, string $contactId): bool {
		$existing = $this->searchObjectsAsArrays(
			objectService: $run->objectService,
			register: $run->register,
			schema: $run->sentimentSchema,
			filters: ['interactionId' => $contactId, '_limit' => 1],
		);

		return empty((array)$existing) === false;
	}//end isScored()

	/**
	 * The klant sentiment record of one analysis.
	 *
	 * @param string               $contactId The contactmoment.
	 * @param array<string, mixed> $analysis  The sentiment analysis.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function sentimentRecord(string $contactId, array $analysis): array {
		return [
			'interactionId' => $contactId,
			'sentimentScore' => $analysis['score'],
			'sentimentLabel' => $analysis['label'],
			'triggerWoorden' => $analysis['triggers'],
			'transcriptSnippet' => $analysis['snippet'],
			'escalationRecommended' => $analysis['escalationRecommended'],
			'escalationLevel' => $analysis['escalationLevel'],
			'createdAt' => date('c'),
		];
	}//end sentimentRecord()

	/**
	 * Record the detected sentiment on every case the contactmoment relates to.
	 *
	 * @param array<string, mixed> $contact  The contactmoment record.
	 * @param array<string, mixed> $analysis The sentiment analysis.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
	 */
	private function escalate(array $contact, array $analysis): void {
		foreach ((array)($contact['relatedCases'] ?? []) as $caseId) {
			$this->contactMomentService->recordActivity(
				(string)$caseId,
				$this->contactIdOf(contact: $contact),
				'sentiment_detected',
				'systeem',
				'Sentiment ' . $analysis['label'] . ' (escalatie: ' . $analysis['escalationLevel'] . ')',
			);
		}
	}//end escalate()

	/**
	 * Resolve the configured trigger words.
	 *
	 * @return array<int, string> The trigger words.
	 */
	private function triggerWords(): array {
		$decoded = json_decode($this->settingsService->getKccConfigValue('sentiment_trigger_words'), true);
		if (is_array($decoded) === true) {
			return array_map('strval', $decoded);
		}

		return [];
	}//end triggerWords()
}//end class
