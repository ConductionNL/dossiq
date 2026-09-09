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
}//end class
