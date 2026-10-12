<?php

/**
 * Dossiq Connected System Case Writer.
 *
 * Carries out a case operation a connected system asked for: open a case,
 * change a case, add a document to a case. The operation arrives already
 * mapped onto generic keys by the adapter that received it (integriq, for
 * StUF-ZDS: decision 206), so nothing here knows a wire format.
 *
 * Every write runs as the background service account with OpenRegister's
 * access rules ON. A connection that did not opt in, or an instance without a
 * usable service account, is refused before anything is read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-connected-system-writes-as-the-service-account
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Opens and changes cases, and files documents, for a connected system.
 *
 * @psalm-type WriteOutcome = array{identifier?: string, refusal?: string, kind?: string}
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-connected-system-writes-as-the-service-account
 */
class ConnectedSystemCaseWriter {

	use SearchesObjects;

	/**
	 * The operation is understood and is not carried out.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * The connection, or this instance, does not let the system write.
	 *
	 * @var string
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * The case the operation names is not there.
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not-found';

	/**
	 * The case the operation opens is already there.
	 *
	 * @var string
	 */
	public const CONFLICT = 'conflict';

	/**
	 * The fields a connected system may change on a case that exists.
	 *
	 * Descriptive only. Type, status, handler, dates the term hangs on and
	 * everything that decides who reads the case stay dossiq's to set.
	 *
	 * @var array<int, string>
	 */
	private const CHANGEABLE = ['title', 'description', 'plannedEndDate'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings  Register and schema resolution.
	 * @param BackgroundServiceAccount  $account   The identity the write runs as.
	 * @param CaseTypeReferenceResolver $caseTypes Resolves a mapped case type.
	 * @param ConnectedSystemDocumentFiler $documents Files a document through the screened upload path.
	 * @param LoggerInterface           $logger    The logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly BackgroundServiceAccount $account,
		private readonly CaseTypeReferenceResolver $caseTypes,
		private readonly ConnectedSystemDocumentFiler $documents,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Carry out one operation, or say why not.
	 *
	 * @param array<string, mixed> $operation  The generic operation: `action` (create, update, add-document) and its fields.
	 * @param array<string, mixed> $connection What the connection allows: `acceptsWrites`, `caseTypes` (case type reference to the
	 *                                         system's name for it) and `documentTypes` (the system's name to a document type id).
	 * @param string               $system     The id of the connection the operation came in on.
	 *
	 * @return array The outcome (WriteOutcome): `identifier` when done, `refusal` and `kind` when not.
	 *
	 * @throws RuntimeException When the register is not configured or the store fails: nothing was decided.
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-connected-system-writes-as-the-service-account
	 */
	public function carryOut(array $operation, array $connection, string $system): array {
		if (($connection['acceptsWrites'] ?? false) !== true || $system === '') {
			return self::refusal(kind: self::FORBIDDEN, reason: 'Deze koppeling neemt geen wijzigingen aan.');
		}

		$status = $this->account->status();
		if ($status['usable'] !== true) {
			$this->logger->warning(
				'ConnectedSystemCaseWriter: a write was refused because the background service account cannot be used',
				['system' => $system, 'reason' => (string)$status['reason']]
			);
			return self::refusal(kind: self::FORBIDDEN, reason: 'Dossiq heeft geen serviceaccount om voor een gekoppeld systeem te schrijven.');
		}

		$action = (string)($operation['action'] ?? '');
		return $this->account->runAs(
			operation: fn (): array => match ($action) {
				'create' => $this->create(operation: $operation, connection: $connection, system: $system),
				'update' => $this->update(operation: $operation, system: $system),
				'add-document' => $this->addDocument(operation: $operation, connection: $connection, system: $system),
				default => self::refusal(kind: self::REFUSED, reason: 'Deze bewerking wordt niet uitgevoerd.'),
			}
		);
	}//end carryOut()

	/**
	 * Open a case.
	 *
	 * @param array<string, mixed> $operation  The operation.
	 * @param array<string, mixed> $connection What the connection allows.
	 * @param string               $system     The connection id.
	 *
	 * @return array The outcome (WriteOutcome).
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-case-opened-by-a-connected-system-keeps-its-origin
	 */
	private function create(array $operation, array $connection, string $system): array {
		$external = self::text(value: ($operation['identifier'] ?? ''));
		$title    = self::text(value: ($operation['title'] ?? ''));
		if ($external === '' || $title === '') {
			return self::refusal(kind: self::REFUSED, reason: 'Een zaak heeft een identificatie en een omschrijving nodig.');
		}

		$caseType = $this->caseTypeFor(operation: $operation, mapping: (array)($connection['caseTypes'] ?? []));
		if ($caseType === []) {
			return self::refusal(kind: self::REFUSED, reason: 'Het zaaktype is niet gekoppeld aan een zaaktype in dossiq.');
		}

		[$objects, $register, $schema] = $this->store();
		if ($this->findOwnCase(identifier: $external, system: $system) !== null) {
			return self::refusal(kind: self::CONFLICT, reason: 'Deze zaak bestaat al.');
		}

		$initiator = (array)($operation['initiator'] ?? []);
		$case      = array_filter(
			[
				'title' => $title,
				'description' => self::text(value: ($operation['description'] ?? '')),
				'caseType' => $this->caseTypes->idOf(caseType: $caseType),
				'status' => $this->caseTypes->initialStatusOf(caseType: $caseType),
				'startDate' => self::text(value: ($operation['startDate'] ?? date('Y-m-d'))),
				'plannedEndDate' => self::text(value: ($operation['plannedEndDate'] ?? '')),
				'intakeChannel' => 'other',
				'initiatorType' => self::text(value: ($initiator['type'] ?? '')),
				'initiatorSourceId' => self::text(value: ($initiator['sourceId'] ?? '')),
				'initiatorDisplayName' => self::text(value: ($initiator['name'] ?? '')),
				'originSystem' => $system,
				'originIdentifier' => $external,
			],
			static fn (string $value): bool => $value !== ''
		);

		$saved = $this->saveObjectAsArray(objectService: $objects, register: $register, schema: $schema, object: $case);
		if ($saved === null) {
			throw new RuntimeException('The case was written but could not be read back.');
		}

		$this->logger->info('ConnectedSystemCaseWriter: a connected system opened a case', ['system' => $system, 'origin' => $external]);

		$number = self::text(value: ($saved['identifier'] ?? ''));
		if ($number === '') {
			$number = $external;
		}

		return ['identifier' => $number];
	}//end create()

	/**
	 * Change the descriptive fields of a case.
	 *
	 * @param array<string, mixed> $operation The operation.
	 * @param string               $system    The connection id.
	 *
	 * @return array The outcome (WriteOutcome).
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-connected-system-changes-descriptive-fields-only
	 */
	private function update(array $operation, string $system): array {
		$case = $this->findCase(identifier: self::text(value: ($operation['identifier'] ?? '')), system: $system);
		if ($case === null) {
			return self::refusal(kind: self::NOT_FOUND, reason: 'Deze zaak is niet bekend.');
		}

		$changes = [];
		foreach (self::CHANGEABLE as $field) {
			$value = self::text(value: ($operation[$field] ?? ''));
			if ($value !== '' && $value !== self::text(value: ($case[$field] ?? ''))) {
				$changes[$field] = $value;
			}
		}

		if ($changes === []) {
			return self::refusal(kind: self::REFUSED, reason: 'Het bericht wijzigt niets dat een gekoppeld systeem mag wijzigen.');
		}

		[$objects, $register, $schema] = $this->store();
		$this->patchObjectAsArray(objectService: $objects, register: $register, schema: $schema, id: self::idOf(row: $case), changes: $changes);

		return ['identifier' => self::text(value: ($case['identifier'] ?? ''))];
	}//end update()

	/**
	 * File a document on a case, through the screened upload path.
	 *
	 * @param array<string, mixed> $operation  The operation.
	 * @param array<string, mixed> $connection What the connection allows.
	 * @param string               $system     The connection id.
	 *
	 * @return array The outcome (WriteOutcome).
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-document-from-a-connected-system-takes-the-upload-path
	 */
	private function addDocument(array $operation, array $connection, string $system): array {
		$case = $this->findCase(identifier: self::text(value: ($operation['caseIdentifier'] ?? '')), system: $system);
		if ($case === null) {
			return self::refusal(kind: self::NOT_FOUND, reason: 'De zaak van dit document is niet bekend.');
		}

		return $this->documents->file(operation: $operation, documentTypes: (array)($connection['documentTypes'] ?? []), case: $case, system: $system);
	}//end addDocument()

	/**
	 * The case a connected system names.
	 *
	 * A case the system opened is known by the identifier the system gave it.
	 * Any other case is known by dossiq's own case number.
	 *
	 * @param string $identifier The identifier in the message.
	 * @param string $system     The connection id.
	 *
	 * @return array<string, mixed>|null The case, null when there is none.
	 */
	private function findCase(string $identifier, string $system): ?array {
		$own = $this->findOwnCase(identifier: $identifier, system: $system);
		if ($own !== null || $identifier === '') {
			return $own;
		}

		return $this->firstCaseWith(filters: ['identifier' => $identifier]);
	}//end findCase()

	/**
	 * The case this system opened under this identifier.
	 *
	 * @param string $identifier The identifier the system gave the case.
	 * @param string $system     The connection id.
	 *
	 * @return array<string, mixed>|null The case, null when there is none.
	 */
	private function findOwnCase(string $identifier, string $system): ?array {
		if ($identifier === '') {
			return null;
		}

		return $this->firstCaseWith(filters: ['originSystem' => $system, 'originIdentifier' => $identifier]);
	}//end findOwnCase()

	/**
	 * The first case carrying every one of these values.
	 *
	 * @param array<string, string> $filters Case property to value.
	 *
	 * @return array<string, mixed>|null The case, null when there is none.
	 */
	private function firstCaseWith(array $filters): ?array {
		[$objects, $register, $schema] = $this->store();
		$rows = $this->searchObjectsAsArrays(objectService: $objects, register: $register, schema: $schema, filters: ($filters + ['_limit' => 5]));
		foreach ($rows as $row) {
			// Compared again on the row: a search that ignores an unknown filter answers every case.
			$same = true;
			foreach ($filters as $property => $value) {
				$same = ($same && self::text(value: ($row[$property] ?? '')) === $value);
			}

			if ($same === true) {
				return $row;
			}
		}

		return null;
	}//end firstCaseWith()

	/**
	 * The dossiq case type the connection maps the system's zaaktype to.
	 *
	 * @param array<string, mixed>  $operation The operation: `caseTypeName`, `caseTypeCode`.
	 * @param array<string, string> $mapping   Case type reference to the system's name or code for it.
	 *
	 * @return array<string, mixed> The case type row, [] when unmapped or unknown.
	 */
	private function caseTypeFor(array $operation, array $mapping): array {
		$names = array_filter(
			[self::text(value: ($operation['caseTypeName'] ?? '')), self::text(value: ($operation['caseTypeCode'] ?? ''))],
			static fn (string $name): bool => $name !== ''
		);
		foreach ($mapping as $reference => $theirs) {
			foreach ($names as $name) {
				if (strcasecmp(trim((string)$theirs), $name) === 0) {
					return $this->caseTypes->resolve(reference: (string)$reference);
				}
			}
		}

		return [];
	}//end caseTypeFor()

	/**
	 * The object service, register and case schema.
	 *
	 * @return array{0: object, 1: string, 2: string} The three.
	 *
	 * @throws RuntimeException When the register is not configured.
	 */
	private function store(): array {
		$objects  = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema   = $this->settings->getConfigValue('case_schema');
		if ($objects === null || $register === '' || $schema === '') {
			throw new RuntimeException('The case register is not configured.');
		}

		return [$objects, $register, $schema];
	}//end store()

	/**
	 * A refusal outcome.
	 *
	 * @param string $kind   One of the kind constants.
	 * @param string $reason The reason, safe to return to the system.
	 *
	 * @return array The outcome (WriteOutcome).
	 */
	private static function refusal(string $kind, string $reason): array {
		return ['refusal' => $reason, 'kind' => $kind];
	}//end refusal()

	/**
	 * A scalar as trimmed text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text, '' for anything that is not a scalar.
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * The uuid of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid.
	 */
	private static function idOf(array $row): string {
		return self::text(value: ($row['@self']['id'] ?? ($row['id'] ?? '')));
	}//end idOf()
}//end class
