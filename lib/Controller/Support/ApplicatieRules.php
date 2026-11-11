<?php

/**
 * Dossiq applicatie rules
 *
 * The VNG AC business rules a ZGW applicatie body is validated against
 * before it is saved as an OpenRegister consumer.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;

/**
 * Validates applicatie bodies against the AC business rules.
 *
 * - ac-001: ClientId uniqueness across applicaties
 * - ac-002: heeftAlleAutorisaties consistency with autorisaties array
 * - ac-003: Scope-based field requirements per component
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */
final class ApplicatieRules {
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
	 * @param ZgwService $zgwService The shared ZGW service, for the consumer mapper.
	 * @param IL10N      $l10n       The localization service.
	 */
	public function __construct(
		private readonly ZgwService $zgwService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Validate an applicatie request body against AC business rules.
	 *
	 * Checks ac-001 (clientId uniqueness), ac-002 (heeftAlleAutorisaties
	 * consistency), and ac-003 (scope-based field requirements).
	 *
	 * @param array $body The request body.
	 * @param string|null $excludeUuid UUID to exclude from uniqueness check (for updates).
	 *
	 * @return JSONResponse|null Validation error response or null if valid.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function validateApplicatieBody(array $body, ?string $excludeUuid = null): ?JSONResponse {
		// Ac-002: Check heeftAlleAutorisaties consistency (before uniqueness).
		$authConsistencyError = $this->validateAutorisatieConsistency(body: $body);
		if ($authConsistencyError !== null) {
			return $authConsistencyError;
		}

		// Ac-003: Check scope-based field requirements (before uniqueness).
		$scopeError = $this->validateAutorisatieScopes(body: $body);
		if ($scopeError !== null) {
			return $scopeError;
		}

		// Ac-001: Check clientId uniqueness (after content validation).
		$clientIdError = $this->validateClientIdUniqueness(body: $body, excludeUuid: $excludeUuid);
		if ($clientIdError !== null) {
			return $clientIdError;
		}

		return null;
	}//end validateApplicatieBody()

	/**
	 * Validate that clientIds are not already used by another applicatie (ac-001).
	 *
	 * @param array $body The request body.
	 * @param string|null $excludeUuid UUID to exclude from check (for updates).
	 *
	 * @return JSONResponse|null Error response or null if valid.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function validateClientIdUniqueness(array $body, ?string $excludeUuid = null): ?JSONResponse {
		$clientIds = $body['clientIds'] ?? [];
		if (is_array($clientIds) === false || count($clientIds) === 0) {
			return null;
		}

		// Enforce a reasonable maximum to prevent DoS via large arrays.
		if (count($clientIds) > 100) {
			return $this->invalidParams(
				params: [
					[
						'name' => 'clientIds',
						'code' => 'max-items',
						'reason' => 'A maximum of 100 clientIds per applicatie is allowed.',
					],
				]
			);
		}

		$takenId = $this->firstTakenClientId(clientIds: $clientIds, excludeUuid: $excludeUuid);
		if ($takenId === null) {
			return null;
		}

		return $this->invalidParams(
			params: [
				[
					'name' => 'clientIds',
					'code' => 'clientId-exists',
					'reason' => "clientId \"{$takenId}\" is already used by another applicatie.",
				],
			]
		);
	}//end validateClientIdUniqueness()

	/**
	 * Find the first requested clientId another consumer already holds.
	 *
	 * Consumers are walked in stored order and, per consumer, the requested
	 * clientIds in request order.
	 *
	 * @param array       $clientIds   The requested clientIds
	 * @param string|null $excludeUuid UUID to skip (the consumer being updated)
	 *
	 * @return mixed The clientId already in use, or null when all are free
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function firstTakenClientId(array $clientIds, ?string $excludeUuid): mixed {
		$allConsumers = $this->zgwService->getConsumerMapper()->findAll();

		foreach ($allConsumers as $consumer) {
			$data = $consumer->jsonSerialize();

			// Skip self when updating.
			if ($excludeUuid !== null && ($data['uuid'] ?? '') === $excludeUuid) {
				continue;
			}

			$takenId = $this->firstHeldClientId(
				clientIds: $clientIds,
				existingClientIds: (new ApplicatieRepresentation())->consumerClientIds(consumer: $consumer)
			);
			if ($takenId !== null) {
				return $takenId;
			}
		}

		return null;
	}//end firstTakenClientId()

	/**
	 * Find the first requested clientId that appears among a consumer's clientIds.
	 *
	 * @param array $clientIds         The requested clientIds
	 * @param array $existingClientIds The clientIds one consumer holds
	 *
	 * @return mixed The overlapping clientId, or null when there is none
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function firstHeldClientId(array $clientIds, array $existingClientIds): mixed {
		foreach ($clientIds as $requestedId) {
			if (in_array($requestedId, $existingClientIds, true) === true) {
				return $requestedId;
			}
		}

		return null;
	}//end firstHeldClientId()

	/**
	 * Validate heeftAlleAutorisaties consistency with autorisaties (ac-002).
	 *
	 * If heeftAlleAutorisaties=true, autorisaties must be empty.
	 * If heeftAlleAutorisaties=false, autorisaties must be non-empty.
	 *
	 * @param array $body The request body.
	 *
	 * @return JSONResponse|null Error response or null if valid.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function validateAutorisatieConsistency(array $body): ?JSONResponse {
		$heeftAlle = $this->normaliseFlag(value: $body['heeftAlleAutorisaties'] ?? false);
		$autorisaties = $body['autorisaties'] ?? [];

		// Ac-002a: heeftAlleAutorisaties=true + non-empty autorisaties.
		if ($heeftAlle === true && is_array($autorisaties) === true && count($autorisaties) > 0) {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$reason = $this->l10n->t('When heeftAlleAutorisaties is true, autorisaties must not be specified. When heeftAlleAutorisaties is false, autorisaties must be specified.');

			return $this->nonFieldError(code: 'ambiguous-authorizations-specified', reason: $reason);
		}

		// Ac-002b: heeftAlleAutorisaties=false + empty autorisaties.
		if ($heeftAlle === false && $this->declaresEmptyAutorisaties(body: $body) === true) {
			return $this->nonFieldError(
				code: 'missing-authorizations',
				reason: $this->l10n->t('When heeftAlleAutorisaties is false, autorisaties must be specified.')
			);
		}

		return null;
	}//end validateAutorisatieConsistency()

	/**
	 * Normalise the string and integer spellings of a boolean flag.
	 *
	 * 'true', '1' and 1 become true; 'false', '0' and 0 become false; any other
	 * value is returned as given.
	 *
	 * @param mixed $value The flag as sent
	 *
	 * @return mixed The normalised flag
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function normaliseFlag(mixed $value): mixed {
		if (in_array($value, ['true', '1', 1], true) === true) {
			return true;
		}

		if (in_array($value, ['false', '0', 0], true) === true) {
			return false;
		}

		return $value;
	}//end normaliseFlag()

	/**
	 * Whether the body sends autorisaties as an explicitly empty list.
	 *
	 * @param array $body The request body
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function declaresEmptyAutorisaties(array $body): bool {
		$autorisaties = $body['autorisaties'] ?? [];

		return is_array($autorisaties) === true
			&& count($autorisaties) === 0
			&& array_key_exists('autorisaties', $body) === true;
	}//end declaresEmptyAutorisaties()

	/**
	 * Build a 400 for a rule that spans fields (nonFieldErrors).
	 *
	 * @param string $code   The error code
	 * @param string $reason The translated reason
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function nonFieldError(string $code, string $reason): JSONResponse {
		return $this->invalidParams(
			params: [
				[
					'name' => 'nonFieldErrors',
					'code' => $code,
					'reason' => $reason,
				],
			]
		);
	}//end nonFieldError()

	/**
	 * Validate autorisatie entries have required fields based on component and scope (ac-003).
	 *
	 * For zrc with scope containing "zaken": requires zaaktype and maxVertrouwelijkheidaanduiding.
	 * For drc with scope containing "documenten": requires informatieobjecttype and maxVertrouwelijkheidaanduiding.
	 * For brc with scope containing "besluiten": requires besluittype.
	 *
	 * @param array $body The request body.
	 *
	 * @return JSONResponse|null Error response or null if valid.
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	private function validateAutorisatieScopes(array $body): ?JSONResponse {
		$autorisaties = $body['autorisaties'] ?? [];
		if (is_array($autorisaties) === false) {
			return null;
		}

		$invalidParams = [];

		foreach ($autorisaties as $index => $autorisatie) {
			$missing = $this->missingRequiredFields(
				autorisatie: $autorisatie,
				fields: $this->requiredFieldsFor(autorisatie: $autorisatie)
			);
			foreach ($missing as $param) {
				$param['name'] = "autorisaties.{$index}.{$param['name']}";
				$invalidParams[] = $param;
			}
		}

		if (count($invalidParams) > 0) {
			return $this->invalidParams(params: $invalidParams);
		}

		return null;
	}//end validateAutorisatieScopes()

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

	/**
	 * Build a ZGW validation error response.
	 *
	 * @param array $params     The invalidParams entries
	 * @param int   $statusCode The HTTP status
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-autorisaties-api/spec.md
	 */
	public function invalidParams(array $params, int $statusCode=Http::STATUS_BAD_REQUEST): JSONResponse {
		return new JSONResponse(
			data: ['invalidParams' => $params],
			statusCode: $statusCode
		);
	}//end invalidParams()
}//end class
