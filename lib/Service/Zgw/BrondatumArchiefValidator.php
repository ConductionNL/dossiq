<?php

/**
 * Dossiq brondatumArchiefprocedure validator.
 *
 * The cross-field constraints on a resultaattype's nested
 * `brondatumArchiefprocedure` value object (ztc-003 to ztc-008). Which of
 * datumkenmerk / objecttype / registratie / procestermijn are required and
 * which are forbidden is decided entirely by `afleidingswijze`, and ztc-003
 * additionally cross-checks that afleidingswijze against the selectielijst-
 * klasse's own procestermijn. That is a self-contained rule table over one
 * nested object, so it lives here rather than inside the resultaattype rules
 * that happen to invoke it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Validates brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrondatumArchiefValidator extends ZgwRulesBase {
	/**
	 * Afleidingswijze values that REQUIRE datumkenmerk (ztc-004).
	 *
	 * @var array<string>
	 */
	private const AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK = [
		'eigenschap',
		'zaakobject',
		'ander_datumkenmerk',
	];

	/**
	 * Afleidingswijze values that REQUIRE objecttype (ztc-006).
	 *
	 * @var array<string>
	 */
	private const AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE = [
		'zaakobject',
		'ander_datumkenmerk',
	];

	/**
	 * Afleidingswijze values that FORBID einddatumBekend=true (ztc-005).
	 *
	 * @var array<string>
	 */
	private const AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND = [
		'afgehandeld',
		'termijn',
	];

	/**
	 * Per selectielijstklasse procestermijn, the one afleidingswijze it allows (ztc-003).
	 *
	 * @var array<string, string>
	 */
	private const ONLY_AFLEIDINGSWIJZE_FOR_PROCESTERMIJN = [
		'nihil'                     => 'afgehandeld',
		'bestaansduur_procesobject' => 'termijn',
	];

	/**
	 * Per archive procedure field, the afleidingswijze values that require it;
	 * every other afleidingswijze forbids it (ztc-004, ztc-006 to ztc-008).
	 *
	 * @var array<string, array<string>>
	 */
	private const PRESENCE_REQUIRED_FOR = [
		'datumkenmerk'  => self::AFLEIDINGSWIJZE_REQUIRES_DATUMKENMERK,
		'objecttype'    => self::AFLEIDINGSWIJZE_REQUIRES_OBJECTTYPE,
		'registratie'   => ['ander_datumkenmerk'],
		'procestermijn' => ['termijn'],
	];

	/**
	 * Validate brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008).
	 *
	 * @param array $archive The brondatumArchiefprocedure data
	 * @param array|null $selectielijstData The fetched selectielijstklasse data
	 *
	 * @return array<array{name: string, code: string, reason: string}> Validation errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function validate(array $archive, ?array $selectielijstData): array {
		$afleidingswijze = $archive['afleidingswijze'] ?? '';

		return array_merge(
			// Ztc-004: datumkenmerk required/forbidden.
			$this->presenceErrors(archive: $archive, field: 'datumkenmerk'),
			// Ztc-005: einddatumBekend must be false for afgehandeld/termijn.
			$this->einddatumBekendErrors(archive: $archive),
			// Ztc-006: objecttype required/forbidden.
			$this->presenceErrors(archive: $archive, field: 'objecttype'),
			// Ztc-007: registratie required only for ander_datumkenmerk.
			$this->presenceErrors(archive: $archive, field: 'registratie'),
			// Ztc-008: procestermijn required only for termijn.
			$this->presenceErrors(archive: $archive, field: 'procestermijn'),
			// Ztc-003: Validate afleidingswijze against selectielijstklasse.procestermijn.
			$this->selectielijstErrors(afleidingswijze: $afleidingswijze, selectielijstData: $selectielijstData)
		);
	}//end validate()

	/**
	 * The required/forbidden error for one field of the archive procedure.
	 *
	 * @param array  $archive The brondatumArchiefprocedure data
	 * @param string $field   The field, a key of PRESENCE_REQUIRED_FOR
	 *
	 * @return array<array{name: string, code: string, reason: string}> Validation errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function presenceErrors(array $archive, string $field): array {
		return $this->fieldPresenceErrors(
			afleidingswijze: $archive['afleidingswijze'] ?? '',
			field: new ArchiveProcedureField(name: $field, value: $this->presenceValue(archive: $archive, field: $field))
		);
	}//end presenceErrors()

	/**
	 * The value a field's presence is judged on: procestermijn only counts
	 * as a string, every other field as stored, or '' when absent.
	 *
	 * @param array  $archive The brondatumArchiefprocedure data
	 * @param string $field   The field
	 *
	 * @return mixed The value
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function presenceValue(array $archive, string $field): mixed {
		if ($field !== 'procestermijn') {
			return ($archive[$field] ?? '');
		}

		$procestermijn = $archive['procestermijn'] ?? null;
		if (is_string($procestermijn) === true) {
			return $procestermijn;
		}

		return '';
	}//end presenceValue()

	/**
	 * Ztc-005: einddatumBekend must be false for afgehandeld/termijn.
	 *
	 * @param array $archive The brondatumArchiefprocedure data
	 *
	 * @return array<array{name: string, code: string, reason: string}> Validation errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function einddatumBekendErrors(array $archive): array {
		$afleidingswijze = $archive['afleidingswijze'] ?? '';
		if ($this->setsForbiddenEinddatumBekend(archive: $archive) === false) {
			return [];
		}

		return [
			$this->fieldError(
				fieldName: 'brondatumArchiefprocedure.einddatumBekend',
				code: 'must-be-empty',
				reason: "einddatumBekend moet false zijn voor afleidingswijze \"{$afleidingswijze}\"."
			),
		];
	}//end einddatumBekendErrors()

	/**
	 * Whether einddatumBekend is set while the afleidingswijze forbids it.
	 *
	 * @param array $archive The brondatumArchiefprocedure data
	 *
	 * @return bool True when it is set for afgehandeld or termijn
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function setsForbiddenEinddatumBekend(array $archive): bool {
		$afleidingswijze = $archive['afleidingswijze'] ?? '';
		$endDateBekend   = $archive['einddatumBekend'] ?? false;

		return ($endDateBekend === true || $endDateBekend === 'true')
			&& in_array($afleidingswijze, self::AFLEIDINGSWIJZE_FORBIDS_EINDDATUM_BEKEND, true) === true;
	}//end setsForbiddenEinddatumBekend()

	/**
	 * Ztc-003: the afleidingswijze against the selectielijstklasse's procestermijn,
	 * when the selectielijstklasse was fetched.
	 *
	 * @param string     $afleidingswijze   The afleidingswijze value
	 * @param array|null $selectielijstData The fetched selectielijstklasse data
	 *
	 * @return array<array{name: string, code: string, reason: string}> Validation errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function selectielijstErrors(string $afleidingswijze, ?array $selectielijstData): array {
		if ($selectielijstData === null) {
			return [];
		}

		$ptCheck = $this->checkProcestermijnCompatibility(
			afleidingswijze: $afleidingswijze,
			procestermijn: $selectielijstData['procestermijn'] ?? null
		);
		if ($ptCheck === null) {
			return [];
		}

		return [$ptCheck];
	}//end selectielijstErrors()

	/**
	 * Validate field presence based on afleidingswijze (required vs forbidden).
	 *
	 * @param string                $afleidingswijze The afleidingswijze value
	 * @param ArchiveProcedureField $field           The field and its value
	 *
	 * @return array<array{name: string, code: string, reason: string}> Validation errors
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function fieldPresenceErrors(string $afleidingswijze, ArchiveProcedureField $field): array {
		$hasValue   = $field->isFilled();
		$isRequired = in_array($afleidingswijze, self::PRESENCE_REQUIRED_FOR[$field->name], true);

		if ($isRequired === true && $hasValue === false) {
			return [$this->requiredError(fieldName: $field->path(), afleidingswijze: $afleidingswijze)];
		}

		if ($isRequired === false && $hasValue === true) {
			return [$this->mustBeEmptyError(fieldName: $field->path(), afleidingswijze: $afleidingswijze)];
		}

		return [];
	}//end fieldPresenceErrors()

	/**
	 * The error for a field the afleidingswijze requires but that is empty.
	 *
	 * @param string $fieldName       The full field path
	 * @param string $afleidingswijze The afleidingswijze value
	 *
	 * @return array{name: string, code: string, reason: string} The error
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function requiredError(string $fieldName, string $afleidingswijze): array {
		return $this->fieldError(
			fieldName: $fieldName,
			code: 'required',
			reason: "{$fieldName} is vereist voor afleidingswijze \"{$afleidingswijze}\"."
		);
	}//end requiredError()

	/**
	 * The error for a field the afleidingswijze forbids but that is filled.
	 *
	 * @param string $fieldName       The full field path
	 * @param string $afleidingswijze The afleidingswijze value
	 *
	 * @return array{name: string, code: string, reason: string} The error
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function mustBeEmptyError(string $fieldName, string $afleidingswijze): array {
		return $this->fieldError(
			fieldName: $fieldName,
			code: 'must-be-empty',
			reason: "{$fieldName} mag niet ingevuld zijn voor afleidingswijze \"{$afleidingswijze}\"."
		);
	}//end mustBeEmptyError()

	/**
	 * Check afleidingswijze compatibility with selectielijstklasse.procestermijn (ztc-003).
	 *
	 * @param string $afleidingswijze The afleidingswijze value
	 * @param string|null $procestermijn The selectielijstklasse procestermijn value
	 *
	 * @return array|null Field error array, or null if compatible
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function checkProcestermijnCompatibility(
		string $afleidingswijze,
		?string $procestermijn,
	): ?array {
		$only = self::ONLY_AFLEIDINGSWIJZE_FOR_PROCESTERMIJN[$procestermijn ?? ''] ?? null;
		if ($only !== null && $afleidingswijze !== $only) {
			return $this->invalidForProcestermijnError(afleidingswijze: $afleidingswijze, procestermijn: $procestermijn);
		}

		if (($procestermijn === '' || $procestermijn === null) && $afleidingswijze === 'termijn') {
			return $this->procestermijnMissingError();
		}

		return null;
	}//end checkProcestermijnCompatibility()

	/**
	 * The error for an afleidingswijze the selectielijstklasse's procestermijn does not allow.
	 *
	 * @param string $afleidingswijze The afleidingswijze value
	 * @param string $procestermijn   The selectielijstklasse procestermijn value
	 *
	 * @return array{name: string, code: string, reason: string} The error
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function invalidForProcestermijnError(string $afleidingswijze, string $procestermijn): array {
		return $this->fieldError(
			fieldName: 'nonFieldErrors',
			code: 'invalid-afleidingswijze-for-procestermijn',
			reason: "Afleidingswijze \"{$afleidingswijze}\" is niet geldig"." bij selectielijstklasse met procestermijn \"{$procestermijn}\"."
		);
	}//end invalidForProcestermijnError()

	/**
	 * The error for afleidingswijze termijn against a selectielijstklasse without procestermijn.
	 *
	 * @return array{name: string, code: string, reason: string} The error
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	private function procestermijnMissingError(): array {
		$reason = 'brondatumArchiefprocedure.procestermijn is vereist voor'
			.' afleidingswijze "termijn" maar selectielijstklasse heeft geen procestermijn.';
		return $this->fieldError(
			fieldName: 'brondatumArchiefprocedure.procestermijn',
			code: 'required',
			reason: $reason
		);
	}//end procestermijnMissingError()
}//end class
