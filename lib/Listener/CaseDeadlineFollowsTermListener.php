<?php

/**
 * Dossiq case deadline follows term listener.
 *
 * Keeps a case's `deadline` on its statutory term's current end date in every
 * save of the case. OpenRegister recomputes the declared `deadline`
 * calculation on every save, and any listener that writes a case back from
 * the payload it was handed writes the date that payload carried. Either
 * would undo the write-back {@see \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror}
 * made when the term moved; this listener runs inside the save, after both,
 * and puts the term's date back.
 *
 * Runs on the PRE-persist update event, below OpenRegister's calculation
 * listener and below {@see CaseInheritedDeadlineListener} (see
 * `CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY`). Its values go back
 * through `setModifiedData`, which OpenRegister merges after every listener
 * has run. A create is not handled: no term exists before the case does, and
 * the bind that follows the create writes the date back itself.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Listener\Support\SchemaMembership;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Put the statutory term's end date into every save of a case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */
class CaseDeadlineFollowsTermListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Schema id bridge.
	 * @param CaseDeadlineMirror $mirror          Which term decides the deadline.
	 * @param LoggerInterface    $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDeadlineMirror $mirror,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the term's date on a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$caseId = $this->savedCaseId(event: $event);
		if ($caseId === '') {
			return;
		}

		$deadline = $this->termDeadline(caseId: $caseId);
		if ($deadline === null || $deadline === '') {
			// No statutory term: the calculated fallback stands (REQ-TERM-001).
			return;
		}

		$this->keepDeadline(event: $event, deadline: $deadline);
	}//end handle()

	/**
	 * The id of the case being saved, or '' when the payload is unreadable, has no id or is not a case.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return string
	 */
	private function savedCaseId(ObjectUpdatingEvent $event): string {
		try {
			$entity = $event->getNewObject();
			$payload = $entity->jsonSerialize();
			$caseId = trim((string)($entity->getUuid() ?? ''));
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: the term deadline listener could not read the payload: ' . $e->getMessage());
			return '';
		}

		if (is_array($payload) === false || $caseId === '' || $this->isCaseSchema(object: $payload) === false) {
			return '';
		}

		return $caseId;
	}//end savedCaseId()

	/**
	 * The statutory term's current end, or null when the terms cannot be read.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string|null
	 */
	private function termDeadline(string $caseId): ?string {
		try {
			return $this->mirror->deadlineFor(caseId: $caseId);
		} catch (RefusedException $e) {
			// The save goes on with what it carried; it is not refused for a
			// read of the terms that failed. The next save puts it right.
			$this->logger->warning(
				'Dossiq termijn: the terms of a case could not be read, so its deadline was left as the save carried it',
				['case' => $caseId, 'error' => $e->getSentence()]
			);
			return null;
		}
	}//end termDeadline()

	/**
	 * Put the term's date on the save, keeping what earlier listeners set.
	 *
	 * @param ObjectUpdatingEvent $event    The update.
	 * @param string              $deadline The term's current end.
	 *
	 * @return void
	 */
	private function keepDeadline(ObjectUpdatingEvent $event, string $deadline): void {
		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['deadline' => $deadline, CaseDeadlineMirror::FIELD => $deadline]
			)
		);
	}//end keepDeadline()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		return SchemaMembership::belongsTo(object: $object, expected: (string)$this->settingsService->getConfigValue('case_schema'));
	}//end isCaseSchema()
}//end class
