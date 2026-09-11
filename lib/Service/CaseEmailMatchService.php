<?php

/**
 * Links a user's incoming mail to the cases it names, through OpenRegister's email leaf.
 *
 * A case handler's mail about `2026-0042` lands in their own Nextcloud Mail
 * inbox and reached the case only when they remembered to link it by hand. This
 * service does it for them: it reads the messages that arrived since its last
 * run, recognises case numbers in the subject (and, only when the subject names
 * no case, in the preview Mail caches of the body), and links the message to
 * every case those numbers resolve to. The link is an ordinary email-leaf row,
 * indistinguishable from one made in the Mail sidebar. dossiq writes no link
 * record of its own; that duplication is what this programme removes (ADR-022).
 *
 * The transport is lifted in shape from pipelinq's `EmailMatchService`: a
 * per-user cursor over `mail_messages`, a batch cap, the leaf resolved through
 * the container, a `getLinkedEmails` pre-check before `linkEmail`, and a refusal
 * on an unconfigured register. Only the recognizer is new, and it lives in
 * {@see CaseNumberRecognizer}, the seam design decision D6 wants kept clean so
 * the core can later move into the leaf.
 *
 * 🔴 THE WHOLE BATCH RUNS AS THE MAILBOX OWNER. A case number in a subject is
 * written by whoever sent the mail, so it must resolve only to cases the owner
 * may see. The batch runs inside `ObjectService::runAs($owner)`, which puts the
 * owner in the session for every OpenRegister read, and the recognizer adds
 * the per-case checks (see its class comment).
 *
 * This is also where pipelinq's shape could not be lifted as-is. It runs its
 * lookups in a cron job with no session user, and OpenRegister reads exactly
 * that (CLI, no user) as a trusted system context: the organisation filter
 * answers "every organisation". Its `linkEmail()` calls then refuse with 401,
 * because the leaf requires a session user. `runAs()` fixes both: the scope is
 * the owner's, and the link is recorded as made by the owner.
 *
 * Nothing here creates anything. No match means no write of any kind, and a
 * miss is recorded only as a scanned count (REQ-ECM-005).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Automatic, idempotent attachment of Nextcloud Mail messages to cases.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchService {

	/**
	 * Instance toggle. Anything but an explicit yes means off.
	 *
	 * @var string
	 */
	public const INSTANCE_TOGGLE_KEY = 'email_case_matching_enabled';

	/**
	 * Most messages one run reads for one user.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 200;

	/**
	 * Most leaf pages read when checking for an existing link.
	 *
	 * @var int
	 */
	private const LINK_PAGE_LIMIT = 25;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Register/schema config and the object service.
	 * @param CaseEmailMatchPreferences $preferences     Per-user settings, cursor and status.
	 * @param CaseNumberRecognizer      $recognizer      Case numbers in text, resolved to cases.
	 * @param IUserManager              $userManager     Resolves the mailbox owner.
	 * @param MailMessageSource         $messages        Mail accounts and messages.
	 * @param ContainerInterface        $container       Resolves OpenRegister's email leaf.
	 * @param LoggerInterface           $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseEmailMatchPreferences $preferences,
		private readonly CaseNumberRecognizer $recognizer,
		private readonly IUserManager $userManager,
		private readonly MailMessageSource $messages,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether matching is switched on for this instance.
	 *
	 * @return bool True only for an explicit yes.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function isInstanceEnabled(): bool {
		$value = strtolower(trim($this->settingsService->getConfigValue(self::INSTANCE_TOGGLE_KEY, 'no')));

		return in_array($value, ['yes', 'true', '1'], true);
	}//end isInstanceEnabled()

	/**
	 * Run one matching pass over one user's mail.
	 *
	 * @param string $userId The mailbox owner.
	 *
	 * @return array{linked: int, scanned: int} What the run did.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function runForUser(string $userId): array {
		$nothing = ['linked' => 0, 'scanned' => 0];
		if ($this->isInstanceEnabled() === false) {
			return $nothing;
		}

		$settings = $this->preferences->getUserSettings(userId: $userId);
		$owner = $this->userManager->get($userId);
		if ($settings['enabled'] === false || $settings['account'] <= 0 || $owner === null) {
			return $nothing;
		}

		$context = $this->prepareRun(owner: $owner, accountId: $settings['account']);
		if (is_string($context) === true) {
			$this->preferences->writeStatus(userId: $userId, linked: 0, scanned: 0, error: $context);
			return $nothing;
		}

		$cursor = $this->preferences->readCursor(userId: $userId);
		if ($cursor === CaseEmailMatchPreferences::CURSOR_UNSET) {
			// First run on this account: start at what is already there, so
			// switching matching on does not retro-link a mailbox history.
			$this->preferences->writeCursor(userId: $userId, cursor: $this->messages->maxMessageId(accountId: $settings['account']));
			$this->preferences->writeStatus(userId: $userId, linked: 0, scanned: 0, error: null);
			return $nothing;
		}

		$outcome = $this->processBatchAsOwner(
			batch: $this->messages->listMessagesSince(accountId: $settings['account'], sinceId: $cursor, limit: self::BATCH_SIZE),
			context: $context,
			owner: $owner,
			accountId: $settings['account'],
			cursor: $cursor
		);

		if ($outcome['cursor'] > $cursor) {
			$this->preferences->writeCursor(userId: $userId, cursor: $outcome['cursor']);
		}

		$this->preferences->writeStatus(userId: $userId, linked: $outcome['linked'], scanned: $outcome['scanned'], error: null);

		return ['linked' => $outcome['linked'], 'scanned' => $outcome['scanned']];
	}//end runForUser()

	/**
	 * Everything one run needs, or the code for why it must not run.
	 *
	 * The order is the fail-closed order: the account and the configuration are
	 * checked before OpenRegister is touched at all, so an unconfigured register
	 * refuses with zero OpenRegister calls (REQ-ECM-007).
	 *
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId Their configured Mail account.
	 *
	 * @return array{objectService: object, linkService: object, pattern: string, register: string, schema: string}|string
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One flat guard per precondition, each an early refusal.
	 */
	private function prepareRun(IUser $owner, int $accountId): array|string {
		if ($this->messages->ownsAccount(accountId: $accountId, userId: $owner->getUID()) === false) {
			$this->logger->warning(
				'Dossiq: email case matching refused, the configured Mail account is not the user\'s own',
				['app' => Application::APP_ID, 'userId' => $owner->getUID(), 'accountId' => $accountId]
			);
			return 'account_not_owned';
		}

		$pattern = $this->recognizer->loadPattern();
		if ($pattern === null) {
			return 'pattern_invalid';
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq: app-config "register" or "case_schema" is not configured; email case matching is refused, not run unscoped',
				['app' => Application::APP_ID]
			);
			return 'register_unconfigured';
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return 'openregister_unavailable';
		}

		if (method_exists($objectService, 'runAs') === false) {
			// Without runAs() the lookup would run with no subject, which a cron
			// process is: OpenRegister then scopes it to every organisation.
			$this->logger->warning(
				'Dossiq: OpenRegister has no runAs(); email case matching is refused rather than resolving cases outside the mailbox owner\'s scope',
				['app' => Application::APP_ID]
			);
			return 'scope_unavailable';
		}

		$linkService = $this->getEmailLinkService();
		if ($linkService === null || method_exists($linkService, 'linkEmail') === false) {
			return 'email_leaf_unavailable';
		}

		return [
			'objectService' => $objectService,
			'linkService' => $linkService,
			'pattern' => $pattern,
			'register' => $register,
			'schema' => $schema,
		];
	}//end prepareRun()

	/**
	 * Process a batch as the mailbox owner.
	 *
	 * `runAs()` sets the owner as the session subject for the duration of the
	 * callable and restores the previous one after, including on a throw. Every
	 * OpenRegister read inside therefore carries the owner's RBAC and
	 * organisation scope, and the leaf's `linkEmail()` has the session user it
	 * requires.
	 *
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch The messages.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId The Mail account.
	 * @param int   $cursor    The cursor the batch started after.
	 *
	 * @return array{linked: int, scanned: int, cursor: int} What the batch did.
	 */
	private function processBatchAsOwner(array $batch, array $context, IUser $owner, int $accountId, int $cursor): array {
		$outcome = $context['objectService']->runAs(
			$owner,
			fn (): array => $this->processBatch(
				batch: $batch,
				context: $context,
				owner: $owner,
				accountId: $accountId,
				cursor: $cursor
			)
		);

		if (is_array($outcome) === false) {
			return ['linked' => 0, 'scanned' => 0, 'cursor' => $cursor];
		}

		return [
			'linked' => (int)($outcome['linked'] ?? 0),
			'scanned' => (int)($outcome['scanned'] ?? 0),
			'cursor' => (int)($outcome['cursor'] ?? $cursor),
		];
	}//end processBatchAsOwner()

	/**
	 * Match and link one batch. Runs inside `runAs($owner)`.
	 *
	 * A message that throws is logged and skipped; the rest of the batch still
	 * runs. The cursor advances over every message that completed, as
	 * pipelinq's does, so a message that fails at the tail is retried next run.
	 *
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch The messages.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId The Mail account.
	 * @param int   $cursor    The cursor the batch started after.
	 *
	 * @return array{linked: int, scanned: int, cursor: int} What the batch did.
	 */
	private function processBatch(array $batch, array $context, IUser $owner, int $accountId, int $cursor): array {
		$linked = 0;
		$scanned = 0;
		foreach ($batch as $message) {
			$scanned++;
			try {
				$linked += $this->matchAndLinkMessage(
					message: $message,
					context: $context,
					owner: $owner,
					accountId: $accountId
				);
				$cursor = max($cursor, $message['id']);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: email case matching failed on one message, continuing with the batch: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'messageId' => $message['id']]
				);
			}
		}

		return ['linked' => $linked, 'scanned' => $scanned, 'cursor' => $cursor];
	}//end processBatch()

	/**
	 * Match one message and link it to every case it names.
	 *
	 * The subject first; the cached body preview only when the subject resolved
	 * nothing (REQ-ECM-002). A failure to link one case is logged and does not
	 * stop the others (REQ-ECM-004).
	 *
	 * @param array{id: int, uid: string, subject: string, preview: string} $message The message.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser $owner     The mailbox owner.
	 * @param int   $accountId The Mail account.
	 *
	 * @return int New links made.
	 */
	private function matchAndLinkMessage(array $message, array $context, IUser $owner, int $accountId): int {
		$cases = $this->casesIn(text: $message['subject'], context: $context, owner: $owner);
		if ($cases === []) {
			$cases = $this->casesIn(text: $message['preview'], context: $context, owner: $owner);
		}

		$linked = 0;
		foreach ($cases as $case) {
			try {
				if ($this->linkCase(linkService: $context['linkService'], case: $case, accountId: $accountId, message: $message) === true) {
					$linked++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the email leaf refused a case link: ' . $e->getMessage(),
					['app' => Application::APP_ID, 'case' => $case['identifier'], 'messageId' => $message['id']]
				);
			}
		}

		return $linked;
	}//end matchAndLinkMessage()

	/**
	 * The cases one text names that the owner may see.
	 *
	 * @param string $text  The subject or the body preview.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser  $owner The mailbox owner.
	 *
	 * @return array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> The cases.
	 */
	private function casesIn(string $text, array $context, IUser $owner): array {
		return $this->recognizer->resolveCases(
			candidates: $this->recognizer->extractCaseNumberCandidates(text: $text, pattern: $context['pattern']),
			objectService: $context['objectService'],
			register: $context['register'],
			schema: $context['schema'],
			owner: $owner
		);
	}//end casesIn()

	/**
	 * Link a message to a case unless the leaf already holds that link.
	 *
	 * @param object $linkService The email leaf.
	 * @param array{uuid: string, identifier: string, registerId: int, schemaId: int} $case The case.
	 * @param int    $accountId   The Mail account.
	 * @param array{id: int, uid: string, subject: string, preview: string} $message The message.
	 *
	 * @return bool True when a new link was made.
	 */
	private function linkCase(object $linkService, array $case, int $accountId, array $message): bool {
		if ($this->hasLink(linkService: $linkService, objectUuid: $case['uuid'], accountId: $accountId, messageId: $message['id']) === true) {
			return false;
		}

		$linkService->linkEmail(
			objectUuid: $case['uuid'],
			registerId: $case['registerId'],
			schemaId: $case['schemaId'],
			mailAccountId: $accountId,
			messageId: (string)$message['id'],
			messageUid: $message['uid']
		);

		return true;
	}//end linkCase()

	/**
	 * Whether the leaf already links this message to this case.
	 *
	 * The leaf is idempotent on its own, so this check exists to keep the
	 * linked count honest: a re-run must not report links it did not make.
	 *
	 * @param object $linkService The email leaf.
	 * @param string $objectUuid  The case.
	 * @param int    $accountId   The Mail account.
	 * @param int    $messageId   The Mail message.
	 *
	 * @return bool True when the link exists.
	 */
	private function hasLink(object $linkService, string $objectUuid, int $accountId, int $messageId): bool {
		if (method_exists($linkService, 'getLinkedEmails') === false) {
			return false;
		}

		$cursor = null;
		for ($page = 0; $page < self::LINK_PAGE_LIMIT; $page++) {
			$result = $linkService->getLinkedEmails($objectUuid, $cursor, 200);
			foreach (($result['items'] ?? []) as $row) {
				if ((int)($row['mailAccountId'] ?? 0) === $accountId && (int)($row['mailMessageId'] ?? 0) === $messageId) {
					return true;
				}
			}

			if (($result['nextCursor'] ?? null) === null) {
				return false;
			}

			$cursor = (string)$result['nextCursor'];
		}

		return false;
	}//end hasLink()

	/**
	 * Resolve OpenRegister's email leaf, or null when it is not there.
	 *
	 * @return object|null The leaf service.
	 */
	private function getEmailLinkService(): ?object {
		try {
			$service = $this->container->get('OCA\OpenRegister\Service\EmailLinkService');
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end getEmailLinkService()
}//end class
