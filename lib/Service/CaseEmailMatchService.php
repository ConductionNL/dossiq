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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Email\MailMatchSources;
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\CaseMailLeaf;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Automatic, idempotent attachment of Nextcloud Mail messages to cases.
 *
 * @spec openspec/specs/email-case-matching/spec.md
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
	 * @param MailMatchSources $matching The match preferences, the case number recognizer and the mail message source.
	 * @param IUserManager              $userManager     Resolves the mailbox owner.
	 * @param ContainerInterface        $container       Resolves OpenRegister's email leaf.
	 * @param LoggerInterface           $logger          Logger.
	 * @param CaseMergeService          $mergeService    Follows a merged case to its survivor.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MailMatchSources $matching,
		private readonly IUserManager $userManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly CaseMergeService $mergeService,
	) {
	}//end __construct()

	/**
	 * Whether matching is switched on for this instance.
	 *
	 * @return bool True only for an explicit yes.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
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
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function runForUser(string $userId): array {
		$run = $this->openRun(userId: $userId);
		if ($run === null || $this->startCursor(run: $run, userId: $userId) === false) {
			return ['linked' => 0, 'scanned' => 0];
		}

		$outcome = $this->processBatchAsOwner(
			run: $run,
			batch: $this->matching->messages->listMessagesSince(accountId: $run->accountId, sinceId: $run->cursor, limit: self::BATCH_SIZE)
		);

		if ($outcome['cursor'] > $run->cursor) {
			$this->matching->preferences->writeCursor(userId: $userId, cursor: $outcome['cursor']);
		}

		$this->matching->preferences->writeStatus(userId: $userId, linked: $outcome['linked'], scanned: $outcome['scanned'], error: null);

		return ['linked' => $outcome['linked'], 'scanned' => $outcome['scanned']];
	}//end runForUser()

	/**
	 * The run for one user, or null when this user's mail is not to be matched.
	 *
	 * A run that is refused records why on the user's status.
	 *
	 * @param string $userId The mailbox owner.
	 *
	 * @return CaseEmailMatchRun|null The run, or null when nothing runs.
	 */
	private function openRun(string $userId): ?CaseEmailMatchRun {
		if ($this->isInstanceEnabled() === false) {
			return null;
		}

		$accountId = $this->optedInAccount(userId: $userId);
		$owner = $this->userManager->get($userId);
		if ($accountId <= 0 || $owner === null) {
			return null;
		}

		return $this->unlessRefused(userId: $userId, prepared: $this->prepareRun(owner: $owner, accountId: $accountId));
	}//end openRun()

	/**
	 * The prepared run, or null after recording why it was refused.
	 *
	 * @param string                   $userId   The mailbox owner.
	 * @param CaseEmailMatchRun|string $prepared The run, or the refusal code.
	 *
	 * @return CaseEmailMatchRun|null The run, or null when it was refused.
	 */
	private function unlessRefused(string $userId, CaseEmailMatchRun|string $prepared): ?CaseEmailMatchRun {
		if (is_string($prepared) === true) {
			$this->matching->preferences->writeStatus(userId: $userId, linked: 0, scanned: 0, error: $prepared);
			return null;
		}

		return $prepared;
	}//end unlessRefused()

	/**
	 * The Mail account a user opted in with, or 0 when they did not opt in.
	 *
	 * @param string $userId The mailbox owner.
	 *
	 * @return int The account, 0 or less when matching is off for them.
	 */
	private function optedInAccount(string $userId): int {
		$settings = $this->matching->preferences->getUserSettings(userId: $userId);
		if ($settings['enabled'] === false) {
			return 0;
		}

		return $settings['account'];
	}//end optedInAccount()

	/**
	 * Put the stored cursor on the run, or start the cursor on a first run.
	 *
	 * @param CaseEmailMatchRun $run    The run.
	 * @param string            $userId The mailbox owner.
	 *
	 * @return bool False on a first run, which reads no mail.
	 */
	private function startCursor(CaseEmailMatchRun $run, string $userId): bool {
		$cursor = $this->matching->preferences->readCursor(userId: $userId);
		if ($cursor === CaseEmailMatchPreferences::CURSOR_UNSET) {
			// First run on this account: start at what is already there, so
			// switching matching on does not retro-link a mailbox history.
			$this->matching->preferences->writeCursor(userId: $userId, cursor: $this->matching->messages->maxMessageId(accountId: $run->accountId));
			$this->matching->preferences->writeStatus(userId: $userId, linked: 0, scanned: 0, error: null);
			return false;
		}

		$run->cursor = $cursor;

		return true;
	}//end startCursor()

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
	 * @return CaseEmailMatchRun|string The run, or the refusal code.
	 */
	private function prepareRun(IUser $owner, int $accountId): CaseEmailMatchRun|string {
		$run = new CaseEmailMatchRun(owner: $owner, accountId: $accountId);
		$refusal = $this->mailboxRefusal(run: $run)
			?? $this->configurationRefusal(run: $run)
			?? $this->objectServiceRefusal(run: $run)
			?? $this->emailLeafRefusal(run: $run);

		return $refusal ?? $run;
	}//end prepareRun()

	/**
	 * Refuse a Mail account that is not the owner's, or an unusable pattern.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the pattern.
	 *
	 * @return string|null The refusal code, or null to go on.
	 */
	private function mailboxRefusal(CaseEmailMatchRun $run): ?string {
		if ($this->matching->messages->ownsAccount(accountId: $run->accountId, userId: $run->owner->getUID()) === false) {
			$this->logger->warning(
				'Dossiq: email case matching refused, the configured Mail account is not the user\'s own',
				['app' => AppIdentity::APP_ID, 'userId' => $run->owner->getUID(), 'accountId' => $run->accountId]
			);
			return 'account_not_owned';
		}

		$pattern = $this->matching->recognizer->loadPattern();
		if ($pattern === null) {
			return 'pattern_invalid';
		}

		$run->pattern = $pattern;

		return null;
	}//end mailboxRefusal()

	/**
	 * Refuse when the register or the case schema is not configured.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the register and schema.
	 *
	 * @return string|null The refusal code, or null to go on.
	 */
	private function configurationRefusal(CaseEmailMatchRun $run): ?string {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Dossiq: app-config "register" or "case_schema" is not configured; email case matching is refused, not run unscoped',
				['app' => AppIdentity::APP_ID]
			);
			return 'register_unconfigured';
		}

		$run->register = $register;
		$run->schema = $schema;

		return null;
	}//end configurationRefusal()

	/**
	 * Refuse when OpenRegister is missing or cannot scope a read to the owner.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the object service.
	 *
	 * @return string|null The refusal code, or null to go on.
	 */
	private function objectServiceRefusal(CaseEmailMatchRun $run): ?string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return 'openregister_unavailable';
		}

		if (method_exists($objectService, 'runAs') === false) {
			// Without runAs() the lookup would run with no subject, which a cron
			// process is: OpenRegister then scopes it to every organisation.
			$this->logger->warning(
				'Dossiq: OpenRegister has no runAs(); email case matching is refused rather than resolving cases outside the mailbox owner\'s scope',
				['app' => AppIdentity::APP_ID]
			);
			return 'scope_unavailable';
		}

		$run->objectService = $objectService;

		return null;
	}//end objectServiceRefusal()

	/**
	 * Refuse when OpenRegister's email leaf is not there to link with.
	 *
	 * @param CaseEmailMatchRun $run The run; receives the leaf.
	 *
	 * @return string|null The refusal code, or null to go on.
	 */
	private function emailLeafRefusal(CaseEmailMatchRun $run): ?string {
		$linkService = $this->getEmailLinkService();
		if ($linkService === null || method_exists($linkService, 'linkEmail') === false) {
			return 'email_leaf_unavailable';
		}

		$run->linkService = $linkService;

		return null;
	}//end emailLeafRefusal()

	/**
	 * Process a batch as the mailbox owner.
	 *
	 * `runAs()` sets the owner as the session subject for the duration of the
	 * callable and restores the previous one after, including on a throw. Every
	 * OpenRegister read inside therefore carries the owner's RBAC and
	 * organisation scope, and the leaf's `linkEmail()` has the session user it
	 * requires.
	 *
	 * @param CaseEmailMatchRun $run The run; its cursor is where the batch started after.
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch The messages.
	 *
	 * @return array{linked: int, scanned: int, cursor: int} What the batch did.
	 */
	private function processBatchAsOwner(CaseEmailMatchRun $run, array $batch): array {
		$outcome = $run->objectService->runAs(
			$run->owner,
			fn (): array => $this->processBatch(run: $run, batch: $batch)
		);

		if (is_array($outcome) === false) {
			return ['linked' => 0, 'scanned' => 0, 'cursor' => $run->cursor];
		}

		return [
			'linked' => (int)($outcome['linked'] ?? 0),
			'scanned' => (int)($outcome['scanned'] ?? 0),
			'cursor' => (int)($outcome['cursor'] ?? $run->cursor),
		];
	}//end processBatchAsOwner()

	/**
	 * Match and link one batch. Runs inside `runAs($owner)`.
	 *
	 * A message that throws is logged and skipped; the rest of the batch still
	 * runs. The cursor advances over every message that completed, as
	 * pipelinq's does, so a message that fails at the tail is retried next run.
	 *
	 * @param CaseEmailMatchRun $run The run; its cursor is where the batch started after.
	 * @param array<int, array{id: int, uid: string, subject: string, preview: string}> $batch The messages.
	 *
	 * @return array{linked: int, scanned: int, cursor: int} What the batch did.
	 */
	private function processBatch(CaseEmailMatchRun $run, array $batch): array {
		$scanned = 0;
		$cursor = $run->cursor;
		foreach ($batch as $message) {
			$scanned++;
			$run->message = $message;
			if ($this->processMessage(run: $run) === true) {
				$cursor = max($cursor, $message['id']);
			}
		}

		return ['linked' => $run->linked, 'scanned' => $scanned, 'cursor' => $cursor];
	}//end processBatch()

	/**
	 * Match and link the run's message, logging a throw instead of passing it on.
	 *
	 * @param CaseEmailMatchRun $run The run; its message is the one matched, its linked count grows.
	 *
	 * @return bool True when the message completed, false when it failed.
	 */
	private function processMessage(CaseEmailMatchRun $run): bool {
		try {
			$run->linked += $this->matchAndLinkMessage(run: $run);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: email case matching failed on one message, continuing with the batch: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID, 'messageId' => $run->message['id']]
			);
			return false;
		}

		return true;
	}//end processMessage()

	/**
	 * Match the run's message and link it to every case it names.
	 *
	 * The subject first; the cached body preview only when the subject resolved
	 * nothing (REQ-ECM-002). A failure to link one case is logged and does not
	 * stop the others (REQ-ECM-004).
	 *
	 * @param CaseEmailMatchRun $run The run; its message is the one matched.
	 *
	 * @return int New links made.
	 */
	private function matchAndLinkMessage(CaseEmailMatchRun $run): int {
		$cases = $this->casesIn(run: $run, text: $run->message['subject']);
		if ($cases === []) {
			$cases = $this->casesIn(run: $run, text: $run->message['preview']);
		}

		return $this->linkCases(run: $run, cases: $cases);
	}//end matchAndLinkMessage()

	/**
	 * Link the run's message to each case, logging a refused link and going on.
	 *
	 * @param CaseEmailMatchRun $run The run; its message is the one linked.
	 * @param array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> $cases The cases.
	 *
	 * @return int New links made.
	 */
	private function linkCases(CaseEmailMatchRun $run, array $cases): int {
		$linked = 0;
		foreach ($cases as $case) {
			try {
				if ($this->linkCase(run: $run, case: $case) === true) {
					$linked++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the email leaf refused a case link: ' . $e->getMessage(),
					['app' => AppIdentity::APP_ID, 'case' => $case['identifier'], 'messageId' => $run->message['id']]
				);
			}
		}

		return $linked;
	}//end linkCases()

	/**
	 * The cases one text names that the owner may see.
	 *
	 * @param CaseEmailMatchRun $run  The run.
	 * @param string            $text The subject or the body preview.
	 *
	 * @return array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> The cases.
	 */
	private function casesIn(CaseEmailMatchRun $run, string $text): array {
		$cases = $this->matching->recognizer->resolveCases(
			candidates: $this->matching->recognizer->extractCaseNumberCandidates(text: $text, pattern: $run->pattern),
			objectService: $run->objectService,
			register: $run->register,
			schema: $run->schema,
			owner: $run->owner
		);

		return $this->onSurvivors(cases: $cases);
	}//end casesIn()

	/**
	 * Move every matched case onto the case it was merged into.
	 *
	 * A merged case keeps its number, and an applicant writes back with the
	 * number they were given. Filing that reply on the case that was merged
	 * away puts it where nobody is working, which is the whole reason
	 * `mergedInto` exists. The identifier is left as it was matched: it is
	 * what the mail quoted, and rewriting it would hide which number arrived.
	 *
	 * @param array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> $cases The matched cases.
	 *
	 * @return array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> The same cases, on their survivors.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	private function onSurvivors(array $cases): array {
		$resolved = [];
		$seen = [];

		foreach ($cases as $case) {
			$case['uuid'] = $this->mergeService->resolveSurvivor(caseId: (string)($case['uuid'] ?? ''));
			if (isset($seen[$case['uuid']]) === true) {
				// Two numbers that now name one case are one link, not two.
				continue;
			}

			$seen[$case['uuid']] = true;
			$resolved[] = $case;
		}

		return $resolved;
	}//end onSurvivors()

	/**
	 * Link the run's message to a case unless the leaf already holds that link.
	 *
	 * @param CaseEmailMatchRun $run  The run; its message is the one linked.
	 * @param array{uuid: string, identifier: string, registerId: int, schemaId: int} $case The case.
	 *
	 * @return bool True when a new link was made.
	 */
	private function linkCase(CaseEmailMatchRun $run, array $case): bool {
		if ($this->hasLink(run: $run, objectUuid: $case['uuid']) === true) {
			return false;
		}

		(new CaseMailLeaf(linkService: $run->linkService, accountId: $run->accountId))->link(case: $case, message: $run->message);

		return true;
	}//end linkCase()

	/**
	 * Whether the leaf already links the run's message to this case.
	 *
	 * The leaf is idempotent on its own, so this check exists to keep the
	 * linked count honest: a re-run must not report links it did not make.
	 *
	 * @param CaseEmailMatchRun $run        The run; its leaf, account and message.
	 * @param string            $objectUuid The case.
	 *
	 * @return bool True when the link exists.
	 */
	private function hasLink(CaseEmailMatchRun $run, string $objectUuid): bool {
		if (method_exists($run->linkService, 'getLinkedEmails') === false) {
			return false;
		}

		$cursor = null;
		for ($page = 0; $page < self::LINK_PAGE_LIMIT; $page++) {
			$result = $run->linkService->getLinkedEmails($objectUuid, $cursor, 200);
			if ($this->pageHoldsLink(run: $run, items: ($result['items'] ?? [])) === true) {
				return true;
			}

			if (($result['nextCursor'] ?? null) === null) {
				return false;
			}

			$cursor = (string)$result['nextCursor'];
		}

		return false;
	}//end hasLink()

	/**
	 * Whether one page of leaf rows holds the run's account and message.
	 *
	 * @param CaseEmailMatchRun     $run   The run.
	 * @param array<int, mixed>     $items The leaf rows of one page.
	 *
	 * @return bool True when a row links this message.
	 */
	private function pageHoldsLink(CaseEmailMatchRun $run, array $items): bool {
		foreach ($items as $row) {
			if ((int)($row['mailAccountId'] ?? 0) === $run->accountId && (int)($row['mailMessageId'] ?? 0) === $run->message['id']) {
				return true;
			}
		}

		return false;
	}//end pageHoldsLink()

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
