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
 * 🔴 THE CASE IS WRITTEN HERE AND NOT BY THE FRONTEND. The case goes through
 * OpenRegister's form submit with the mapping the case type was saved with
 * (`intakeFormMapping`, judged against the case schema at that save), so the
 * payload is validated against the case schema and written in one request. A
 * raw object insert would skip the status, and the case would land with no
 * status, off every lens. That is what happened to every copied case before
 * dossiq#2109; the comment in `CaseCopyService` records it. The receipt and
 * start dates are set by the receipt listener, by the one rule every case
 * follows, so the statutory clock starts when the citizen pressed send, or on
 * the later day the term starts.
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
 * @spec openspec/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a submission of a bound form into a correctly clocked case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/leaf-integrations/spec.md
 */
class FormsIntakeService {

	/**
	 * The channel a case opened this way records.
	 *
	 * @var string
	 */
	public const CHANNEL = IntakeFormBinding::CHANNEL;

	/**
	 * OpenRegister's form submit: one request, validated against the destination, answered with its reference.
	 *
	 * @var string
	 */
	public const FORM_SUBMIT = 'OCA\\OpenRegister\\Service\\Form\\FormSubmitService';

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface   $logger          Logger.
	 * @param IntakeFormBinding $binding         Reads the case type's binding as a form mapping.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IntakeFormBinding $binding = new IntakeFormBinding(),
	) {
	}//end __construct()

	/**
	 * Open a case for one submission, when a case type asked for it.
	 *
	 * @param string               $formHash The submitted form's hash.
	 * @param array<string, mixed> $answers  The submitted answers, question to value.
	 *
	 * @return string|null The new case's id, or `null` when nothing was created.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function caseFor(string $formHash, array $answers): ?string {
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

		// The mapping the case type was saved with is the mapping the case is
		// written with. The receipt and start dates are not written here: the
		// receipt listener sets them for every case by one rule (decision 218).
		$mapping = $this->binding->mappingFor(caseType: $caseType, answers: $answers);

		try {
			$created = $this->write(
				objectService: $objectService,
				destination: ['register' => $register, 'schema' => $caseSchema],
				mapping: $mapping,
				answers: $answers,
				formHash: $formHash
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'FormsIntakeService: could not open a case for a form submission',
				['form' => $formHash, 'error' => $e->getMessage(), 'findings' => $this->findingsOf(refusal: $e)]
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
	 * @spec openspec/specs/leaf-integrations/spec.md
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
	 * Write the case: through OpenRegister's form submit, or as a plain save without it.
	 *
	 * The form submit validates the payload against the case schema and writes
	 * it in one request. An OpenRegister without it gets the same object
	 * through the object service.
	 *
	 * @param object                                                                       $objectService OpenRegister's object service.
	 * @param array{register: string, schema: string}                                      $destination   The case register and schema.
	 * @param array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} $mapping       The binding's mapping.
	 * @param array<string, mixed>                                                         $answers       The submitted answers.
	 * @param string                                                                       $formHash      The form, as the scope of the submit.
	 *
	 * @return mixed The written case, or the submit's answer; both name its id.
	 */
	private function write(object $objectService, array $destination, array $mapping, array $answers, string $formHash): mixed {
		$submitter = $this->settingsService->getOpenRegisterClass(self::FORM_SUBMIT);
		if ($submitter !== null && method_exists($submitter, 'submit') === true) {
			return $submitter->submit(
				destination: $destination,
				mapping: $mapping,
				payload: $answers,
				scope: $formHash
			);
		}

		$object = $mapping['fixed'];
		foreach ($mapping['fields'] as $field) {
			if (array_key_exists($field['field'], $answers) === true) {
				$object[$field['property']] = $answers[$field['field']];
			}
		}

		return $objectService->saveObject(
			object: $object,
			register: $destination['register'],
			schema: $destination['schema'],
		);
	}//end write()

	/**
	 * The findings a refused submit names, for the log.
	 *
	 * @param Throwable $refusal What the write threw.
	 *
	 * @return array<int, mixed> The findings, or an empty list when it names none.
	 */
	private function findingsOf(Throwable $refusal): array {
		if (method_exists($refusal, 'getFindings') === false) {
			return [];
		}

		$findings = $refusal->getFindings();
		if (is_array($findings) === false) {
			return [];
		}

		return array_values($findings);
	}//end findingsOf()

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
