<?php

/**
 * Dossiq Notifier.
 *
 * Renders Dossiq's Nextcloud notifications for the bell menu.
 * MentionNotificationService raises `note_mention` notifications when a
 * saved note contains an `@mention` (nc-vue #207, CnNotesTab); this
 * INotifier turns the stored subject key + parameters into localised
 * text with an icon.
 *
 * @category Notification
 * @package  OCA\Dossiq\Notification
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Notification;

use OCA\Dossiq\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Parses Dossiq notifications into localised, rendered form.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class Notifier implements INotifier {

	/**
	 * The subject key for an @mention in a note.
	 */
	public const SUBJECT_NOTE_MENTION = 'note_mention';

	/**
	 * The subject key a `notify` transition action dispatches.
	 */
	public const SUBJECT_CASE_STATUS_CHANGED = 'case_status_changed';

	/**
	 * The subject key a `notifyRole` automatic action dispatches.
	 */
	public const SUBJECT_CASE_ROLE_NOTIFIED = 'case_role_notified';

	/**
	 * The subject key BottleneckDetectionJob dispatches for a stalled milestone.
	 */
	public const SUBJECT_MILESTONE_BOTTLENECK = 'milestone_bottleneck';

	/**
	 * The subject key CaseReassignmentService dispatches for a transfer digest.
	 */
	public const SUBJECT_CASES_REASSIGNED = 'cases_reassigned';

	/**
	 * Advice was asked of the recipient. Two keys, one event: AdviceNotifier
	 * raises `advies_aangevraagd` on the status transition and
	 * `advice_requested` when the request object is created. Both are live
	 * senders, so both are rendered, and they read the same because they mean
	 * the same. Collapsing them into one key is a sender change, not a
	 * renderer change, and it belongs with whoever retires the duplicate.
	 */
	public const SUBJECT_ADVICE_REQUESTED_NL = 'advies_aangevraagd';

	/**
	 * Advice was asked of the recipient, raised at object creation.
	 *
	 * {@see self::SUBJECT_ADVICE_REQUESTED_NL} for why there are two.
	 */
	public const SUBJECT_ADVICE_REQUESTED = 'advice_requested';

	/**
	 * The advice the recipient asked for has come back.
	 */
	public const SUBJECT_ADVICE_RECEIVED = 'advies_ontvangen';

	/**
	 * An advice request the recipient owes an answer on is still open.
	 */
	public const SUBJECT_ADVICE_REMINDER = 'advies_herinnering';

	/**
	 * A Woo request is approaching its decision term.
	 */
	public const SUBJECT_WOO_DEADLINE_WARNING = 'woo_deadline_warning';

	/**
	 * A Woo request has run past its decision term.
	 */
	public const SUBJECT_WOO_DEADLINE_OVERDUE = 'woo_deadline_overdue';

	/**
	 * A permit case is approaching its decision term.
	 */
	public const SUBJECT_DSO_DEADLINE_WARNING = 'dso_deadline_warning';

	/**
	 * A permit case has little time left to decide.
	 */
	public const SUBJECT_DSO_DEADLINE_CRITICAL = 'dso_deadline_critical';

	/**
	 * A permit case has run past its decision term.
	 */
	public const SUBJECT_DSO_DEADLINE_OVERDUE = 'dso_deadline_overdue';

	/**
	 * A StUF endpoint was taken out of service by the circuit breaker.
	 */
	public const SUBJECT_STUF_CIRCUIT_OPEN = 'stuf_circuit_open';

	/**
	 * A StUF message went unanswered.
	 */
	public const SUBJECT_STUF_TIMEOUT = 'stuf_timeout';

	/**
	 * A StUF message came back refused.
	 */
	public const SUBJECT_STUF_PERMANENT_ERROR = 'stuf_permanent_error';

	/**
	 * Every subject key this notifier can render.
	 *
	 * A subject that is not on this list is refused in `prepare()`, and
	 * Nextcloud then drops the notification before the recipient sees it. A
	 * sender adding a subject key adds it here in the same change.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_SUBJECTS = [
		self::SUBJECT_NOTE_MENTION,
		self::SUBJECT_CASE_STATUS_CHANGED,
		self::SUBJECT_CASE_ROLE_NOTIFIED,
		self::SUBJECT_MILESTONE_BOTTLENECK,
		self::SUBJECT_CASES_REASSIGNED,
		self::SUBJECT_ADVICE_REQUESTED_NL,
		self::SUBJECT_ADVICE_REQUESTED,
		self::SUBJECT_ADVICE_RECEIVED,
		self::SUBJECT_ADVICE_REMINDER,
		self::SUBJECT_WOO_DEADLINE_WARNING,
		self::SUBJECT_WOO_DEADLINE_OVERDUE,
		self::SUBJECT_DSO_DEADLINE_WARNING,
		self::SUBJECT_DSO_DEADLINE_CRITICAL,
		self::SUBJECT_DSO_DEADLINE_OVERDUE,
		self::SUBJECT_STUF_CIRCUIT_OPEN,
		self::SUBJECT_STUF_TIMEOUT,
		self::SUBJECT_STUF_PERMANENT_ERROR,
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory Resolves the localisation for the recipient's language.
	 * @param IURLGenerator $urlGenerator Builds the notification icon URL.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Identifier of the notifier, only use [a-z0-9_].
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * Human-readable name describing the notifier.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function getName(): string {
		return 'Dossiq';
	}//end getName()

	/**
	 * Prepare a Dossiq notification for display.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string $languageCode The recipient's language code.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not a Dossiq one.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Notification not handled by Dossiq');
		}

		$subjectKey = $notification->getSubject();
		if (in_array($subjectKey, self::KNOWN_SUBJECTS, true) === false) {
			throw new UnknownNotificationException('Unknown Dossiq notification subject');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$subjectRaw = $notification->getSubjectParameters();

		[$subject, $message] = match ($subjectKey) {
			self::SUBJECT_CASE_STATUS_CHANGED => $this->caseStatusChangedText(subjectRaw: $subjectRaw, l: $l),
			self::SUBJECT_CASE_ROLE_NOTIFIED => $this->caseRoleNotifiedText(subjectRaw: $subjectRaw, l: $l),
			self::SUBJECT_MILESTONE_BOTTLENECK => $this->milestoneBottleneckText(subjectRaw: $subjectRaw, l: $l),
			self::SUBJECT_CASES_REASSIGNED => $this->casesReassignedText(subjectRaw: $subjectRaw, l: $l),
			self::SUBJECT_ADVICE_REQUESTED_NL,
			self::SUBJECT_ADVICE_REQUESTED,
			self::SUBJECT_ADVICE_RECEIVED,
			self::SUBJECT_ADVICE_REMINDER => $this->adviceText(
				subjectKey: $subjectKey,
				subjectRaw: $subjectRaw,
				l: $l,
			),
			self::SUBJECT_WOO_DEADLINE_WARNING,
			self::SUBJECT_WOO_DEADLINE_OVERDUE,
			self::SUBJECT_DSO_DEADLINE_WARNING,
			self::SUBJECT_DSO_DEADLINE_CRITICAL,
			self::SUBJECT_DSO_DEADLINE_OVERDUE => $this->deadlineText(
				subjectKey: $subjectKey,
				subjectRaw: $subjectRaw,
				l: $l,
			),
			self::SUBJECT_STUF_CIRCUIT_OPEN,
			self::SUBJECT_STUF_TIMEOUT,
			self::SUBJECT_STUF_PERMANENT_ERROR => $this->stufText(subjectKey: $subjectKey, l: $l),
			default => $this->noteMentionText(subjectRaw: $subjectRaw, l: $l),
		};

		$notification->setParsedSubject($subject);
		$notification->setParsedMessage($message);
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'))
		);

		return $notification;
	}//end prepare()

	/**
	 * The `note_mention` wording.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`actorDisplayName`, `register`, `schema`, `objectId`, `noteId`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	private function noteMentionText(array $subjectRaw, \OCP\IL10N $l): array {
		$actorDisplayName = (string)($subjectRaw['actorDisplayName'] ?? '');

		$subject = $l->t('You were mentioned in a note');
		if ($actorDisplayName !== '') {
			$subject = $l->t('%s mentioned you in a note', [$actorDisplayName]);
		}

		return [$subject, $l->t('Open the record to see the full note.')];
	}//end noteMentionText()

	/**
	 * The `case_status_changed` wording.
	 *
	 * The `message` parameter is written by whoever configured the `notify`
	 * action on the transition, so it is shown as given. When they left it
	 * empty the recipient still gets the one thing worth doing next.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`caseId`, `transitionLabel`, `message`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function caseStatusChangedText(array $subjectRaw, \OCP\IL10N $l): array {
		$transitionLabel = trim((string)($subjectRaw['transitionLabel'] ?? ''));

		$subject = $l->t('A case you handle changed status');
		if ($transitionLabel !== '') {
			$subject = $l->t('A case you handle changed status: %s', [$transitionLabel]);
		}

		$message = trim((string)($subjectRaw['message'] ?? ''));
		if ($message === '') {
			$message = $l->t('Open the case to see what changed.');
		}

		return [$subject, $message];
	}//end caseStatusChangedText()

	/**
	 * The `case_role_notified` wording.
	 *
	 * The `message` parameter is written by whoever configured the
	 * `notifyRole` action, so it is shown as given. The role slug is NOT in
	 * the wording: it is workflow-configuration vocabulary, and a recipient
	 * who never opened the workflow editor cannot read it.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`caseId`, `roleSlug`, `message`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	private function caseRoleNotifiedText(array $subjectRaw, \OCP\IL10N $l): array {
		$subject = $l->t('A case you handle needs your attention');

		$message = trim((string)($subjectRaw['message'] ?? ''));
		if ($message === '') {
			$message = $l->t('Open the case to see what to do next.');
		}

		return [$subject, $message];
	}//end caseRoleNotifiedText()

	/**
	 * The `milestone_bottleneck` wording.
	 *
	 * BottleneckDetectionJob also stores a raw `plain` message in hardcoded
	 * Dutch. Whatever `prepare()` parses wins on screen, so from here the
	 * recipient reads their own language. The raw message is left where it is:
	 * it sits in stored notification rows, and rewriting those is a data
	 * migration, not a rendering change.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`milestone`, `daysOverdue`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	private function milestoneBottleneckText(array $subjectRaw, \OCP\IL10N $l): array {
		$milestone = trim((string)($subjectRaw['milestone'] ?? ''));

		$subject = $l->t('A case is stuck on a milestone');
		if ($milestone !== '') {
			$subject = $l->t('A case is stuck on %s', [$milestone]);
		}

		// The count goes after a colon rather than into a sentence. This
		// catalogue has no plural pipeline: nothing writes `_one_::_many_`
		// entries and no tool builds them, so an `IL10N::n()` here would look
		// up a key that is not there and hand every Dutch reader the English
		// string. A label reads correctly at one and at twenty.
		$daysOverdue = (int)($subjectRaw['daysOverdue'] ?? 0);
		if ($daysOverdue > 0) {
			return [
				$subject,
				$l->t('Days past the planned date: %s. Open the case and move it on.', [$daysOverdue]),
			];
		}

		return [$subject, $l->t('Open the case and move it on.')];
	}//end milestoneBottleneckText()

	/**
	 * The `cases_reassigned` wording.
	 *
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`fromUser`, `count`).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	private function casesReassignedText(array $subjectRaw, \OCP\IL10N $l): array {
		$count = (int)($subjectRaw['count'] ?? 0);
		$fromUser = trim((string)($subjectRaw['fromUser'] ?? ''));

		$subject = $l->t('Cases were transferred to you');

		if ($fromUser !== '' && $count > 0) {
			return [
				$subject,
				$l->t(
					'Cases from %1$s: %2$s. Open your case list to see them.',
					[$fromUser, $count],
				),
			];
		}

		return [$subject, $l->t('Open your case list to see what is new.')];
	}//end casesReassignedText()

	/**
	 * The four advice wordings.
	 *
	 * `message` carries the advice subject on the request keys, so it is shown
	 * as given. When it is empty the recipient still gets the next step.
	 *
	 * @param string $subjectKey The subject key being rendered.
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`object`, and a `message` the
	 *                                        sender may have set separately).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	private function adviceText(string $subjectKey, array $subjectRaw, \OCP\IL10N $l): array {
		[$subject, $fallback] = match ($subjectKey) {
			self::SUBJECT_ADVICE_RECEIVED => [
				$l->t('Your advice request was answered'),
				$l->t('Open the request to read the advice.'),
			],
			self::SUBJECT_ADVICE_REMINDER => [
				$l->t('An advice request is still open'),
				$l->t('Open the request and send your advice.'),
			],
			default => [
				$l->t('You were asked for advice'),
				$l->t('Open the request to see what is asked.'),
			],
		};

		$message = trim((string)($subjectRaw['message'] ?? ''));
		if ($message === '') {
			$message = $fallback;
		}

		return [$subject, $message];
	}//end adviceText()

	/**
	 * The five deadline wordings.
	 *
	 * The Woo keys carry `daysRemaining`; the permit keys carry only the case,
	 * because DsoDeadlineJob decides the tier itself and sends the tier rather
	 * than the count. So the Woo warning names a number and the permit warning
	 * does not, which is the honest split: inventing a count the sender never
	 * measured would read as precision the notification does not have.
	 *
	 * @param string $subjectKey The subject key being rendered.
	 * @param array<string,mixed> $subjectRaw The stored subject parameters
	 *                                        (`caseId`, and `daysRemaining` on
	 *                                        the Woo keys).
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	private function deadlineText(string $subjectKey, array $subjectRaw, \OCP\IL10N $l): array {
		if ($subjectKey === self::SUBJECT_WOO_DEADLINE_OVERDUE) {
			return [
				$l->t('A Woo deadline has passed'),
				$l->t('The legal term has run out. Decide on this request now.'),
			];
		}

		if ($subjectKey === self::SUBJECT_WOO_DEADLINE_WARNING) {
			$daysRemaining = (int)($subjectRaw['daysRemaining'] ?? 0);
			$subject = $l->t('A Woo request is nearing its deadline');
			if ($daysRemaining > 0) {
				return [
					$subject,
					$l->t('Days left to decide: %s. Open the request.', [$daysRemaining]),
				];
			}

			return [$subject, $l->t('Open the request and plan the decision.')];
		}

		if ($subjectKey === self::SUBJECT_DSO_DEADLINE_OVERDUE) {
			return [
				$l->t('A permit deadline has passed'),
				$l->t('The legal term has run out. Decide on this case now.'),
			];
		}

		if ($subjectKey === self::SUBJECT_DSO_DEADLINE_CRITICAL) {
			return [
				$l->t('A permit deadline is close'),
				$l->t('Little time is left. Open the case and decide.'),
			];
		}

		return [
			$l->t('A permit case is nearing its deadline'),
			$l->t('Open the case and plan the decision.'),
		];
	}//end deadlineText()

	/**
	 * The three StUF wordings.
	 *
	 * These go to every member of the admin group, so they name the thing an
	 * administrator can act on: the endpoint. The endpoint id travels as a
	 * subject parameter and stays out of the wording, because a UUID in a bell
	 * notification is noise.
	 *
	 * @param string $subjectKey The subject key being rendered.
	 * @param \OCP\IL10N $l The recipient-language localisation.
	 *
	 * @return array{0:string,1:string} The [subject, message] pair.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md#REQ-W2L-005
	 */
	private function stufText(string $subjectKey, \OCP\IL10N $l): array {
		if ($subjectKey === self::SUBJECT_STUF_TIMEOUT) {
			return [
				$l->t('A StUF message timed out'),
				$l->t('The other system did not answer. Check the endpoint and try again.'),
			];
		}

		if ($subjectKey === self::SUBJECT_STUF_PERMANENT_ERROR) {
			return [
				$l->t('A StUF message was refused'),
				$l->t('The other system rejected it. Open the endpoint log to see why.'),
			];
		}

		return [
			$l->t('A StUF connection was switched off'),
			$l->t('Too many failures in a row. Check the endpoint settings.'),
		];
	}//end stufText()
}//end class
