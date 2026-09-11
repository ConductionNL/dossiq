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
 * on an unconfigured register. Only the recognizer is new, which is the seam
 * design decision D6 wants kept clean so the core can later move into the leaf.
 *
 * 🔴 A CASE NUMBER IN A SUBJECT IS WRITTEN BY WHOEVER SENT THE MAIL. Anyone can
 * put `2026-0042` in a subject line, so recognising it must never be enough to
 * attach the mail to a case its recipient has no business with, or to a case in
 * another organisation. Three things stand between the two:
 *
 *  - the whole batch runs inside `ObjectService::runAs($owner)`, so the
 *    identifier is resolved with the MAILBOX OWNER's OpenRegister RBAC and
 *    multitenancy (their active organisation and its hierarchy). A case they
 *    cannot see does not come back from the search;
 *  - every case that does come back must also pass
 *    `CaseAccessGuard::hasCaseReadAccess()`, the same per-case check every
 *    dossiq case endpoint uses: the owner handles the case, is among its
 *    assignees, or is an administrator;
 *  - an identifier that resolves to more than one visible case links to none.
 *    Picking one would be a guess, and the guess is exactly the cross-case
 *    attachment this guard exists to prevent.
 *
 * pipelinq ran its lookups with no user at all. In a background job that means
 * a search with no subject, and its `linkEmail()` calls then refused with 401
 * because the leaf needs a session user. `runAs()` supplies that user too,
 * which is why the link is recorded as made by the mailbox owner.
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
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Support\SuppressesWarnings;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Automatic, idempotent attachment of Nextcloud Mail messages to cases.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Orchestrates the Mail tables,
 *     OpenRegister's object and email-leaf services, per-user preferences and
 *     the case access guard; each is a required collaborator of one run.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseEmailMatchService {

	use SearchesObjects;
	use SuppressesWarnings;

	/**
	 * The recognizer every instance starts with (design D2).
	 *
	 * Capture group 1 is the bare identifier the `case` schema materialises
	 * (`2026-0042`). The optional uppercase prefix and brackets accept the
	 * legacy `[ZAAK-2026-000142]` tag as decoration, and the boundary guards
	 * keep `12026-00421` and phone-number fragments out.
	 *
	 * @var string
	 */
	public const DEFAULT_PATTERN = '/(?<![\w-])(?:\[)?(?:[A-Z]{2,10}-)?((?:19|20)\d{2}-\d{4,6})(?:\])?(?![\w-])/u';

	/**
	 * Instance toggle. Anything but an explicit yes means off.
	 *
	 * @var string
	 */
	public const INSTANCE_TOGGLE_KEY = 'email_case_matching_enabled';

	/**
	 * App-config key for a configured recognizer. Empty means the default.
	 *
	 * @var string
	 */
	public const PATTERN_KEY = 'email_case_matching_pattern';

	/**
	 * User preference: whether this user's mail is matched.
	 *
	 * Per-user settings live in user preferences, not in app config keyed by
	 * `<prefix>.<uid>` as pipelinq keeps them. App-config keys are capped at 64
	 * characters and a user id may be 64 characters on its own, so that shape
	 * throws for exactly the long LDAP and SSO ids a municipality uses.
	 *
	 * @var string
	 */
	public const PREF_ENABLED = 'case_matching_enabled';

	/**
	 * User preference: the Mail account id whose messages are matched.
	 *
	 * @var string
	 */
	public const PREF_ACCOUNT = 'case_matching_account';

	/**
	 * User preference: the last Mail message id processed.
	 *
	 * @var string
	 */
	public const PREF_CURSOR = 'case_matching_cursor';

	/**
	 * User preference: JSON status of the last run.
	 *
	 * @var string
	 */
	public const PREF_STATUS = 'case_matching_status';

	/**
	 * The cursor value that means "never started".
	 *
	 * Distinct from 0, which is a real starting point: an account that held no
	 * mail when matching was switched on.
	 *
	 * @var int
	 */
	public const CURSOR_UNSET = -1;

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
	 * @param SettingsService    $settingsService Register/schema config and the object service.
	 * @param IUserConfig        $userConfig      Per-user preferences.
	 * @param IUserManager       $userManager     Resolves the mailbox owner.
	 * @param MailMessageSource  $messages        Mail accounts and messages.
	 * @param CaseAccessGuard    $caseAccess      The per-case read check.
	 * @param ContainerInterface $container       Resolves OpenRegister's email leaf.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserConfig $userConfig,
		private readonly IUserManager $userManager,
		private readonly MailMessageSource $messages,
		private readonly CaseAccessGuard $caseAccess,
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
	 * The users who have switched matching on for themselves.
	 *
	 * @return iterable<string> Their user ids.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function optedInUsers(): iterable {
		return $this->userConfig->searchUsersByValueBool(Application::APP_ID, self::PREF_ENABLED, true);
	}//end optedInUsers()

	/**
	 * Why a recognizer cannot be used, or null when it can.
	 *
	 * It must compile and contain at least one capture group, because group 1
	 * is the identifier. A recognizer that silently matches nothing is the
	 * classic silent failure, so the caller refuses the run instead.
	 *
	 * The group count is taken from PCRE itself rather than by reading the
	 * pattern: the body is wrapped as `(?:body)|` so it always matches the empty
	 * string, and with PREG_UNMATCHED_AS_NULL every group is then reported.
	 *
	 * @param string $pattern A full PCRE pattern, delimiters included.
	 *
	 * @return string|null The reason it is unusable, or null.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function validatePattern(string $pattern): ?string {
		if (trim($pattern) === '') {
			return 'The pattern is empty.';
		}

		$compiled = $this->withoutWarnings(static fn (): int|false => preg_match($pattern, ''));
		if ($compiled === false) {
			return 'The pattern does not compile.';
		}

		$delimiter = $pattern[0];
		$closing = (['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$delimiter] ?? $delimiter);
		$end = strrpos($pattern, $closing);
		if ($end === false || $end === 0) {
			return 'The pattern has no closing delimiter.';
		}

		$probe = $delimiter . '(?:' . substr($pattern, 1, ($end - 1)) . "\n)|" . $closing . substr($pattern, ($end + 1));
		$groups = [];
		$probed = $this->withoutWarnings(
			static function () use ($probe, &$groups): int|false {
				return preg_match($probe, '', $groups, PREG_UNMATCHED_AS_NULL);
			}
		);
		if ($probed === false || (count($groups) - 1) < 1) {
			return 'The pattern has no capture group for the case number.';
		}

		return null;
	}//end validatePattern()

	/**
	 * The recognizer this instance uses, validated, or null when it must not run.
	 *
	 * @return string|null The pattern, or null after logging why it was refused.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function loadPattern(): ?string {
		$pattern = trim($this->settingsService->getConfigValue(self::PATTERN_KEY));
		if ($pattern === '') {
			$pattern = self::DEFAULT_PATTERN;
		}

		$reason = $this->validatePattern(pattern: $pattern);
		if ($reason !== null) {
			$this->logger->error(
				'Dossiq: email case matching refused to run, the configured ' . self::PATTERN_KEY . ' is unusable: ' . $reason,
				['app' => Application::APP_ID]
			);
			return null;
		}

		return $pattern;
	}//end loadPattern()

	/**
	 * The distinct case-number candidates a text contains, in order of appearance.
	 *
	 * @param string $text    The text to scan.
	 * @param string $pattern A validated recognizer.
	 *
	 * @return array<int, string> The identifiers captured by group 1.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function extractCaseNumberCandidates(string $text, string $pattern): array {
		if (trim($text) === '') {
			return [];
		}

		$matches = [];
		$count = $this->withoutWarnings(
			static function () use ($pattern, $text, &$matches): int|false {
				return preg_match_all($pattern, $text, $matches);
			}
		);
		if ($count === false || $count === 0) {
			return [];
		}

		$candidates = [];
		foreach (($matches[1] ?? []) as $candidate) {
			$candidate = trim((string)$candidate);
			if ($candidate !== '' && in_array($candidate, $candidates, true) === false) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}//end extractCaseNumberCandidates()

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

		$settings = $this->getUserSettings(userId: $userId);
		$owner = $this->userManager->get($userId);
		if ($settings['enabled'] === false || $settings['account'] <= 0 || $owner === null) {
			return $nothing;
		}

		$context = $this->prepareRun(owner: $owner, accountId: $settings['account']);
		if (is_string($context) === true) {
			$this->writeStatus(userId: $userId, linked: 0, scanned: 0, error: $context);
			return $nothing;
		}

		$cursor = $this->readCursor(userId: $userId);
		if ($cursor === self::CURSOR_UNSET) {
			// First run on this account: start at what is already there, so
			// switching matching on does not retro-link a mailbox history.
			$this->writeCursor(userId: $userId, cursor: $this->messages->maxMessageId(accountId: $settings['account']));
			$this->writeStatus(userId: $userId, linked: 0, scanned: 0, error: null);
			return $nothing;
		}

		$batch = $this->messages->listMessagesSince(
			accountId: $settings['account'],
			sinceId: $cursor,
			limit: self::BATCH_SIZE
		);

		$outcome = $this->processBatchAsOwner(
			batch: $batch,
			context: $context,
			owner: $owner,
			accountId: $settings['account'],
			cursor: $cursor
		);

		if ($outcome['cursor'] > $cursor) {
			$this->writeCursor(userId: $userId, cursor: $outcome['cursor']);
		}

		$this->writeStatus(userId: $userId, linked: $outcome['linked'], scanned: $outcome['scanned'], error: null);

		return ['linked' => $outcome['linked'], 'scanned' => $outcome['scanned']];
	}//end runForUser()

	/**
	 * A user's matching settings.
	 *
	 * An unreadable preference reads as off: there is no failure that should
	 * start scanning somebody's mailbox.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{enabled: bool, account: int} The settings.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function getUserSettings(string $userId): array {
		try {
			return [
				'enabled' => $this->userConfig->getValueBool($userId, Application::APP_ID, self::PREF_ENABLED, false),
				'account' => $this->userConfig->getValueInt($userId, Application::APP_ID, self::PREF_ACCOUNT, 0),
			];
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading email case matching settings failed: ' . $e->getMessage());
			return ['enabled' => false, 'account' => 0];
		}
	}//end getUserSettings()

	/**
	 * Save a user's matching settings.
	 *
	 * The account must be the user's own. Switching matching on, or pointing it
	 * at another account, restarts the cursor at that account's current newest
	 * message, so nothing already in the mailbox is linked retroactively.
	 *
	 * @param string $userId  The user.
	 * @param bool   $enabled Whether to match this user's mail.
	 * @param int    $account The Mail account to match, 0 for none.
	 *
	 * @return array{enabled: bool, account: int} The settings now stored.
	 *
	 * @throws \InvalidArgumentException When the account is not the user's.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function saveUserSettings(string $userId, bool $enabled, int $account): array {
		if ($account > 0 && $this->messages->ownsAccount(accountId: $account, userId: $userId) === false) {
			throw new \InvalidArgumentException('That Mail account is not yours.');
		}

		$account = max(0, $account);
		$before = $this->getUserSettings(userId: $userId);
		$restart = ($enabled === true
			&& $account > 0
			&& ($before['enabled'] === false || $before['account'] !== $account));

		$this->userConfig->setValueBool($userId, Application::APP_ID, self::PREF_ENABLED, $enabled);
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_ACCOUNT, $account);
		if ($restart === true) {
			$this->writeCursor(userId: $userId, cursor: $this->messages->maxMessageId(accountId: $account));
		}

		return ['enabled' => $enabled, 'account' => $account];
	}//end saveUserSettings()

	/**
	 * The status of a user's last run.
	 *
	 * @param string $userId The user.
	 *
	 * @return array{lastRunAt: ?string, linked: int, scanned: int, error: ?string} The status.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function getStatus(string $userId): array {
		$empty = ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		try {
			$json = $this->userConfig->getValueString($userId, Application::APP_ID, self::PREF_STATUS, '');
		} catch (Throwable $e) {
			return $empty;
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return $empty;
		}

		return [
			'lastRunAt' => (isset($decoded['lastRunAt']) === true ? (string)$decoded['lastRunAt'] : null),
			'linked' => (int)($decoded['linked'] ?? 0),
			'scanned' => (int)($decoded['scanned'] ?? 0),
			'error' => (isset($decoded['error']) === true ? (string)$decoded['error'] : null),
		];
	}//end getStatus()

	/**
	 * Record the outcome of a user's run.
	 *
	 * The error is a short code the settings screen translates, never message
	 * content: the matcher stores nothing about a mail but counts.
	 *
	 * @param string      $userId  The user.
	 * @param int         $linked  New links the run made.
	 * @param int         $scanned Messages the run read.
	 * @param string|null $error   Why the run refused, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error): void {
		$payload = json_encode(
			[
				'lastRunAt' => gmdate('c'),
				'linked' => $linked,
				'scanned' => $scanned,
				'error' => $error,
			]
		);

		try {
			$this->userConfig->setValueString($userId, Application::APP_ID, self::PREF_STATUS, (string)$payload);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: recording the email case matching status failed: ' . $e->getMessage());
		}
	}//end writeStatus()

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

		$pattern = $this->loadPattern();
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
			// Without runAs() the lookup would run with no subject, or with
			// whoever the process happens to be. Neither is the mailbox owner.
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
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch     The messages.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser                                                                    $owner     The mailbox owner.
	 * @param int                                                                      $accountId The Mail account.
	 * @param int                                                                      $cursor    The cursor the batch started after.
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
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch     The messages.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser                                                                    $owner     The mailbox owner.
	 * @param int                                                                      $accountId The Mail account.
	 * @param int                                                                      $cursor    The cursor the batch started after.
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
	 * @param array{id: int, uid: string, subject: string, preview: string} $message   The message.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser                                                        $owner     The mailbox owner.
	 * @param int                                                          $accountId The Mail account.
	 *
	 * @return int New links made.
	 */
	private function matchAndLinkMessage(array $message, array $context, IUser $owner, int $accountId): int {
		$cases = $this->resolveCases(
			candidates: $this->extractCaseNumberCandidates(text: $message['subject'], pattern: $context['pattern']),
			context: $context,
			owner: $owner
		);
		if ($cases === []) {
			$cases = $this->resolveCases(
				candidates: $this->extractCaseNumberCandidates(text: $message['preview'], pattern: $context['pattern']),
				context: $context,
				owner: $owner
			);
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
	 * Resolve candidates to the cases the mailbox owner may see.
	 *
	 * @param array<int, string> $candidates The identifiers found in the text.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser              $owner      The mailbox owner.
	 *
	 * @return array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> The cases, distinct.
	 */
	private function resolveCases(array $candidates, array $context, IUser $owner): array {
		$cases = [];
		foreach ($candidates as $identifier) {
			$case = $this->resolveCase(identifier: $identifier, context: $context, owner: $owner);
			if ($case !== null) {
				$cases[$case['uuid']] = $case;
			}
		}

		return array_values($cases);
	}//end resolveCases()

	/**
	 * Resolve one identifier to exactly one case the owner may read, or nothing.
	 *
	 * The search runs as the owner (the caller is inside `runAs()`), and its
	 * rows are then held to exact equality on `identifier`: a search filter
	 * that matched loosely must not become a link.
	 *
	 * @param string $identifier The candidate identifier.
	 * @param array{objectService: object, linkService: object, pattern: string, register: string, schema: string} $context The run context.
	 * @param IUser  $owner      The mailbox owner.
	 *
	 * @return array{uuid: string, identifier: string, registerId: int, schemaId: int}|null The case, or null.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One flat refusal per way a candidate can fail to be a case.
	 */
	private function resolveCase(string $identifier, array $context, IUser $owner): ?array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: [
					'identifier' => $identifier,
					'_limit' => 10,
					'_rbac' => true,
					'_multitenancy' => true,
					// 🔴 REQUIRED, NOT DECORATION. OpenRegister skips the
					// organisation filter for a caller whose RBAC already grants
					// the schema ("let RBAC handle access control"), and for a
					// schema with public read. A case handler holds that grant,
					// so without this flag the lookup spans every organisation.
					// Asking explicitly keeps the owner's active organisation
					// (and its parents) as the boundary in every case.
					'_multitenancy_explicit' => true,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: resolving a case number failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}

		$hits = array_values(
			array_filter($rows, static fn (array $row): bool => (string)($row['identifier'] ?? '') === $identifier)
		);
		if (count($hits) !== 1) {
			if (count($hits) > 1) {
				$this->logger->warning(
					'Dossiq: a case number resolves to more than one case; the mail is linked to none of them',
					['app' => Application::APP_ID, 'case' => $identifier, 'matches' => count($hits)]
				);
			}

			return null;
		}

		$uuid = $this->idOf(row: $hits[0]);
		if ($uuid === '' || $this->caseAccess->hasCaseReadAccess(caseId: $uuid, user: $owner) === false) {
			return null;
		}

		$self = (is_array($hits[0]['@self'] ?? null) === true ? $hits[0]['@self'] : []);
		$registerId = $this->numericId(fromRow: $self['register'] ?? null, fromConfig: $context['register']);
		$schemaId = $this->numericId(fromRow: $self['schema'] ?? null, fromConfig: $context['schema']);
		if ($registerId <= 0 || $schemaId <= 0) {
			$this->logger->warning(
				'Dossiq: a matched case carries no numeric register or schema id; it is not linked rather than linked to id 0',
				['app' => Application::APP_ID, 'case' => $identifier]
			);
			return null;
		}

		return ['uuid' => $uuid, 'identifier' => $identifier, 'registerId' => $registerId, 'schemaId' => $schemaId];
	}//end resolveCase()

	/**
	 * Link a message to a case unless the leaf already holds that link.
	 *
	 * @param object                                                               $linkService The email leaf.
	 * @param array{uuid: string, identifier: string, registerId: int, schemaId: int} $case       The case.
	 * @param int                                                                  $accountId   The Mail account.
	 * @param array{id: int, uid: string, subject: string, preview: string}        $message     The message.
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
	 * The object's uuid from an OpenRegister row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or '' when it carries none.
	 */
	private function idOf(array $row): string {
		$self = (is_array($row['@self'] ?? null) === true ? $row['@self'] : []);

		return (string)($self['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
	}//end idOf()

	/**
	 * A register or schema id as the integer the leaf stores.
	 *
	 * The row's own `@self` value wins, because that is where the case lives;
	 * the configured value is used only when it is numeric. A slug is never
	 * cast, because `(int)'dossiq'` is 0 and a link to register 0 is a write to
	 * the wrong place rather than a refusal.
	 *
	 * @param mixed  $fromRow    The row's `@self` value.
	 * @param string $fromConfig The configured value.
	 *
	 * @return int The id, or 0 when neither is numeric.
	 */
	private function numericId(mixed $fromRow, string $fromConfig): int {
		foreach ([(string)(is_scalar($fromRow) === true ? $fromRow : ''), $fromConfig] as $candidate) {
			if ($candidate !== '' && ctype_digit($candidate) === true) {
				return (int)$candidate;
			}
		}

		return 0;
	}//end numericId()

	/**
	 * The user's cursor, or CURSOR_UNSET when it was never started.
	 *
	 * @param string $userId The user.
	 *
	 * @return int The cursor.
	 */
	private function readCursor(string $userId): int {
		try {
			return $this->userConfig->getValueInt($userId, Application::APP_ID, self::PREF_CURSOR, self::CURSOR_UNSET);
		} catch (Throwable $e) {
			return self::CURSOR_UNSET;
		}
	}//end readCursor()

	/**
	 * Store the user's cursor.
	 *
	 * @param string $userId The user.
	 * @param int    $cursor The last processed message id.
	 *
	 * @return void
	 */
	private function writeCursor(string $userId, int $cursor): void {
		$this->userConfig->setValueInt($userId, Application::APP_ID, self::PREF_CURSOR, max(0, $cursor));
	}//end writeCursor()

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

		return (is_object($service) === true ? $service : null);
	}//end getEmailLinkService()
}//end class
