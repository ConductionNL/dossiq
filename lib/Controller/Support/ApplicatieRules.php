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
		// Ac-003: Check scope-based field requirements (before uniqueness).
		// Ac-001: Check clientId uniqueness (after content validation).
		// The first rule that answers an error wins; the later ones do not run.
		return $this->validateAutorisatieConsistency(body: $body)
			?? $this->validateAutorisatieScopes(body: $body)
			?? $this->validateClientIdUniqueness(body: $body, excludeUuid: $excludeUuid);
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

		return $this->clientIdsError(clientIds: $clientIds, excludeUuid: $excludeUuid);
	}//end validateClientIdUniqueness()

	/**
	 * The error for a non-empty list of client ids: too many, or one already taken.
	 *
	 * @param array<int, mixed> $clientIds   The requested client ids.
	 * @param string|null       $excludeUuid UUID of the applicatie being updated.
	 *
	 * @return JSONResponse|null The error, or null when the list may be used.
	 */
	private function clientIdsError(array $clientIds, ?string $excludeUuid): ?JSONResponse {
		// Enforce a reasonable maximum to prevent DoS via large arrays.
		if (count($clientIds) > 100) {
			return $this->clientIdsParamError(
				code: 'max-items',
				reason: 'A maximum of 100 clientIds per applicatie is allowed.'
			);
		}

		return $this->clientIdTakenError(clientIds: $clientIds, excludeUuid: $excludeUuid);
	}//end clientIdsError()

	/**
	 * The error naming the first requested client id another applicatie already uses.
	 *
	 * @param array<int, mixed> $clientIds   The requested client ids.
	 * @param string|null       $excludeUuid UUID of the applicatie being updated.
	 *
	 * @return JSONResponse|null The error, or null when none is taken.
	 */
	private function clientIdTakenError(array $clientIds, ?string $excludeUuid): ?JSONResponse {
		$takenId = $this->firstTakenClientId(clientIds: $clientIds, excludeUuid: $excludeUuid);
		if ($takenId === null) {
			return null;
		}

		return $this->clientIdsParamError(
			code: 'clientId-exists',
			reason: "clientId \"{$takenId}\" is already used by another applicatie."
		);
	}//end clientIdTakenError()

	/**
	 * A 400 with one invalid parameter on `clientIds`.
	 *
	 * @param string $code   The error code.
	 * @param string $reason The reason.
	 *
	 * @return JSONResponse The response.
	 */
	private function clientIdsParamError(string $code, string $reason): JSONResponse {
		return $this->invalidParams(
			params: [
				[
					'name' => 'clientIds',
					'code' => $code,
					'reason' => $reason,
				],
			]
		);
	}//end clientIdsParamError()

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
			if ($this->isApplicatie(data: $data, uuid: $excludeUuid) === true) {
				continue;
			}

			$takenId = $this->firstHeldBy(consumer: $consumer, clientIds: $clientIds);
			if ($takenId !== null) {
				return $takenId;
			}
		}

		return null;
	}//end firstTakenClientId()

	/**
	 * The first requested client id the consumer already holds.
	 *
	 * @param object            $consumer  The stored consumer.
	 * @param array<int, mixed> $clientIds The requested client ids.
	 *
	 * @return mixed The first held id, or null when it holds none of them.
	 */
	private function firstHeldBy(object $consumer, array $clientIds): mixed {
		return $this->firstHeldClientId(
			clientIds: $clientIds,
			existingClientIds: (new ApplicatieRepresentation())->consumerClientIds(consumer: $consumer)
		);
	}//end firstHeldBy()

	/**
	 * Whether a serialised consumer is the applicatie with the given UUID.
	 *
	 * @param array<string, mixed> $data The serialised consumer.
	 * @param string|null          $uuid The UUID, or null when none is excluded.
	 *
	 * @return bool True when it is that applicatie.
	 */
	private function isApplicatie(array $data, ?string $uuid): bool {
		return $uuid !== null && ($data['uuid'] ?? '') === $uuid;
	}//end isApplicatie()

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

		return $this->ambiguousAutorisatiesError(heeftAlle: $heeftAlle, autorisaties: ($body['autorisaties'] ?? []))
			?? $this->missingAutorisatiesError(heeftAlle: $heeftAlle, body: $body);
	}//end validateAutorisatieConsistency()

	/**
	 * Ac-002a: heeftAlleAutorisaties=true together with a non-empty autorisaties list.
	 *
	 * @param mixed $heeftAlle    The normalised heeftAlleAutorisaties flag.
	 * @param mixed $autorisaties The autorisaties as sent.
	 *
	 * @return JSONResponse|null The error, or null when the two do not clash.
	 */
	private function ambiguousAutorisatiesError(mixed $heeftAlle, mixed $autorisaties): ?JSONResponse {
		if ($heeftAlle === true && is_array($autorisaties) === true && count($autorisaties) > 0) {
			// phpcs:ignore Generic.Files.LineLength.MaxExceeded
			$reason = $this->l10n->t('When heeftAlleAutorisaties is true, autorisaties must not be specified. When heeftAlleAutorisaties is false, autorisaties must be specified.');

			return $this->nonFieldError(code: 'ambiguous-authorizations-specified', reason: $reason);
		}

		return null;
	}//end ambiguousAutorisatiesError()

	/**
	 * Ac-002b: heeftAlleAutorisaties=false together with an empty autorisaties list.
	 *
	 * @param mixed                $heeftAlle The normalised heeftAlleAutorisaties flag.
	 * @param array<string, mixed> $body      The request body.
	 *
	 * @return JSONResponse|null The error, or null when autorisaties are given.
	 */
	private function missingAutorisatiesError(mixed $heeftAlle, array $body): ?JSONResponse {
		if ($heeftAlle === false && $this->declaresEmptyAutorisaties(body: $body) === true) {
			return $this->nonFieldError(
				code: 'missing-authorizations',
				reason: $this->l10n->t('When heeftAlleAutorisaties is false, autorisaties must be specified.')
			);
		}

		return null;
	}//end missingAutorisatiesError()

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
			array_push($invalidParams, ...$this->missingFieldParams(index: $index, autorisatie: $autorisatie));
		}

		if (count($invalidParams) > 0) {
			return $this->invalidParams(params: $invalidParams);
		}

		return null;
	}//end validateAutorisatieScopes()

	/**
	 * The invalid parameters for the fields one autorisatie lacks, named by its position.
	 *
	 * @param int|string $index       The autorisatie's position in the list.
	 * @param mixed      $autorisatie The autorisatie.
	 *
	 * @return array<int, array{name: string, code: string, reason: string}> The invalid parameters.
	 */
	private function missingFieldParams(int|string $index, mixed $autorisatie): array {
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
	}//end missingFieldParams()

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
