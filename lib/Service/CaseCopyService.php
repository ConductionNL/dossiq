<?php

/**
 * Dossiq Case Copy Service.
 *
 * Copies a CASE — not a case type. `CaseTypeCopyService` beside it deep-copies
 * a definition and every child it owns; this one copies one live case into a
 * new case of the same type, in that type's initial status.
 *
 * The field list is FIXED and written out in full, rather than derived by
 * subtracting a deny-list from whatever the source happens to carry. A case
 * schema property added later is then absent from the copy until somebody
 * decides it belongs there, which is the safe direction: the fields this must
 * never carry over are the ones that identify the source as a legal fact — its
 * number, its deadline, its result, its status history, its decisions and its
 * publications — and a deny-list silently stops covering a field the day it is
 * renamed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Copies one case into a new case of the same type.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseCopyService {

	/**
	 * The properties a copy carries over from its source.
	 *
	 * `title` is not here: the caller names the copy, and the dialog proposes
	 * "Copy of <title>" rather than silently reusing a title that is already
	 * on another case.
	 *
	 * @var array<int, string>
	 */
	private const CARRIED = [
		'description',
		'caseType',
		'requester',
		'initiatorType',
		'initiatorSourceId',
		'initiatorDisplayName',
		'confidentiality',
		'priority',
		'intakeChannel',
		'properties',
	];

	/**
	 * The properties a copy must NEVER carry, asserted rather than assumed.
	 *
	 * Read by {@see self::copy()} to strip them after the carried list is
	 * built, so the two lists cannot drift into overlapping, and named here so
	 * the unit test can assert the ban rather than restate it.
	 *
	 * @var array<int, string>
	 */
	public const NEVER_COPIED = [
		'identifier',
		'deadline',
		'result',
		'statusHistory',
		'decisions',
		'publications',
		'endDate',
		'publishedAt',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OpenRegister register/schema resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Copy a case.
	 *
	 * @param string $caseId The source case's OpenRegister id.
	 * @param array{title?: string, documents?: bool} $options The copy's title, and whether to link the source's documents.
	 *
	 * @return array<string, mixed> The new case.
	 *
	 * @throws RuntimeException `storage_unavailable` when OpenRegister is absent or unconfigured, `case_not_found` when the source does not resolve, `copy_failed` when the write is refused.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function copy(string $caseId, array $options = []): array {
		$context = $this->context();
		$source = $this->fetch(
			objectService: $context['objectService'],
			register: $context['register'],
			schema: $context['caseSchema'],
			id: $caseId
		);

		if ($source === null) {
			throw new RuntimeException('case_not_found');
		}

		$payload = $this->buildPayload(source: $source, caseId: $caseId, options: $options);

		try {
			$created = $this->toArray(
				value: $context['objectService']->saveObject(
					object: $payload,
					register: $context['register'],
					schema: $context['caseSchema'],
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseCopyService: could not write the copy',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			throw new RuntimeException('copy_failed');
		}

		$newId = (string)($created['id'] ?? '');
		if ($newId === '') {
			throw new RuntimeException('copy_failed');
		}

		$linked = 0;
		if (($options['documents'] ?? false) === true) {
			$linked = $this->linkDocuments(
				objectService: $context['objectService'],
				register: $context['register'],
				sourceId: $caseId,
				newId: $newId
			);
		}

		$this->logger->info(
			'CaseCopyService: copied a case',
			['source' => $caseId, 'copy' => $newId, 'documents' => $linked]
		);

		$created['documentsLinked'] = $linked;

		return $created;
	}//end copy()

	/**
	 * The payload the copy is written from.
	 *
	 * @param array<string, mixed> $source The source case.
	 * @param string $caseId The source case's id.
	 * @param array{title?: string, documents?: bool} $options The caller's options.
	 *
	 * @return array<string, mixed> The new case's properties.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function buildPayload(array $source, string $caseId, array $options): array {
		$payload = [];
		foreach (self::CARRIED as $field) {
			if (array_key_exists($field, $source) === true && $source[$field] !== null) {
				$payload[$field] = $source[$field];
			}
		}

		foreach (self::NEVER_COPIED as $field) {
			unset($payload[$field]);
		}

		$payload['title'] = $this->titleFor(source: $source, options: $options);
		$payload['startDate'] = date('Y-m-d');

		// `status` is deliberately absent rather than set: `caseType` carries an
		// x-openregister-prefill block (status <- initialStatus) and that is the
		// one write path for a new case's status. Setting it here would be a
		// second, and the two would disagree the day a case type's initial
		// status moves.
		$payload['relatedCases'] = $this->relationTo(caseId: $caseId);

		return $payload;
	}//end buildPayload()

	/**
	 * The copy's title.
	 *
	 * @param array<string, mixed> $source The source case.
	 * @param array{title?: string} $options The caller's options.
	 *
	 * @return string The title.
	 */
	private function titleFor(array $source, array $options): string {
		$given = trim((string)($options['title'] ?? ''));
		if ($given !== '') {
			return $given;
		}

		return 'Copy of ' . (string)($source['title'] ?? '');
	}//end titleFor()

	/**
	 * The `relatedCases` value naming the source.
	 *
	 * `relatedCases` is a JSON-ENCODED string of typed relations
	 * (`{caseId, aardRelatie, toelichting}`), not an array of ids — the schema
	 * says so and CaseRelationCodec reads it that way. Writing an array here
	 * would store a shape nothing reads and the Related cases tab would be
	 * empty on every copy.
	 *
	 * @param string $caseId The source case's id.
	 *
	 * @return string The encoded relation list.
	 */
	private function relationTo(string $caseId): string {
		return (string)json_encode(
			[
				[
					'caseId' => $caseId,
					'aardRelatie' => 'vervolg',
					'toelichting' => 'Copied from this case',
				],
			]
		);
	}//end relationTo()

	/**
	 * Link the source's documents to the copy, by reference.
	 *
	 * A `caseDocument` row is a LINK between a case and a document URI, so a
	 * new row pointing at the same URI puts the document on the copy without
	 * a second file existing anywhere. The rows are read as the calling user,
	 * so a document the copier may not read is not among them and is therefore
	 * never linked onto a case they can write.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug.
	 * @param string $sourceId The source case's id.
	 * @param string $newId The copy's id.
	 *
	 * @return integer How many documents were linked.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function linkDocuments(object $objectService, string $register, string $sourceId, string $newId): int {
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if ($schema === '') {
			return 0;
		}

		$linked = 0;
		foreach ($this->documentsOf($objectService, $register, $schema, $sourceId) as $link) {
			$document = (string)($link['document'] ?? '');
			if ($document === '') {
				continue;
			}

			try {
				$objectService->saveObject(
					object: [
						'case' => $newId,
						'document' => $document,
						'title' => (string)($link['title'] ?? ''),
						'description' => (string)($link['description'] ?? ''),
						'registrationDate' => date('Y-m-d'),
					],
					register: $register,
					schema: $schema,
				);
				$linked++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'CaseCopyService: could not link a document onto the copy',
					['case' => $newId, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return $linked;
	}//end linkDocuments()

	/**
	 * The source case's document links.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The caseDocument schema.
	 * @param string $caseId The source case's id.
	 *
	 * @return array<int, array<string, mixed>> The link rows.
	 */
	private function documentsOf(object $objectService, string $register, string $schema, string $caseId): array {
		try {
			$results = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'case' => $caseId,
					],
					'limit' => 200,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseCopyService: could not read the source case documents',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($results) === true && isset($results['results']) === true) {
			$results = $results['results'];
		}

		if (is_array($results) === false) {
			return [];
		}

		return array_map(fn ($row): array => $this->toArray(value: $row), $results);
	}//end documentsOf()

	/**
	 * The OpenRegister seam and the two slugs this service writes through.
	 *
	 * @return array{objectService: object, register: string, caseSchema: string} The resolved context.
	 *
	 * @throws RuntimeException `storage_unavailable` when OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		if ($objectService === null || $register === '' || $caseSchema === '') {
			throw new RuntimeException('storage_unavailable');
		}

		return ['objectService' => $objectService, 'register' => $register, 'caseSchema' => $caseSchema];
	}//end context()

	/**
	 * Fetch one object, tolerating a fail-closed miss.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function fetch(object $objectService, string $register, string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseCopyService: object lookup failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($found === null) {
			return null;
		}

		return $this->toArray(value: $found);
	}//end fetch()

	/**
	 * Normalise an OpenRegister entity (or array) into a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string, mixed> The array form.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($value) === true) {
			return (array)$value;
		}

		return [];
	}//end toArray()
}//end class
