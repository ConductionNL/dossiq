<?php

/**
 * Dossiq Link In-Flight Contract Decisions Repair
 *
 * Migration repair step: for each open contract / besluitvorming case that
 * does NOT yet have a `decisionRef`, link it forward to a decidesk Decision
 * via the ContractDecisionDelegationService so its outcome can complete in
 * decidesk. Cases that already have a recorded ZGW `Besluit` are left as the
 * authoritative historical record — no Besluit data is dropped.
 *
 * Safe + idempotent: if the decidesk leaf is unavailable, the step warns and
 * skips (no case data is modified); it does not fail the migration.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ContractDecisionDelegationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\JsonEncodedStringPropertiesInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Links in-flight contract/besluitvorming cases forward to decidesk Decisions.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class LinkInFlightContractDecisionsRepair implements IRepairStep {

	use SearchesObjects;

	/**
	 * Case types that represent open contract/besluitvorming decisions.
	 *
	 * @var string[]
	 */
	private const CONTRACT_DECISION_CASE_TYPES = [
		'leverancier-contractverlenging-verzoek',
		'besluitvorming-college',
		'besluitvorming-raad',
		'besluitvorming-mandaat',
	];

	/**
	 * Per-case outcomes reported by linkCase(); each value doubles as the
	 * tally key in the run's counter map.
	 */
	private const RESULT_LINKED = 'linked';
	private const RESULT_SKIPPED = 'skipped';
	private const RESULT_ERROR = 'errors';
	private const RESULT_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param ContractDecisionDelegationServiceInterface $delegationService Decision delegation service.
	 * @param SettingsServiceInterface $settingsService Settings / ObjectService resolver.
	 * @param JsonEncodedStringPropertiesInterface $jsonProperties Restores the declared string shape of JSON-encoded properties.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContractDecisionDelegationServiceInterface $delegationService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly JsonEncodedStringPropertiesInterface $jsonProperties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function getName(): string {
		return 'Link in-flight Dossiq contract/besluitvorming cases to decidesk Decisions';
	}//end getName()

	/**
	 * Run the migration: link open cases forward without dropping Besluit data.
	 *
	 * @param IOutput $output The migration output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Linking in-flight contract/besluitvorming cases to decidesk Decisions...');

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister unavailable — skipping in-flight contract decision link.');
			return;
		}

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls run inside runAsSystem().
		$run = new InFlightDecisionLinkRun(objectService: $objectService, output: $output, logger: $this->logger);
		$this->runAsSystemIfAvailable(objectService: $objectService, operation: fn (): array => $this->linkCaseTypes(run: $run));

		$output->info($this->summaryOf(counts: $run->counts));
	}//end run()

	/**
	 * Link the cases of every contract decision case type, counting each outcome.
	 *
	 * @param InFlightDecisionLinkRun $run The run.
	 *
	 * @return array<string, int> The counts so far.
	 */
	private function linkCaseTypes(InFlightDecisionLinkRun $run): array {
		foreach (self::CONTRACT_DECISION_CASE_TYPES as $caseTypeSlug) {
			foreach ($this->casesOfType(run: $run, caseTypeSlug: $caseTypeSlug) as $case) {
				$run->count(
					outcome: $this->linkCase(run: $run, case: $case, caseTypeSlug: $caseTypeSlug)
				);
			}
		}

		return $run->counts;
	}//end linkCaseTypes()

	/**
	 * The cases of one case type; a list that fails is reported and reads as none.
	 *
	 * @param InFlightDecisionLinkRun $run          The run.
	 * @param string                  $caseTypeSlug The case type slug.
	 *
	 * @return array<int, array<string, mixed>> The case rows.
	 */
	private function casesOfType(InFlightDecisionLinkRun $run, string $caseTypeSlug): array {
		try {
			// ObjectService::findAll() takes a single $config array — the
			// previous named-argument call (register:/schema:/limit:) threw
			// "Unknown named parameter" on every run. Use the shared
			// slug-aware search bridge, which also normalises the rows to
			// the associative arrays this loop expects.
			return $this->searchObjectsAsArrays(
				objectService: $run->objectService,
				register: AppIdentity::REGISTER_SLUG,
				schema: 'case',
				filters: $this->caseTypeFilters(caseTypeSlug: $caseTypeSlug),
			);
		} catch (Throwable $e) {
			$run->listFailed(caseTypeSlug: $caseTypeSlug, error: $e);
			return [];
		}
	}//end casesOfType()

	/**
	 * The search filters for one case type's cases.
	 *
	 * @param string $caseTypeSlug The case type slug.
	 *
	 * @return array<string, mixed> The filters.
	 */
	private function caseTypeFilters(string $caseTypeSlug): array {
		return [
			'caseTypeSlug' => $caseTypeSlug,
			'_limit' => 500,
		];
	}//end caseTypeFilters()

	/**
	 * The closing line of a run.
	 *
	 * @param array<string, int> $counts How many cases ended in each outcome.
	 *
	 * @return string The line.
	 */
	private function summaryOf(array $counts): string {
		return sprintf(
			'Contract decision link complete: %d linked, %d skipped (already decided/historical), %d errors (leaf unavailable).',
			($counts[self::RESULT_LINKED] ?? 0),
			($counts[self::RESULT_SKIPPED] ?? 0),
			($counts[self::RESULT_ERROR] ?? 0)
		);
	}//end summaryOf()

	/**
	 * Link a single in-flight case forward to a decidesk Decision.
	 *
	 * @param InFlightDecisionLinkRun $run          The run: its object service and output.
	 * @param array<string, mixed>    $case         The case row.
	 * @param string                  $caseTypeSlug The dossiq case type slug.
	 *
	 * @return string One of the self::RESULT_* constants.
	 */
	private function linkCase(InFlightDecisionLinkRun $run, array $case, string $caseTypeSlug): string {
		$output = $run->output;
		$caseUuid = (string)($case['uuid'] ?? $case['id'] ?? '');
		$precheck = $this->precheckOf(case: $case);
		if ($precheck !== null) {
			return $precheck;
		}

		// Open case with no decision yet — link forward to decidesk.
		try {
			$newDecisionRef = $this->raiseAndPersistDecision(objectService: $run->objectService, case: $case, caseTypeSlug: $caseTypeSlug);
			$output->info('Linked case ' . $caseUuid . ' → decidesk Decision ' . $newDecisionRef);
		} catch (RuntimeException $e) {
			// Decidesk leaf unavailable — warn + skip this case; do NOT fail the migration.
			$this->reportNotLinked(output: $output, caseUuid: $caseUuid, error: $e);
			return self::RESULT_ERROR;
		}//end try

		return self::RESULT_LINKED;
	}//end linkCase()

	/**
	 * Report a case that could not be linked.
	 *
	 * @param IOutput $output The migration output interface.
	 * @param string $caseUuid The case uuid.
	 * @param RuntimeException $error Why it could not be linked.
	 *
	 * @return void
	 */
	private function reportNotLinked(IOutput $output, string $caseUuid, RuntimeException $error): void {
		$output->warning('Could not link case ' . $caseUuid . ': ' . $error->getMessage() . ' — skipping.');
		$this->logger->warning(
			'LinkInFlightContractDecisionsRepair: could not link case',
			['caseUuid' => $caseUuid, 'error' => $error->getMessage()]
		);
	}//end reportNotLinked()

	/**
	 * The outcome of a case that needs no link, or null when it must be linked.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string|null One of the self::RESULT_* constants, or null to link.
	 */
	private function precheckOf(array $case): ?string {
		$caseUuid = (string)($case['uuid'] ?? $case['id'] ?? '');
		$besluitRef = (string)($case['besluitRef'] ?? '');
		$decisionRef = (string)($case['decisionRef'] ?? '');
		$status = (string)($case['status'] ?? '');
		$isClosed = in_array($status, ['closed', 'handled', 'archived', 'closed'], true);

		if ($caseUuid === '') {
			return self::RESULT_NONE;
		}

		// REQ-PDCD-007: if a Besluit is already recorded, keep it as
		// the authoritative historical record — no link needed.
		if ($besluitRef !== '') {
			return self::RESULT_SKIPPED;
		}

		// Already linked to a decidesk Decision.
		if ($decisionRef !== '') {
			return self::RESULT_SKIPPED;
		}

		// Skip closed cases without a Besluit — they are historical,
		// do not create dangling Decisions in decidesk.
		if ($isClosed === true) {
			return self::RESULT_SKIPPED;
		}

		return null;
	}//end precheckOf()

	/**
	 * Raise a decidesk Decision for a case and persist its reference on the case.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $case          The case row.
	 * @param string               $caseTypeSlug  The dossiq case type slug.
	 *
	 * @return string The new decision reference.
	 *
	 * @throws RuntimeException When the decidesk leaf is unavailable.
	 */
	private function raiseAndPersistDecision(object $objectService, array $case, string $caseTypeSlug): string {
		$caseUuid = (string)($case['uuid'] ?? $case['id'] ?? '');
		$newDecisionRef = $this->delegationService->raiseContractDecision(
			caseRef: $caseUuid,
			contractRef: (string)($case['contractRef'] ?? ''),
			decisionType: $this->mapCaseTypeToDecisionType(caseTypeSlug: $caseTypeSlug),
			subject: [
				'subjectRegister' => AppIdentity::REGISTER_SLUG,
				'subjectSchema' => 'case',
				'subjectId' => $caseUuid,
				'subjectLabel' => (string)($case['title'] ?? $caseTypeSlug),
			],
			mandateContext: [],
		);

		// Persist the decisionRef on the case (does not alter the case outcome).
		// A loaded case carries `statusHistory`, `activity`, `geometry`
		// and `relatedCases` DECODED, and the schema still declares each
		// of them a string — a bare array_merge writes arrays into them
		// and OpenRegister refuses the whole save.
		$objectService->saveObject(
			object: $this->jsonProperties->mergeForWrite(
				stored: $case,
				updates: ['decisionRef' => $newDecisionRef],
				schemaSlug: 'case',
			),
			register: AppIdentity::REGISTER_SLUG,
			schema: 'case',
			uuid: $caseUuid,
		);

		return $newDecisionRef;
	}//end raiseAndPersistDecision()

	/**
	 * Map a dossiq case type slug to a decidesk decisionType.
	 *
	 * @param string $caseTypeSlug The dossiq case type slug.
	 *
	 * @return string The decidesk decisionType.
	 */
	private function mapCaseTypeToDecisionType(string $caseTypeSlug): string {
		return match ($caseTypeSlug) {
			'leverancier-contractverlenging-verzoek' => ContractDecisionDelegationServiceInterface::DECISION_TYPE_CONTRACT_RENEWAL,
			default => ContractDecisionDelegationServiceInterface::DECISION_TYPE_REPORT_ADOPTION,
		};
	}//end mapCaseTypeToDecisionType()
}//end class
