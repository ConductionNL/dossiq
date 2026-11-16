<?php

/**
 * Dossiq submission closing date guard.
 *
 * Refuses a submission that arrives after the closing date of the case it is
 * submitted to. Generic: a record of a submission schema names the case it is
 * for and the moment it came in, the case carries the day submissions close,
 * and a submission received on that day still counts. The table below says,
 * per submission schema, which field names the case, which case field holds
 * the closing day and which field holds the moment of receipt.
 *
 * Only a create is checked: a submission that came in on time keeps moving
 * through its lifecycle after the closing date. OpenRegister's bulk save,
 * import and flow writes fire no ObjectCreatingEvent and are not covered.
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\SchemaMembership;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops the create of a submission received after its case's closing date.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */
class SubmissionClosingDateGuard implements IEventListener {

	/**
	 * The refusal code a client can recognise the refusal by.
	 */
	public const REFUSAL_CODE = 'submission-after-closing-date';

	/**
	 * Per submission schema (by its config key): the field naming the case,
	 * the case field holding the closing day, and the field holding the
	 * moment the submission came in.
	 *
	 * @var array<string, array{case: string, closesOn: string, receivedAt: string}>
	 */
	public const SUBMISSIONS = [
		'bid_schema' => ['case' => 'tender', 'closesOn' => 'sluiting', 'receivedAt' => 'submittedAt'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface    $settingsService Schema slug bridge.
	 * @param CaseObjectReaderInterface   $cases           Reads the case a submission names.
	 * @param CaseDateNormaliserInterface $dates           Reads a stored date and today in the administrator's time zone.
	 * @param IL10N                       $l10n            The app's translations.
	 * @param LoggerInterface             $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseObjectReaderInterface $cases,
		private readonly CaseDateNormaliserInterface $dates,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the create of a submission that came in after its case closed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		try {
			$submission = $event->getObject()->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: submission closing date check could not read the payload: ' . $e->getMessage());
			return;
		}

		$fields = $this->fieldsFor(submission: $submission);
		if ($fields === null) {
			return;
		}

		$closingDay = $this->closingDayOf(caseId: ReferenceId::of(value: ($submission[$fields['case']] ?? null)), field: $fields['closesOn']);
		if ($closingDay === null) {
			return;
		}

		$receivedDay = ($this->dates->toCalendarDateOrNull(value: ($submission[$fields['receivedAt']] ?? null)) ?? $this->dates->todayAsCalendarDate());
		if (strcmp($receivedDay, $closingDay) <= 0) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t('Submissions closed on %s. This one came in after the closing date.', [$closingDay]),
				'code' => self::REFUSAL_CODE,
				'codes' => [self::REFUSAL_CODE],
				'closingDate' => $closingDay,
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The submission fields of the schema this record belongs to, or null when it is no submission.
	 *
	 * @param array<string, mixed> $submission The record being created (incl. `@self`).
	 *
	 * @return array{case: string, closesOn: string, receivedAt: string}|null
	 */
	private function fieldsFor(array $submission): ?array {
		foreach (self::SUBMISSIONS as $configKey => $fields) {
			if (SchemaMembership::belongsTo(object: $submission, expected: $this->settingsService->getConfigValue($configKey)) === true) {
				return $fields;
			}
		}

		return null;
	}//end fieldsFor()

	/**
	 * The closing day of the case, or null when there is no case or it sets no closing day.
	 *
	 * @param string $caseId The case the submission names.
	 * @param string $field  The case field holding the closing day.
	 *
	 * @return string|null The `Y-m-d` day.
	 */
	private function closingDayOf(string $caseId, string $field): ?string {
		if ($caseId === '') {
			return null;
		}

		$case = $this->cases->fetchCaseById(caseUuid: $caseId);
		if ($case === null) {
			return null;
		}

		return $this->dates->toCalendarDateOrNull(value: $this->valueOf(case: $case, field: $field));
	}//end closingDayOf()

	/**
	 * The field's value on the case, or in its answered properties.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return mixed The value, or null.
	 */
	private function valueOf(array $case, string $field): mixed {
		if (isset($case[$field]) === true) {
			return $case[$field];
		}

		$entries = ($case['properties'] ?? null);
		if (is_array($entries) === false) {
			return null;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === true && (string)($entry['name'] ?? '') === $field) {
				return ($entry['value'] ?? null);
			}
		}

		return null;
	}//end valueOf()
}//end class
