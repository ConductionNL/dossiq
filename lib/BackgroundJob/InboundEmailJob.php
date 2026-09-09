<?php

/**
 * Dossiq Inbound Email Job
 *
 * Polls the configured SHARED/functional IMAP mailbox, links unread messages to
 * cases via the subject-tag pattern `[ZAAK-YYYY-NNNNNN]`, and triggers archival
 * through {@see EmailArchivalService}. Per the ADR-002 exception
 * (case-email-integration), this is the ONLY dossiq-side mail dispatch: manual
 * per-user mail is owned by NC Mail.
 *
 * 🔴 THE TAG WAS NEVER RESOLVED, SO THE MATCHED PATH WROTE NONSENSE. The
 * pattern captures the WHOLE tag, prefix included (`ZAAK-2026-000142`), and the
 * job handed that string to `archiveLinkedEmail()` as if it were a case id. A
 * case id is a uuid, and the identifier the case actually carries is the bare
 * `YYYY-NNNN` OpenRegister materialises. So the prefixed capture matched no
 * case and could not have: every "linked" mail was archived against a case that
 * does not exist. The tag is now stripped to its identifier and resolved
 * through `CaseEmailRepository::findCaseIdByIdentifier()`, which was sitting
 * unused one directory away.
 *
 * 🔴 AND AN UNMATCHED MAIL IS NO LONGER DROPPED. It used to `continue` with no
 * log line at all. When an administrator names a fallback case type, it becomes
 * a case. When none is named, it stays in the mailbox exactly as before.
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
 * @spec openspec/changes/case-email-integration/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\EmailArchivalService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\SuppressesWarnings;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Pulls inbound email from the shared mailbox and auto-links to cases.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T08
 */
class InboundEmailJob extends TimedJob {

	use SuppressesWarnings;

	/**
	 * Subject-tag pattern carrying the case identifier.
	 */
	public const CASE_NUMBER_PATTERN = '/\[([A-Z]+-\d{4}-\d{4,6})\]/';

	/**
	 * Default poll interval when the appconfig key is unset.
	 */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/**
	 * Default per-run batch size.
	 */
	private const DEFAULT_BATCH_SIZE = 50;

	/**
	 * The case the last message was FILED as, if it was filed rather than matched.
	 *
	 * Held on the object rather than returned beside the id because the batch
	 * loop reports the two outcomes separately and an id alone cannot tell them
	 * apart: a case that already existed and a case created a moment ago are the
	 * same shape.
	 *
	 * @var string|null
	 */
	private ?string $lastFiledCaseId = null;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IAppConfig $appConfig App config.
	 * @param IAppManager $appManager App manager.
	 * @param SettingsService $settingsService Settings service.
	 * @param EmailArchivalService $archivalService Archival service.
	 * @param CaseEmailRepository $cases Resolves a case identifier to its id.
	 * @param UnmatchedMailIntake $intake What an unmatched mail becomes.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly EmailArchivalService $archivalService,
		private readonly CaseEmailRepository $cases,
		private readonly UnmatchedMailIntake $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$interval = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'email_poll_interval',
			(string)self::DEFAULT_INTERVAL_SECONDS,
		);
		if ($interval < 60) {
			$interval = self::DEFAULT_INTERVAL_SECONDS;
		}

		$this->setInterval(seconds: $interval);
	}//end __construct()

	/**
	 * Run a single poll batch.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T08
	 */
	protected function run($argument): void {
		try {
			if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
				return;
			}

			$host = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_host', '');
			if ($host === '') {
				return;
			}

			$messages = $this->fetchUnreadBatch();
			if ($messages === []) {
				return;
			}

			$linkedCount = 0;
			$filedCount = 0;
			$droppedCount = 0;
			foreach ($messages as $message) {
				if ($this->isAlreadyLinked(messageId: (string)($message['mailMessageId'] ?? '')) === true) {
					continue;
				}

				$caseId = $this->caseForMessage(message: $message);
				if ($caseId === null) {
					$droppedCount++;
					continue;
				}

				$this->archivalService->archiveLinkedEmail(caseId: $caseId, metadata: $message);
				$this->markProcessed(messageId: (string)($message['mailMessageId'] ?? ''));
				if ($caseId === $this->lastFiledCaseId) {
					$filedCount++;
					continue;
				}

				$linkedCount++;
			}//end foreach

			$this->reportBatch(linked: $linkedCount, filed: $filedCount, dropped: $droppedCount);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InboundEmailJob failed',
				['error' => $e->getMessage(), 'app' => Application::APP_ID]
			);
		}//end try
	}//end run()

	/**
	 * Match a `[ZAAK-2026-000142]` style tag in the subject.
	 *
	 * @param string $subject Subject header.
	 *
	 * @return string|null Matched identifier or null when no tag present.
	 *
	 * @spec openspec/changes/case-email-integration/tasks.md#T08
	 */
	public function matchCaseFromSubject(string $subject): ?string {
		if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end matchCaseFromSubject()

	/**
	 * The case identifier inside a subject tag, without its prefix.
	 *
	 * 🔴 THE TAG IS NOT THE IDENTIFIER. `[ZAAK-2026-000142]` carries a prefix
	 * the case does not: the identifier OpenRegister materialises is the bare
	 * `2026-000142`. Comparing the whole tag against `identifier` is why the
	 * matched path never matched anything.
	 *
	 * @param string $subject Subject header.
	 *
	 * @return string|null The bare identifier, or null when there is no tag.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function identifierFromSubject(string $subject): ?string {
		$tag = $this->matchCaseFromSubject(subject: $subject);
		if ($tag === null) {
			return null;
		}

		if (preg_match('/(\d{4}-\d{4,6})$/', $tag, $matches) === 1) {
			return $matches[1];
		}

		return $tag;
	}//end identifierFromSubject()

	/**
	 * The case a message belongs on, matched or newly filed.
	 *
	 * Three outcomes, and only one of them used to exist. The subject names a
	 * case that resolves: link it. The subject names none, or names one that no
	 * longer exists: file it as a case of the configured fallback type. No
	 * fallback type configured: leave it in the mailbox, which is exactly the
	 * behaviour of every instance that does not opt in.
	 *
	 * @param array<string, mixed> $message The normalised message row.
	 *
	 * @return string|null The case id, or null when nothing takes the mail.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	private function caseForMessage(array $message): ?string {
		$this->lastFiledCaseId = null;

		$identifier = $this->identifierFromSubject(subject: (string)($message['subject'] ?? ''));
		if ($identifier !== null) {
			$caseId = $this->cases->findCaseIdByIdentifier($identifier);
			if ($caseId !== null && $caseId !== '') {
				return $caseId;
			}

			$this->logger->info(
				'InboundEmailJob: the subject names case {identifier}, which does not resolve',
				['identifier' => $identifier, 'app' => Application::APP_ID]
			);
		}

		$filed = $this->intake->caseFor(message: $message);
		if ($filed === null) {
			return null;
		}

		$this->lastFiledCaseId = $filed;

		return $filed;
	}//end caseForMessage()

	/**
	 * Say what the batch did, including what it could not place.
	 *
	 * The dropped count is the point. A mail nobody can place used to leave no
	 * trace at all, so an instance losing every inbound message looked exactly
	 * like an instance receiving none.
	 *
	 * @param integer $linked  Messages linked to an existing case.
	 * @param integer $filed   Messages filed as a new case.
	 * @param integer $dropped Messages left in the mailbox.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	private function reportBatch(int $linked, int $filed, int $dropped): void {
		if ($linked > 0 || $filed > 0) {
			$this->logger->info(
				'InboundEmailJob: linked {linked} messages and filed {filed} as new cases',
				['linked' => $linked, 'filed' => $filed, 'app' => Application::APP_ID]
			);
		}

		if ($dropped === 0) {
			return;
		}

		if ($this->intake->isConfigured() === true) {
			$this->logger->warning(
				'InboundEmailJob: {dropped} messages could not be placed and no case was filed for them',
				['dropped' => $dropped, 'app' => Application::APP_ID]
			);
			return;
		}

		$this->logger->info(
			'InboundEmailJob: {dropped} messages matched no case and stay in the mailbox, '
				. 'because no fallback case type is configured',
			['dropped' => $dropped, 'app' => Application::APP_ID]
		);
	}//end reportBatch()

	/**
	 * Fetch a batch of unread messages from the shared mailbox.
	 *
	 * Real IMAP retrieval depends on `imap_open()` which is not guaranteed
	 * to be installed in every deployment; when missing, the job simply
	 * returns an empty batch — never throws.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @psalm-suppress UnusedFunctionCall
	 */
	private function fetchUnreadBatch(): array {
		if (function_exists('imap_open') === false) {
			$this->logger->debug('imap_open() not available — skipping inbound poll');
			return [];
		}

		$host = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_host', '');
		$port = (int)$this->appConfig->getValueString(Application::APP_ID, 'email_imap_port', '993');
		$encryption = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_encryption', 'ssl');
		$username = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_username', '');
		$password = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_password', '');
		$folder = $this->appConfig->getValueString(Application::APP_ID, 'email_imap_folder', 'INBOX');
		$batchSize = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'email_poll_batch_size',
			(string)self::DEFAULT_BATCH_SIZE,
		);
		if ($batchSize <= 0) {
			$batchSize = self::DEFAULT_BATCH_SIZE;
		}

		$mailbox = '{' . $host . ':' . $port . '/imap/' . $encryption . '}' . $folder;

		$connection = $this->withoutWarnings(
			operation: static function () use ($mailbox, $username, $password): mixed {
				return imap_open($mailbox, $username, $password);
			}
		);
		if ($connection === false) {
			$this->logger->warning(
				'IMAP connection failed',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return [];
		}

		$messages = [];
		try {
			$ids = $this->withoutWarnings(
				operation: static function () use ($connection): mixed {
					return imap_search($connection, 'UNSEEN');
				}
			);
			if (is_array($ids) === false || $ids === []) {
				return [];
			}

			$ids = array_slice($ids, 0, $batchSize);
			foreach ($ids as $id) {
				$headers = $this->withoutWarnings(
					operation: static function () use ($connection, $id): mixed {
						return imap_headerinfo($connection, (int)$id);
					}
				);
				if ($headers === false) {
					continue;
				}

				$messages[] = $this->mapHeaderToMessage(headers: $headers, imapUid: (int)$id);
			}//end foreach
		} finally {
			$this->withoutWarnings(
				operation: static function () use ($connection): mixed {
					return imap_close($connection);
				}
			);
		}//end try

		return $messages;
	}//end fetchUnreadBatch()

	/**
	 * Flatten one `imap_headerinfo()` result into the message array the rest
	 * of the job works with.
	 *
	 * @param object $headers The stdClass returned by imap_headerinfo().
	 * @param int $imapUid The IMAP UID the headers were read from.
	 *
	 * @return array<string, mixed> The normalised message row.
	 */
	private function mapHeaderToMessage(object $headers, int $imapUid): array {
		return [
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- imap_headerinfo() returns a stdClass whose property names are fixed by the PHP IMAP extension.
			'mailMessageId' => (string)($headers->message_id ?? ''),
			'subject' => (string)($headers->subject ?? ''),
			'from' => (string)($headers->fromaddress ?? ''),
			'to' => (string)($headers->toaddress ?? ''),
			'sentAt' => (string)($headers->date ?? ''),
			'imapUid' => $imapUid,
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- imap_headerinfo() returns a stdClass whose property names are fixed by the PHP IMAP extension.
			'sizeBytes' => (int)($headers->Size ?? 0),
		];
	}//end mapHeaderToMessage()

	/**
	 * Check whether a mailMessageId is already linked to a case.
	 *
	 * @param string $messageId RFC822 Message-ID header.
	 *
	 * @return bool
	 */
	private function isAlreadyLinked(string $messageId): bool {
		if ($messageId === '') {
			return false;
		}

		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return false;
			}

			$register = $this->settingsService->getConfigValue('register');
			$schema = $this->settingsService->getConfigValue('case_document_schema');
			if (empty($register) === true || empty($schema) === true) {
				return false;
			}

			if (method_exists($objectService, 'searchObjectsBySlug') === true) {
				// Positional: the narrowed duck-typed object has no parameter
				// names for static analysis. Order matches OpenRegister's
				// searchObjectsBySlug(registerSlug, schemaSlug, filters).
				$rows = $objectService->searchObjectsBySlug(
					(string)$register,
					(string)$schema,
					['mailMessageId' => $messageId, '_limit' => 1]
				);
				return (is_array($rows) === true && $rows !== []);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('isAlreadyLinked check failed', ['error' => $e->getMessage()]);
		}//end try

		return false;
	}//end isAlreadyLinked()

	/**
	 * Best-effort mark the message as "processed" so the next poll skips it.
	 *
	 * Implementation is a no-op when IMAP control is unavailable; the
	 * already-linked check above is still authoritative.
	 *
	 * @param string $messageId Message ID.
	 *
	 * @return void
	 */
	private function markProcessed(string $messageId): void {
		// Real IMAP flag-set / folder-move requires an open connection; future
		// work can plumb that through fetchUnreadBatch's connection scope. The
		// dedup guarantee comes from {@see isAlreadyLinked()}.
		unset($messageId);
	}//end markProcessed()
}//end class
