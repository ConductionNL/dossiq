<?php

/**
 * Dossiq autorisatie scope fields
 *
 * The fields a ZGW autorisatie must carry for its component and scopes
 * (VNG AC rule ac-003), read from one entry of an applicatie body without
 * a store.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\IL10N;

/**
 * Ac-003: the fields an autorisatie lacks for its component and scopes, as invalidParams.
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AutorisatieScopeFields {
	/**
	 * Ac-003: per component, the scope keyword that triggers the requirement
	 * and the fields it requires, in the order they are reported.
	 *
	 * 'source' is the body field read, 'name' the field named in the error.
	 *
	 * @var array<string, array{keyword: string, fields: array<int, array{source: string, name: string, reason: string}>}>
	 */
	private const SCOPE_REQUIREMENTS = [
		'zrc' => [
			'keyword' => 'zaken',
			'fields' => [
				[
					'source' => 'caseType',
					'name' => 'zaaktype',
					'reason' => 'zaaktype is required when a scope related to zaken is specified.',
				],
				[
					'source' => 'maxVertrouwelijkheidaanduiding',
					'name' => 'maxVertrouwelijkheidaanduiding',
					'reason' => 'maxVertrouwelijkheidaanduiding is required when a scope related to zaken is specified.',
				],
			],
		],
		'drc' => [
			'keyword' => 'documenten',
			'fields' => [
				[
					'source' => 'informatieobjecttype',
					'name' => 'informatieobjecttype',
					'reason' => 'informatieobjecttype is required when a scope related to documenten is specified.',
				],
				[
					'source' => 'maxVertrouwelijkheidaanduiding',
					'name' => 'maxVertrouwelijkheidaanduiding',
					// phpcs:ignore Generic.Files.LineLength.TooLong
					'reason' => 'maxVertrouwelijkheidaanduiding is required when a scope related to documenten is specified.',
				],
			],
		],
		'brc' => [
			'keyword' => 'besluiten',
			'fields' => [
				[
					'source' => 'besluittype',
					'name' => 'besluittype',
					'reason' => 'besluittype is required when a scope related to besluiten is specified.',
				],
			],
		],
	];

	/**
	 * The requirement of a component ac-003 sets no rule for.
	 *
	 * @var array{keyword: string, fields: array<int, mixed>}
	 */
	private const NO_SCOPE_REQUIREMENT = [
		'keyword' => '',
		'fields' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the reason of each missing field.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The invalid parameters for the fields one autorisatie lacks, named by its position.
	 *
	 * @param int|string $index       The autorisatie's position in the list.
	 * @param mixed      $autorisatie The autorisatie.
	 *
	 * @return array<int, array{name: string, code: string, reason: string}> The invalid parameters.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function missingParams(int|string $index, mixed $autorisatie): array {
		$params = [];
		$missing = $this->missingRequiredFields(
			autorisatie: $autorisatie,
			fields: $this->requiredFieldsFor(autorisatie: $autorisatie)
		);
		foreach ($missing as $param) {
			$param['name'] = "autorisaties.{$index}.{$param['name']}";
			$params[] = $param;
		}

		return $params;
	}//end missingParams()

	/**
	 * The fields one autorisatie must carry for its component and scopes (ac-003).
	 *
	 * Ac-003a/003b: ZRC with a zaken-related scope; ac-003c/003d: DRC with a
	 * documenten-related scope; ac-003e: BRC with a besluiten-related scope.
	 * Any other component requires nothing.
	 *
	 * @param mixed $autorisatie One entry of the autorisaties list
	 *
	 * @return array The required fields, as in SCOPE_REQUIREMENTS
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function requiredFieldsFor(mixed $autorisatie): array {
		$component = $autorisatie['component'] ?? '';
		$scopes = $autorisatie['scopes'] ?? [];

		$requirement = self::NO_SCOPE_REQUIREMENT;
		if (is_string($component) === true && isset(self::SCOPE_REQUIREMENTS[$component]) === true) {
			$requirement = self::SCOPE_REQUIREMENTS[$component];
		}

		// Check if any scope relates to the component's domain.
		if ($this->scopesContain(scopes: $scopes, keyword: $requirement['keyword']) === false) {
			return [];
		}

		return $requirement['fields'];
	}//end requiredFieldsFor()

	/**
	 * List the required fields an autorisatie leaves empty, as invalidParams.
	 *
	 * The name is the field name only; the caller prefixes the entry's index.
	 *
	 * @param mixed $autorisatie One entry of the autorisaties list
	 * @param array $fields      The required fields, as in SCOPE_REQUIREMENTS
	 *
	 * @return array The invalidParams entries, in field order
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function missingRequiredFields(mixed $autorisatie, array $fields): array {
		$missing = [];
		foreach ($fields as $field) {
			$value = $autorisatie[$field['source']] ?? null;
			if ($value === null || $value === '') {
				$missing[] = [
					'name' => $field['name'],
					'code' => 'required',
					'reason' => $this->l10n->t($field['reason']),
				];
			}
		}

		return $missing;
	}//end missingRequiredFields()

	/**
	 * Check whether any scope in the array contains the given keyword.
	 *
	 * @param array $scopes The scopes array.
	 * @param string $keyword The keyword to search for (e.g. 'zaken', 'documenten').
	 *
	 * @return bool True if any scope contains the keyword.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function scopesContain(array $scopes, string $keyword): bool {
		foreach ($scopes as $scope) {
			if (is_string($scope) === true && str_contains($scope, $keyword) === true) {
				return true;
			}
		}

		return false;
	}//end scopesContain()
}//end class
