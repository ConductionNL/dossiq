<?php

/**
 * ZRC field rules of a zaak
 *
 * The rules a zaak body must pass on create, update and patch, in the order the
 * standard lists them (zrc-002, zrc-010 to zrc-015, zrc-022). The first refusal
 * wins. Each rule is one method that answers a refusal or null.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwRulesBase;
use Psr\Log\LoggerInterface;

/**
 * Sequences the field rules of a zaak and owns the ones that read only the body.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcCaseFieldRules extends ZgwRulesBase {
	/**
	 * The rules that read another object (hoofdzaak, products).
	 *
	 * @var ZrcCaseRelationRules
	 */
	private ZrcCaseRelationRules $relations;

	/**
	 * Constructor.
	 *
	 * Takes what every rules class takes and builds its relation rules from the
	 * same three, so the two always share one logger, settings and validator.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param SettingsService $settingsService The settings service.
	 * @param FieldValidator $fieldValidator The field validator.
	 */
	public function __construct(LoggerInterface $logger, SettingsService $settingsService, FieldValidator $fieldValidator) {
		parent::__construct(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
		$this->relations = new ZrcCaseRelationRules(logger: $logger, settingsService: $settingsService, fieldValidator: $fieldValidator);
	}//end __construct()

	/**
	 * Set the request context here and on the relation rules.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService.
	 * @param array|null $mappingConfig The mapping configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		parent::setContext(objectService: $objectService, mappingConfig: $mappingConfig);
		$this->relations->setContext(objectService: $objectService, mappingConfig: $mappingConfig);
	}//end setContext()

	/**
	 * Common zaak field validation for create/update/patch.
	 *
	 * Implements, in this order (the first refusal wins):
	 * - zrc-002: Identificatie immutability on update/patch.
	 * - zrc-010: Validate communicatiekanaal URL.
	 * - zrc-011: Validate relevanteAndereZaken URLs.
	 * - zrc-012: Validate gegevensgroepen (opschorting, verlenging).
	 * - zrc-013: Validate hoofdzaak URL.
	 * - zrc-014: Validate betalingsindicatie + laatsteBetaaldatum consistency.
	 * - zrc-015: Validate productenOfDiensten subset of zaaktype.
	 * - zrc-022: Validate archiefstatus transition requires archiefnominatie + archiefactiedatum.
	 *
	 * Each rule is its own method returning a refusal or null, so this method only sequences
	 * them (method-decomposition).
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function validateCaseFields(array $result, ?array $existingObject): array {
		$body = $result['enrichedBody'];

		$error = $this->checkIdentificatieImmutable(body: $body, existingObject: $existingObject)
			?? $this->checkCommunicatiekanaal(body: $body)
			?? $this->checkRelevanteAndereZaken(body: $body)
			?? $this->checkGegevensgroep(
				group: $body['suspension'] ?? null,
				required: [
					'indicatie' => ['opschorting.indicatie', 'Indicatie is vereist bij opschorting.', true],
					'reason'    => ['opschorting.reden', 'Reden is vereist bij opschorting.', false],
				],
				detail: 'Opschorting vereist indicatie en reden.'
			)
			?? $this->checkGegevensgroep(
				group: $body['verlenging'] ?? null,
				required: [
					'reason' => ['verlenging.reden', 'Reden is vereist bij verlenging.', false],
					'duur'   => ['verlenging.duur', 'Duur is vereist bij verlenging.', false],
				],
				detail: 'Verlenging vereist reden en duur.'
			)
			?? $this->relations->checkHoofdzaak(body: $body, existingObject: $existingObject);
		if ($error !== null) {
			return $error;
		}

		// Zrc-014: refuses on create, clears the date on update/patch.
		$payment = $this->applyBetalingsindicatie(body: $body, existingObject: $existingObject);
		if ($payment['error'] !== null) {
			return $payment['error'];
		}

		$body  = $payment['body'];
		$error = $this->relations->validateProductenOfDiensten(body: $body) ?? $this->checkArchiefstatus(body: $body);
		if ($error !== null) {
			return $error;
		}

		$result['enrichedBody'] = $body;

		return $result;
	}//end validateCaseFields()

	/**
	 * Zrc-002: refuse a changed identificatie on update/patch.
	 *
	 * @param array $body The request body
	 * @param array|null $existingObject The existing zaak, null on create
	 *
	 * @return array|null The refusal, or null when acceptable
	 */
	private function checkIdentificatieImmutable(array $body, ?array $existingObject): ?array {
		if ($existingObject === null || isset($body['identificatie']) === false) {
			return null;
		}

		$existingId = $existingObject['identifier'] ?? ($existingObject['identificatie'] ?? '');
		if ($existingId !== '' && $body['identificatie'] !== $existingId) {
			return $this->fieldImmutableError(fieldName: 'identificatie');
		}

		return null;
	}//end checkIdentificatieImmutable()

	/**
	 * Zrc-010: refuse a communicatiekanaal that is no URL, or no URL of a resource.
	 *
	 * @param array $body The request body
	 *
	 * @return array|null The refusal, or null when acceptable
	 */
	private function checkCommunicatiekanaal(array $body): ?array {
		$commChannel = $body['communicatiekanaal'] ?? null;
		if ($commChannel === null || $commChannel === '') {
			return null;
		}

		if (filter_var($commChannel, FILTER_VALIDATE_URL) === false) {
			return $this->error(
				status: 400,
				detail: 'De communicatiekanaal URL is ongeldig.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'communicatiekanaal',
						code: 'bad-url',
						reason: 'De communicatiekanaal URL is ongeldig.'
					),
				]
			);
		}

		if ($this->isValidUrl(url: $commChannel) === false) {
			// Zrc-010: URL is syntactically valid but does not point to a specific
			// resource (no UUID path segment) → VNG requires 'invalid-resource'.
			return $this->error(
				status: 400,
				detail: 'De communicatiekanaal URL is ongeldig.',
				invalidParams: [
					$this->fieldError(
						fieldName: 'communicatiekanaal',
						code: 'invalid-resource',
						reason: 'De communicatiekanaal URL wijst niet naar een geldig object.'
					),
				]
			);
		}

		return null;
	}//end checkCommunicatiekanaal()

	/**
	 * Zrc-011: refuse a relevanteAndereZaken entry whose url is no resource URL.
	 *
	 * @param array $body The request body
	 *
	 * @return array|null The refusal naming the entry's index, or null when acceptable
	 */
	private function checkRelevanteAndereZaken(array $body): ?array {
		$relevanteCases = $body['relevanteAndereZaken'] ?? null;
		if (is_array($relevanteCases) === false) {
			return null;
		}

		foreach ($relevanteCases as $idx => $relCase) {
			$relUrl = $relCase['url'] ?? '';
			if ($relUrl !== '' && $this->isValidUrl(url: $relUrl) === false) {
				return $this->error(
					status: 400,
					detail: 'relevanteAndereZaken bevat een ongeldige URL.',
					invalidParams: [$this->fieldError(
						fieldName: "relevanteAndereZaken.{$idx}.url",
						code: 'bad-url',
						reason: 'De URL is ongeldig.'
					)
					]
				);
			}
		}

		return null;
	}//end checkRelevanteAndereZaken()

	/**
	 * Zrc-012: a gegevensgroep (opschorting, verlenging) needs all of its required fields.
	 *
	 * @param mixed $group The gegevensgroep from the body; anything but an array is skipped
	 * @param array<string, array{0: string, 1: string, 2: bool}> $required Body key => [field name, reason, null-only]
	 * @param string $detail The error detail
	 *
	 * @return array|null The refusal listing every missing field, or null when acceptable
	 */
	private function checkGegevensgroep(mixed $group, array $required, string $detail): ?array {
		if (is_array($group) === false) {
			return null;
		}

		$errors = [];
		foreach ($required as $key => [$fieldName, $reason, $nullOnly]) {
			// The opschorting indicatie is a boolean: only a missing one counts, false is an answer.
			$missing = (($group[$key] ?? '') === '');
			if ($nullOnly === true) {
				$missing = (($group[$key] ?? null) === null);
			}

			if ($missing === true) {
				$errors[] = $this->fieldError(fieldName: $fieldName, code: 'required', reason: $reason);
			}
		}

		if (empty($errors) === true) {
			return null;
		}

		return $this->error(status: 400, detail: $detail, invalidParams: $errors);
	}//end checkGegevensgroep()

	/**
	 * Zrc-014: betalingsindicatie "nvt" and a laatsteBetaaldatum do not go together.
	 *
	 * On create the pair is refused. On update/patch the stored values count when the body
	 * leaves them out, and switching to "nvt" clears the date instead of refusing.
	 *
	 * @param array $body The request body
	 * @param array|null $existingObject The existing zaak, null on create
	 *
	 * @return array{error: array|null, body: array} The refusal, or the (possibly cleared) body
	 */
	private function applyBetalingsindicatie(array $body, ?array $existingObject): array {
		$betalingsindicatie = $body['betalingsindicatie'] ?? null;
		$lastPaid           = $body['laatsteBetaaldatum'] ?? null;

		// On update/patch, also consider existing values when not explicitly sent.
		if ($existingObject !== null) {
			$betalingsindicatie ??= $existingObject['paymentIndication'] ?? ($existingObject['betalingsindicatie'] ?? null);
			$lastPaid           ??= $existingObject['lastPaymentDate'] ?? ($existingObject['laatsteBetaaldatum'] ?? null);
		}

		if ($betalingsindicatie !== 'nvt' || $lastPaid === null || $lastPaid === '') {
			return ['error' => null, 'body' => $body];
		}

		// On create: reject (cannot set date with nvt).
		if ($existingObject === null) {
			$error = $this->error(
				status: 400,
				detail: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.',
				invalidParams: [$this->fieldError(
					fieldName: 'laatsteBetaaldatum',
					code: 'betaling-nvt',
					reason: 'Als betalingsindicatie "nvt" is, mag laatsteBetaaldatum niet gezet worden.'
				)
				]
			);
			return ['error' => $error, 'body' => $body];
		}

		// On update/patch: clear laatsteBetaaldatum when switching to nvt.
		$body['laatsteBetaaldatum'] = null;

		return ['error' => null, 'body' => $body];
	}//end applyBetalingsindicatie()

	/**
	 * Zrc-022: an archiefstatus past nog_te_archiveren needs archiefnominatie and archiefactiedatum.
	 *
	 * @param array $body The request body
	 *
	 * @return array|null The refusal for the first missing field, or null when acceptable
	 */
	private function checkArchiefstatus(array $body): ?array {
		$archiefstatus = $body['archiefstatus'] ?? null;
		if ($archiefstatus === null || $archiefstatus === 'nog_te_archiveren') {
			return null;
		}

		foreach (['archiefnominatie', 'archiefactiedatum'] as $field) {
			if (empty($body[$field] ?? null) === true) {
				return $this->error(
					status: 400,
					detail: $field.' is vereist als archiefstatus niet "nog_te_archiveren" is.',
					invalidParams: [$this->fieldError(fieldName: $field, code: $field.'-not-set', reason: 'Vereist.')]
				);
			}
		}

		return null;
	}//end checkArchiefstatus()
}//end class
