<?php

/**
 * Dossiq Woo collection
 *
 * The corpus of a Woo request as a record (woo-request-corpus-collection,
 * REQ-WRC-002 and REQ-WRC-003): what arrived per custodian and per system,
 * every candidate set aside before review with its reason, and the
 * reconciliation arrived = assessed + excluded + outstanding.
 *
 * What arrived is every document on the case plus every candidate that never
 * reached it (a duplicate, an unreadable pick). An excluded document stays on
 * the case but leaves the outstanding list.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use RuntimeException;

/**
 * Reports the collection and keeps its exclusions.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
 */
class WooCollection {

	use SearchesObjects;

	/**
	 * The exclusion's config key.
	 */
	public const EXCLUSION_SCHEMA = 'woo_exclusion_schema';

	/**
	 * The reasons a candidate can be set aside for.
	 */
	public const REASONS = ['duplicate', 'out-of-period', 'out-of-scope', 'unreadable'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService              $settingsService The register and the exclusion schema.
	 * @param WooCaseDocuments             $caseDocuments   Which documents are on the case.
	 * @param DocumentRecordStore          $store           A document's record and its join to the case.
	 * @param WOODocumentAssessmentService $assessments     Whether a document is assessed.
	 * @param WooSearchPlans               $plans           The plan's custodians and systems.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooCaseDocuments $caseDocuments,
		private readonly DocumentRecordStore $store,
		private readonly WOODocumentAssessmentService $assessments,
		private readonly WooSearchPlans $plans,
	) {
	}//end __construct()

	/**
	 * The collection report: per custodian, per system, the exclusions and the reconciliation.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> `{custodians, systems, exclusions, arrived, assessed, excluded, outstanding}`.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function report(string $caseId): array {
		$plan = ($this->plans->find(caseId: $caseId) ?? []);
		$custodians = array_fill_keys($this->plans->custodianNames(plan: $plan), $this->zero());
		$systems = array_fill_keys(array_map('strval', (array)($plan['systems'] ?? [])), $this->zero());

		$exclusions = $this->exclusions(caseId: $caseId);
		$excludedDocs = [];
		$neverArrived = 0;
		foreach ($exclusions as $exclusion) {
			$custodian = (string)($exclusion['custodian'] ?? '');
			if ($custodian !== '') {
				$custodians[$custodian] = ($custodians[$custodian] ?? $this->zero());
				$custodians[$custodian]['excluded']++;
			}

			$docRef = (string)($exclusion['documentRef'] ?? '');
			if ($docRef === '') {
				$neverArrived++;
				continue;
			}

			$excludedDocs[$docRef] = true;
		}

		$documentIds = $this->caseDocuments->idsFor(caseId: $caseId);
		$assessed = 0;
		foreach ($documentIds as $documentId) {
			$this->count(caseId: $caseId, documentId: $documentId, custodians: $custodians, systems: $systems);
			if (isset($excludedDocs[$documentId]) === false && $this->assessments->findAssessment(caseId: $caseId, documentRef: $documentId) !== null) {
				$assessed++;
			}
		}

		$excludedOnCase = count(array_intersect_key($excludedDocs, array_flip($documentIds)));
		$arrived = (count($documentIds) + $neverArrived);

		return [
			'custodians' => $this->listed(groups: $custodians, key: 'custodian'),
			'systems' => $this->listed(groups: $systems, key: 'system'),
			'exclusions' => $exclusions,
			'arrived' => $arrived,
			'assessed' => $assessed,
			'excluded' => ($excludedOnCase + $neverArrived),
			'outstanding' => (count($documentIds) - $excludedOnCase - $assessed),
		];
	}//end report()

	/**
	 * Set a document on the case aside before review.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $documentRef The document's uuid.
	 * @param string $reason      One of {@see self::REASONS}.
	 * @param string $note        What the handler wrote.
	 * @param string $userId      Who sets it aside.
	 *
	 * @return array<string, mixed> The exclusion as stored.
	 *
	 * @throws WooCorpusRefused 400 for an unknown reason, 404 for a document not on the case,
	 *                          409 for an assessed or already excluded document.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function exclude(string $caseId, string $documentRef, string $reason, string $note, string $userId): array {
		if (in_array($reason, self::REASONS, true) === false) {
			throw new WooCorpusRefused(reason: 'unknown_reason', status: 400);
		}

		if (in_array($documentRef, $this->caseDocuments->idsFor(caseId: $caseId), true) === false) {
			throw new WooCorpusRefused(reason: 'document_not_on_case', status: 404);
		}

		if ($this->assessments->findAssessment(caseId: $caseId, documentRef: $documentRef) !== null) {
			throw new WooCorpusRefused(reason: 'document_assessed', status: 409);
		}

		foreach ($this->exclusions(caseId: $caseId) as $exclusion) {
			if ((string)($exclusion['documentRef'] ?? '') === $documentRef) {
				throw new WooCorpusRefused(reason: 'document_excluded', status: 409);
			}
		}

		$provenance = $this->provenanceOf(caseId: $caseId, documentId: $documentRef);

		return $this->record(
			caseId: $caseId,
			fields: [
				'documentRef' => $documentRef,
				'custodian' => (string)($provenance['custodian'] ?? ''),
				'reason' => $reason,
				'note' => $note,
				'excludedBy' => $userId,
			]
		);
	}//end exclude()

	/**
	 * Record a candidate set aside before review.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $fields The exclusion's fields; `case` and `excludedAt` are set here.
	 *
	 * @return array<string, mixed> The exclusion as stored.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function record(string $caseId, array $fields): array {
		[$objectService, $register, $schema] = $this->target();
		$row = array_filter($fields, static fn (mixed $value): bool => $value !== '' && $value !== null);
		$row['case'] = $caseId;
		$row['excludedAt'] = gmdate('Y-m-d\TH:i:s\Z');

		$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema);
		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		return (array)$saved;
	}//end record()

	/**
	 * Every exclusion of the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The exclusions.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function exclusions(string $caseId): array {
		[$objectService, $register, $schema] = $this->target();
		$filters = ['case' => $caseId, '_limit' => 1000];
		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);

		return array_values(array_filter($rows, static fn (array $row): bool => (string)($row['case'] ?? '') === $caseId));
	}//end exclusions()

	/**
	 * Count one document into its custodian and its system.
	 *
	 * @param string                              $caseId     The case uuid.
	 * @param string                              $documentId The document uuid.
	 * @param array<string, array<string, int>>   $custodians The custodian totals, changed in place.
	 * @param array<string, array<string, int>>   $systems    The system totals, changed in place.
	 *
	 * @return void
	 */
	private function count(string $caseId, string $documentId, array &$custodians, array &$systems): void {
		$record = ($this->store->findRecord(recordId: $documentId) ?? []);
		$provenance = $this->provenanceOf(caseId: $caseId, documentId: $documentId, record: $record);
		$bytes = (int)($record['bestandsomvang'] ?? 0);

		$custodian = (string)($provenance['custodian'] ?? '');
		if ($custodian !== '') {
			$custodians[$custodian] = ($custodians[$custodian] ?? $this->zero());
			$custodians[$custodian]['documents']++;
			$custodians[$custodian]['bytes'] += $bytes;
		}

		$system = (string)($provenance['sourceSystem'] ?? ($provenance['source'] ?? ''));
		if ($system !== '') {
			$systems[$system] = ($systems[$system] ?? $this->zero());
			$systems[$system]['documents']++;
			$systems[$system]['bytes'] += $bytes;
		}
	}//end count()

	/**
	 * A document's provenance: on its record when it was copied, on this case's join when it was linked.
	 *
	 * @param string                    $caseId     The case uuid.
	 * @param string                    $documentId The document uuid.
	 * @param array<string, mixed>|null $record     The record when already read.
	 *
	 * @return array<string, mixed> The provenance, [] when there is none.
	 */
	private function provenanceOf(string $caseId, string $documentId, ?array $record = null): array {
		$record = ($record ?? $this->store->findRecord(recordId: $documentId) ?? []);
		if (is_array($record['provenance'] ?? null) === true) {
			return $record['provenance'];
		}

		foreach ($this->store->joinsFor(recordId: $documentId, caseId: $caseId) as $join) {
			if (is_array($join['provenance'] ?? null) === true) {
				return $join['provenance'];
			}
		}

		return [];
	}//end provenanceOf()

	/**
	 * An empty total.
	 *
	 * @return array{documents: int, bytes: int, excluded: int}
	 */
	private function zero(): array {
		return ['documents' => 0, 'bytes' => 0, 'excluded' => 0];
	}//end zero()

	/**
	 * Totals keyed by name as a list.
	 *
	 * @param array<string, array<string, int>> $groups The totals.
	 * @param string                            $key    The name's key in each row.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function listed(array $groups, string $key): array {
		$rows = [];
		foreach ($groups as $name => $totals) {
			$rows[] = array_merge([$key => (string)$name], $totals);
		}

		return $rows;
	}//end listed()

	/**
	 * OpenRegister, the register and the exclusion schema.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When they are not there.
	 */
	private function target(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::EXCLUSION_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The Woo exclusion schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end target()
}//end class
