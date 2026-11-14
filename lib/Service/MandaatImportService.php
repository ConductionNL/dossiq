<?php

/**
 * Dossiq MandaatImportService.
 *
 * Imports a MandateringsBesluit + its Mandaten from a CSV payload
 * (header: mandaatNummer,omschrijving,rolNaam,plafondCents,subdelegatie,
 * wettelijkeGrondslag,decisionTypes). Resolves rolNaam to an
 * OrganisatieRol id; missing roles abort the import with a clear
 * error. Returns a diff vs the prior version of the same besluit.
 *
 * Excel (.xlsx) parsing is intentionally out of scope (PhpSpreadsheet
 * would pull in 4 MB of vendor code); the import controller may pre-
 * convert .xlsx to .csv via openconnector before posting.
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
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\MandaatImportServiceInterface;
use OCA\Dossiq\Service\Mandaat\MandaatCsvParser;
use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * CSV import of a MandateringsBesluit from a Decidesk export.
 *
 * The wire format is parsed by {@see MandaatCsvParser} and every register read
 * or write goes through {@see MandaatRepository}; what stays here is the import
 * decision — new vs changed vs removed — and the approval state machine.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
 */
class MandaatImportService implements MandaatImportServiceInterface {
	use SearchesObjects;

	/**
	 * Columns an import CSV must carry.
	 *
	 * Canonically owned by {@see MandaatCsvParser}; aliased here so existing
	 * callers of `MandaatImportService::REQUIRED_COLUMNS` keep working.
	 *
	 * @var string[]
	 */
	public const REQUIRED_COLUMNS = MandaatCsvParser::REQUIRED_COLUMNS;

	/**
	 * Constructor.
	 *
	 * @param MandaatRepository $repository OpenRegister access for the mandaat matrix.
	 * @param MandaatCsvParser $csvParser Decidesk CSV export parser.
	 */
	public function __construct(
		private readonly MandaatRepository $repository,
		private readonly MandaatCsvParser $csvParser,
	) {
	}//end __construct()

	/**
	 * Import a mandate decision from CSV text.
	 *
	 * @param string $decisionNumber Decision identifier.
	 * @param string $decisionName Decision name.
	 * @param string $decideskUuid Source Decidesk decision id.
	 * @param string $csvContents The CSV payload (RFC 4180; first row is header).
	 *
	 * @return array<string, mixed> carrying mandateDecisionId, totalMandaten, newCount, changedCount, removedCount and diff.
	 *
	 * @throws RuntimeException When the CSV is malformed or a rol cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function importFromCsv(string $decisionNumber, string $decisionName, string $decideskUuid, string $csvContents): array {
		$rows = $this->parsedRows(csvContents: $csvContents);

		// Resolve rol-name → rolId.
		$resolved = $this->resolveRoleReferences(rows: $rows);

		$decision = $this->saveDraftDecision(decisionNumber: $decisionNumber, decisionName: $decisionName, decideskUuid: $decideskUuid);

		$tally = $this->tallyFor(decision: $decision, decisionNumber: $decisionNumber);

		// Create one mandaat per CSV row.
		foreach ($resolved as $row) {
			$this->importRow(tally: $tally, row: $row);
		}

		return $this->importSummary(tally: $tally, resolved: $resolved);
	}//end importFromCsv()

	/**
	 * The data rows of the CSV.
	 *
	 * @param string $csvContents The CSV payload (RFC 4180; first row is header).
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When the CSV has no data rows.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function parsedRows(string $csvContents): array {
		$rows = $this->csvParser->parse(csv: $csvContents);
		if (count($rows) === 0) {
			throw new RuntimeException('CSV is empty or missing data rows');
		}

		return $rows;
	}//end parsedRows()

	/**
	 * Create the decision as a concept.
	 *
	 * @param string $decisionNumber Decision identifier.
	 * @param string $decisionName Decision name.
	 * @param string $decideskUuid Source Decidesk decision id.
	 *
	 * @return array<string, mixed> The stored decision.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function saveDraftDecision(string $decisionNumber, string $decisionName, string $decideskUuid): array {
		// Create the decision (concept). The schemaConfigKey stays
		// 'mandaterings_besluit_schema': it is the app-config key the schema id
		// is already stored under on existing installs, not a display name.
		return $this->repository->save(
			schemaConfigKey: 'mandaterings_besluit_schema',
			object: [
				'decisionNumber' => $decisionNumber,
				'decisionName' => $decisionName,
				'status' => 'draft',
				'decideskUuid' => $decideskUuid,
			]
		);
	}//end saveDraftDecision()

	/**
	 * A tally for the new decision, against the mandates of its prior version.
	 *
	 * @param array<string, mixed> $decision The stored decision.
	 * @param string $decisionNumber Decision identifier.
	 *
	 * @return MandateImportTally The tally.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function tallyFor(array $decision, string $decisionNumber): MandateImportTally {
		$tally = new MandateImportTally();
		$tally->decisionId = (string)$decision['id'];
		$tally->prior = $this->priorMandatesOf(decisionNumber: $decisionNumber);

		return $tally;
	}//end tallyFor()

	/**
	 * The mandates of the prior decision version with this number, for the diff.
	 *
	 * @param string $decisionNumber The decision number.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function priorMandatesOf(string $decisionNumber): array {
		$prior = $this->repository->findPriorDecision(decisionNumber: $decisionNumber);
		if ($prior === null) {
			return [];
		}

		return $this->repository->findMandatenForBesluit(
			decisionId: (string)($prior['id'] ?? '')
		);
	}//end priorMandatesOf()

	/**
	 * Store the mandate of one CSV row and add its change to the tally.
	 *
	 * @param MandateImportTally   $tally The import so far.
	 * @param array<string, mixed> $row   The resolved CSV row.
	 *
	 * @return void
	 */
	private function importRow(MandateImportTally $tally, array $row): void {
		$payload = $this->buildMandatePayload(row: $row, decisionId: $tally->decisionId);

		$this->repository->save(schemaConfigKey: 'mandaat_schema', object: $payload);

		$existing = $this->findPriorMandate(
			priorMandaten: $tally->prior,
			mandateNumber: (string)$row['mandaatNummer']
		);

		$this->tallyChange(tally: $tally, entry: $this->changeOf(existing: $existing, payload: $payload));
	}//end importRow()

	/**
	 * The diff entry of one mandate: new, changed with its fields, or unchanged.
	 *
	 * @param array<string, mixed>|null $existing The prior mandate with this number, or null.
	 * @param array<string, mixed>      $payload  The mandate as imported.
	 *
	 * @return array<string, mixed>
	 */
	private function changeOf(?array $existing, array $payload): array {
		$number = (string)$payload['mandateNumber'];
		if ($existing === null) {
			return ['mandaatNummer' => $number, 'change' => 'NEW'];
		}

		$changedFields = $this->collectChangedFields(existing: $existing, payload: $payload);
		if (count($changedFields) > 0) {
			return [
				'mandaatNummer' => $number,
				'change' => 'CHANGED',
				'fields' => $changedFields,
			];
		}

		return ['mandaatNummer' => $number, 'change' => 'UNCHANGED'];
	}//end changeOf()

	/**
	 * Add one diff entry to the tally and count it.
	 *
	 * @param MandateImportTally   $tally The import so far.
	 * @param array<string, mixed> $entry The diff entry.
	 *
	 * @return void
	 */
	private function tallyChange(MandateImportTally $tally, array $entry): void {
		$tally->diff[] = $entry;
		if ($entry['change'] === 'NEW') {
			$tally->newCount++;
			return;
		}

		if ($entry['change'] === 'CHANGED') {
			$tally->changedCount++;
			return;
		}

		$tally->unchangedCount++;
	}//end tallyChange()

	/**
	 * The import answer: the counts and the diff, the removed mandates last.
	 *
	 * @param MandateImportTally               $tally    The finished import.
	 * @param array<int, array<string, mixed>> $resolved The resolved CSV rows.
	 *
	 * @return array<string, mixed>
	 */
	private function importSummary(MandateImportTally $tally, array $resolved): array {
		// REMOVED = in prior, not in new.
		$removed = $this->collectRemovedMandaten(priorMandaten: $tally->prior, resolved: $resolved);

		return [
			'mandateDecisionId' => $tally->decisionId,
			'totalMandaten' => count($resolved),
			'newCount' => $tally->newCount,
			'changedCount' => $tally->changedCount,
			'removedCount' => count($removed),
			'unchangedCount' => $tally->unchangedCount,
			'diff' => array_merge($tally->diff, $removed),
		];
	}//end importSummary()

	/**
	 * Resolve every CSV row's rolNaam to a gemandateerdeRol id.
	 *
	 * @param array<int, array<string, string>> $rows Parsed CSV data rows.
	 *
	 * @return array<int, array<string, mixed>> Rows enriched with gemandateerdeRol.
	 *
	 * @throws RuntimeException When a row has no rolNaam or names an unknown OrganisatieRol.
	 */
	private function resolveRoleReferences(array $rows): array {
		$roleIndex = $this->repository->loadRoleIndex();
		$resolved = [];
		foreach ($rows as $idx => $row) {
			$roleName = (string)($row['rolNaam'] ?? '');
			if ($roleName === '') {
				throw new RuntimeException('Row ' . ($idx + 1) . ' missing rolNaam');
			}

			if (isset($roleIndex[$roleName]) === false) {
				throw new RuntimeException('Unknown OrganisatieRol "' . $roleName . '" at row ' . ($idx + 1));
			}

			$resolved[] = $row + ['mandateeRole' => $roleIndex[$roleName]];
		}

		return $resolved;
	}//end resolveRolReferences()

	/**
	 * Build the concept mandaat payload for a single resolved CSV row.
	 *
	 * @param array<string, mixed> $row A resolved CSV row.
	 * @param string $decisionId The owning mandate decision id.
	 *
	 * @return array<string, mixed> The mandaat object payload.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function buildMandatePayload(array $row, string $decisionId): array {
		return [
			// Key = schema property (renamed). Value = CSV column header, an
			// external input contract that stays as operators' files write it.
			'mandateNumber' => (string)$row['mandaatNummer'],
			'mandateDecision' => $decisionId,
			// Key = schema property (renamed). Value = CSV column header, an
			// external input contract that stays as operators' files write it.
			'description' => (string)($row['description'] ?? $row['omschrijving'] ?? ''),
			'mandateeRole' => (string)$row['mandateeRole'],
			// Key = the schema property (renamed). Value = a CSV COLUMN HEADER,
			// which is an external input format the operator's file already
			// uses, so both spellings are read.
			'legalBasis' => (string)($row['legalBasis'] ?? $row['wettelijkeGrondslag'] ?? ''),
			'terms' => [
				'plafondCents' => (int)($row['plafondCents'] ?? 0),
				'subdelegatie' => $this->csvParser->parseBool(value: (string)($row['subdelegatie'] ?? 'false')),
				'decisionTypes' => $this->csvParser->parseList(value: (string)($row['decisionTypes'] ?? '')),
			],
			'status' => 'draft',
		];
	}//end buildMandaatPayload()

	/**
	 * Find the prior-version mandaat carrying a given mandaatNummer.
	 *
	 * @param array<int, array<string, mixed>> $priorMandaten Mandaten of the prior besluit version.
	 * @param string $mandateNumber The mandaat number to look for.
	 *
	 * @return array<string, mixed>|null The matching prior mandaat, or null when it is new.
	 */
	private function findPriorMandate(array $priorMandaten, string $mandateNumber): ?array {
		foreach ($priorMandaten as $pm) {
			if ((string)($pm['mandateNumber'] ?? '') === $mandateNumber) {
				return $pm;
			}
		}

		return null;
	}//end findPriorMandaat()

	/**
	 * Collect the field names that differ between a prior mandaat and its new payload.
	 *
	 * @param array<string, mixed> $existing The prior-version mandaat.
	 * @param array<string, mixed> $payload The freshly built mandaat payload.
	 *
	 * @return array<int, string> Changed field names; empty when unchanged.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	private function collectChangedFields(array $existing, array $payload): array {
		$changedFields = [];
		foreach (['description', 'mandateeRole', 'legalBasis'] as $f) {
			if ((string)($existing[$f] ?? '') !== (string)$payload[$f]) {
				$changedFields[] = $f;
			}
		}

		$exPlafond = (int)(($existing['terms'] ?? [])['plafondCents'] ?? 0);
		if ($exPlafond !== (int)$payload['terms']['plafondCents']) {
			$changedFields[] = 'plafondCents';
		}

		return $changedFields;
	}//end collectChangedFields()

	/**
	 * Build the REMOVED diff entries — mandaten present in the prior besluit but
	 * absent from the new import.
	 *
	 * @param array<int, array<string, mixed>> $priorMandaten Mandaten of the prior besluit version.
	 * @param array<int, array<string, mixed>> $resolved The resolved CSV rows of the new import.
	 *
	 * @return array<int, array<string, string>> REMOVED diff entries.
	 */
	private function collectRemovedMandaten(array $priorMandaten, array $resolved): array {
		$newNumbers = array_map(static fn (array $r): string => (string)($r['mandaatNummer'] ?? ''), $resolved);
		$removed = [];
		foreach ($priorMandaten as $pm) {
			$num = (string)($pm['mandateNumber'] ?? '');
			if ($num !== '' && in_array($num, $newNumbers, true) === false) {
				$removed[] = ['mandaatNummer' => $num, 'change' => 'REMOVED'];
			}
		}

		return $removed;
	}//end collectRemovedMandaten()

	/**
	 * Approve a concept besluit: flip besluit → vastgesteld + every mandaat → active,
	 * and mark the prior besluit (if any) → vervallen.
	 *
	 * @param string $decisionId Besluit id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function approveImport(string $decisionId): array {
		$run = new MandateApproval();
		$run->context = $this->repository->resolveApprovalContext();
		$run->decisionId = $decisionId;
		$run->decision = $this->draftDecision(run: $run);
		$run->now = (new DateTimeImmutable())->format('Y-m-d');

		$this->determineDecision(run: $run);

		// Flip mandaten to active.
		$this->repository->activateMandatenForBesluit(
			objectService: $run->context['objectService'],
			register: $run->context['register'],
			mSchema: $run->context['mSchema'],
			decisionId: $decisionId,
			now: $run->now
		);

		$this->lapsePriorDecision(run: $run);

		return $run->decision;
	}//end approveImport()

	/**
	 * The decision being approved, which must exist and still be a concept.
	 *
	 * @param MandateApproval $run The approval.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the decision is missing or not in concept status.
	 */
	private function draftDecision(MandateApproval $run): array {
		try {
			$decision = $this->findObjectAsArray(
				objectService: $run->context['objectService'],
				register: $run->context['register'],
				schema: $run->context['bSchema'],
				id: $run->decisionId
			);
		} catch (\Throwable $e) {
			$decision = null;
		}

		if ($decision === null) {
			throw new RuntimeException('Besluit not found: ' . $run->decisionId);
		}

		if (($decision['status'] ?? '') !== 'draft') {
			throw new RuntimeException('Besluit is not in concept status');
		}

		return $decision;
	}//end draftDecision()

	/**
	 * Store the decision as determined, in force from today unless it names a day.
	 *
	 * @param MandateApproval $run The approval; it then holds the stored decision.
	 *
	 * @return void
	 */
	private function determineDecision(MandateApproval $run): void {
		$decision = $run->decision;
		$decision['status'] = 'determined';
		$decision['effectiveFrom'] = ($decision['effectiveFrom'] ?? $run->now);
		$run->decision = ($this->saveObjectAsArray(
			objectService: $run->context['objectService'],
			register: $run->context['register'],
			schema: $run->context['bSchema'],
			object: $decision
		) ?? $decision);
	}//end determineDecision()

	/**
	 * Expire the prior decision with the same number, when there is one.
	 *
	 * @param MandateApproval $run The approval.
	 *
	 * @return void
	 */
	private function lapsePriorDecision(MandateApproval $run): void {
		$prior = $this->repository->findPriorDecision(
			decisionNumber: (string)$run->decision['decisionNumber'],
			excludeId: $run->decisionId
		);
		if ($prior === null) {
			return;
		}

		$prior['status'] = 'lapsed';
		$prior['expiryDate'] = $run->now;
		$this->saveObjectAsArray(
			objectService: $run->context['objectService'],
			register: $run->context['register'],
			schema: $run->context['bSchema'],
			object: $prior
		);
	}//end lapsePriorDecision()
}//end class
