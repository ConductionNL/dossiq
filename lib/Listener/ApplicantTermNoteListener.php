<?php

/**
 * Dossiq applicant term note listener.
 *
 * Puts what the applicant is told about the term into every save of a case:
 * `termNote` and `legalDecisionDate`, from
 * {@see \OCA\Dossiq\Service\Termijn\ApplicantTermNote}. The portal projects
 * the case to a field list and cannot follow the term, the open request or
 * the journal, so the words live on the case.
 *
 * Runs on the PRE-persist update event, below
 * {@see CaseDeadlineFollowsTermListener}: it reads the deadline that
 * listener just put into the save, so it reads no term itself. Its values go
 * back through `setModifiedData`, and only the ones that differ from what
 * the case already carries, so a save that changes nothing about the term
 * writes nothing extra.
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
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\ApplicantTermNote;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keep the applicant's term note on a case about to be written.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
 */
class ApplicantTermNoteListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Schema id bridge.
	 * @param ApplicantTermNote $note            The sentence and the date.
	 * @param CaseTypeReader    $caseTypes       The case type's extension period.
	 * @param LoggerInterface   $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ApplicantTermNote $note,
		private readonly CaseTypeReader $caseTypes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the note and the date into the save when they changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-applicant-reads-the-decision-date-and-the-state-of-the-term-req-wds-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		try {
			$payload = $event->getNewObject()->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: the applicant term note listener could not read the payload: ' . $e->getMessage());
			return;
		}

		if (is_array($payload) === false || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		// What the save will hold: the payload with what earlier listeners
		// (the calculation, the statutory term's deadline) already changed.
		$modified = $event->getModifiedData();
		$case = array_merge($payload, $modified);

		try {
			$fields = $this->note->fieldsFor(
				case: $case,
				deadline: (string)($case['deadline'] ?? ''),
				extensionPeriod: fn (string $caseTypeId): string => $this->caseTypes->read(caseTypeId: $caseTypeId)['extensionPeriod'],
			);
		} catch (Throwable $e) {
			// The save goes on with what it carried; the next save puts the
			// note right. A sentence is never a reason to refuse a save.
			$this->logger->warning(
				'Dossiq termijn: the applicant term note could not be worked out, so it was left as the save carried it',
				['error' => $e->getMessage()]
			);
			return;
		}

		$changes = [];
		foreach ($fields as $field => $value) {
			$stored = ($case[$field] ?? null);
			if ((string)($stored ?? '') !== (string)($value ?? '')) {
				$changes[$field] = $value;
			}
		}

		if ($changes === []) {
			return;
		}

		$event->setModifiedData(array_merge($modified, $changes));
	}//end handle()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = (string)$this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isCaseSchema()
}//end class
