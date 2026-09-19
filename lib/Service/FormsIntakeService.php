<?php

/**
 * A citizen fills in a form, and a case opens with the clock already running.
 *
 * 🔴 THE BINDING IS OPT-IN AND LIVES ON THE CASE TYPE. A case type names one
 * Nextcloud Forms form in `intakeFormRef`, and a submission of that form opens
 * a case of that type. A submission of any other form creates nothing at all.
 * That direction matters: a listener that guessed would turn every form on the
 * instance, including the staff lunch poll, into work somebody is measured on.
 *
 * 🔴 THE CASE IS WRITTEN HERE AND NOT BY THE FRONTEND. `startDate` is the day
 * the submission came in, and the materialised `deadline` is computed from it
 * and the case type's `processingDeadline`, so the statutory clock starts when
 * the citizen pressed send rather than when a handler noticed. A raw object
 * insert would skip the status and the date, and the case would land with no
 * status, off every lens, with no clock at all. That is exactly what happened
 * to every copied case before dossiq#2109; the comment in `CaseCopyService`
 * records it.
 *
 * 🔴 IT NEVER NAMES A FORMS CLASS. `forms` is an optional app, and a type hint
 * on a class the instance does not have is a fatal at container build time,
 * not a missing feature. The listener takes the event as `mixed` and reads it
 * with duck typing, and nothing here imports from `OCA\Forms`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a submission of a bound form into a correctly clocked case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */
class FormsIntakeService {

	/**
	 * The channel a case opened this way records.
	 *
	 * @var string
	 */
	public const CHANNEL = 'forms';

	/**
	 * The title a submission with nothing to name it gets.
	 *
	 * @var string
	 */
	private const UNTITLED = 'Aanvraag via formulier';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open a case for one submission, when a case type asked for it.
	 *
	 * @param string               $formHash  The submitted form's hash.
	 * @param array<string, mixed> $answers   The submitted answers, question to value.
	 * @param string               $submitted The submission date, `Y-m-d`. Today when empty.
	 *
	 * @return string|null The new case's id, or `null` when nothing was created.
	 *
	 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
	 */
	public function caseFor(string $formHash, array $answers, string $submitted = ''): ?string {
		$formHash = trim($formHash);
		if ($formHash === '') {
			return null;
		}

		$caseType = $this->caseTypeBoundTo(formHash: $formHash);
		if ($caseType === null) {
			// 🔴 INFO AND NOT WARNING. An unbound form is the ordinary case on
			// any instance that also uses Forms for anything else, and a
			// warning per submission would make the log useless for the times
			// something is actually wrong.
			$this->logger->info(
				'FormsIntakeService: no case type is bound to form {form}, so nothing was created',
				['form' => $formHash]
			);
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			$this->logger->warning(
				'FormsIntakeService: a case type is bound to this form but the register is not '
					. 'configured, so the submission opened no case',
				['form' => $formHash]
			);
			return null;
		}

		$payload = [
			'title' => $this->titleFor(answers: $answers),
			'description' => $this->descriptionFor(answers: $answers),
			'caseType' => (string)($caseType['id'] ?? ''),
			'intakeChannel' => self::CHANNEL,
			// The statutory clock starts the day it came in. The materialised
			// `deadline` is dateAdd(startDate, caseType.processingDeadline),
			// so a startDate left out means no deadline at all.
			'startDate' => $this->submissionDate(submitted: $submitted),
		];

		// A case with no status is off every status-filtered lens, has no
		// available transitions and reads as Unknown in the header. The
		// case type's prefill block fills a FORM and does not run on a write,
		// so the status is written here or it is never written.
		$initial = trim((string)($caseType['initialStatus'] ?? ''));
		if ($initial !== '') {
			$payload['status'] = $initial;
		}

		try {
			$created = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $caseSchema,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'FormsIntakeService: could not open a case for a form submission',
				['form' => $formHash, 'error' => $e->getMessage()]
			);
			return null;
		}

		$caseId = $this->idOf(row: $created);
		if ($caseId === '') {
			$this->logger->error(
				'FormsIntakeService: the case was written but answered no id, so the submission '
					. 'cannot be linked to it',
				['form' => $formHash]
			);
			return null;
		}

		$this->logger->info(
			'FormsIntakeService: form {form} opened case {case}',
			['form' => $formHash, 'case' => $caseId]
		);

		return $caseId;
	}//end caseFor()

	/**
	 * The case type that named this form, when one did.
	 *
	 * @param string $formHash The form's hash.
	 *
	 * @return array<string, mixed>|null The case type, or null when none is bound.
	 *
	 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
	 */
	private function caseTypeBoundTo(string $formHash): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			// 🔴 A BARE KEY, NOT `filter[intakeFormRef]`. The objects endpoint
			// reads a bare property name and answers the EMPTY SET for a
			// `filter[...]` one, with no error either way (openregister#3611).
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'intakeFormRef' => $formHash,
					],
					'limit' => 2,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'FormsIntakeService: could not look up the case type bound to a form',
				['form' => $formHash, 'error' => $e->getMessage()]
			);
			return null;
		}

		$rows = $this->rowsOf(result: $rows);
		foreach ($rows as $row) {
			$row = $this->arrayOf(row: $row);
			// The filter is asked of the store and then CHECKED here. A store
			// that ignored an unknown filter key would answer the whole case
			// type list, and the first row of it would open a case of a type
			// nobody bound to this form.
			if (trim((string)($row['intakeFormRef'] ?? '')) === $formHash) {
				return $row;
			}
		}

		return null;
	}//end caseTypeBoundTo()

	/**
	 * The date the case starts its clock from.
	 *
	 * @param string $submitted The submission date, or '' for today.
	 *
	 * @return string The date as `Y-m-d`.
	 */
	private function submissionDate(string $submitted): string {
		$submitted = trim($submitted);
		if ($submitted === '') {
			return date('Y-m-d');
		}

		$parsed = date_create($submitted);
		if ($parsed === false) {
			return date('Y-m-d');
		}

		return $parsed->format('Y-m-d');
	}//end submissionDate()

	/**
	 * What the case is called.
	 *
	 * The first answer that reads like a sentence, because a citizen filling
	 * in a form has already said what they want in it.
	 *
	 * @param array<string, mixed> $answers The submitted answers.
	 *
	 * @return string The title, within the schema's 255.
	 */
	private function titleFor(array $answers): string {
		foreach ($answers as $value) {
			if (is_string($value) === false) {
				continue;
			}

			$value = trim($value);
			if ($value !== '') {
				return mb_substr($value, 0, 255);
			}
		}

		return self::UNTITLED;
	}//end titleFor()

	/**
	 * What the case says the citizen filled in.
	 *
	 * Every answer, question first, because a handler opening a case that
	 * appeared on its own asks what it says before anything else.
	 *
	 * @param array<string, mixed> $answers The submitted answers.
	 *
	 * @return string The description.
	 */
	private function descriptionFor(array $answers): string {
		$lines = [];
		foreach ($answers as $question => $value) {
			if (is_array($value) === true) {
				$value = implode(', ', array_map('strval', $value));
			}

			$lines[] = sprintf('%s: %s', (string)$question, trim((string)$value));
		}

		if ($lines === []) {
			return '';
		}

		return implode("\n", $lines);
	}//end descriptionFor()

	/**
	 * The rows out of whatever shape the store answered with.
	 *
	 * @param mixed $result The store's answer.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rowsOf(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		if (isset($result['results']) === true && is_array($result['results']) === true) {
			return array_values($result['results']);
		}

		return array_values($result);
	}//end rowsOf()

	/**
	 * One row as an array, whatever the store handed back.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row as an array.
	 */
	private function arrayOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end arrayOf()

	/**
	 * The id of a written object.
	 *
	 * @param mixed $row The saved object.
	 *
	 * @return string The id, or '' when the row names none.
	 */
	private function idOf(mixed $row): string {
		$row = $this->arrayOf(row: $row);

		foreach (['uuid', 'id'] as $key) {
			$value = trim((string)($row[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$self = ($row['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id'] as $key) {
				$value = trim((string)($self[$key] ?? ''));
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end idOf()
}//end class
