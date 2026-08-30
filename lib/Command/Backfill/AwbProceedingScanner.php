<?php

/**
 * Dossiq Awb proceeding scanner.
 *
 * Answers one question for the legal-hold backfill: which cases still have at
 * least one OPEN Awb proceeding (bezwaar/objection/beroep)? A proceeding is
 * OPEN when no terminal decision references it — backfilling a hold on a
 * concluded case would fabricate retention history rather than repair it.
 *
 * Split out of BackfillLegalHoldsCommand so the command keeps only configure()
 * and execute(): the read side (which schemas open a proceeding, which
 * decisions close one, how a scan failure is reported rather than swallowed)
 * lives here, and the write side lives in {@see LegalHoldApplier}.
 *
 * @category Command
 * @package  OCA\Dossiq\Command\Backfill
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command\Backfill;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds the cases that still carry an open Awb proceeding.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class AwbProceedingScanner {
	/**
	 * The register the Awb schemas live in.
	 *
	 * @var string
	 */
	// FROZEN: OpenRegister register SLUG, not this app's id. Unchanged by the
	// procest -> dossiq rename — a renamed value resolves no register and the
	// scanner would list zero objects while reporting success.
	private const REGISTER = 'dossiq';

	/**
	 * Proceeding schemas whose existence opens an Awb proceeding.
	 *
	 * `beroep` is included here even though the listener itself does not act on
	 * it: an appeal suspends destruction exactly as an objection does, and a
	 * case sitting in beroep with no hold is the same compliance defect. The
	 * gap in the listener is reported separately on procest#694.
	 *
	 * @var array<int, string>
	 */
	private const OPENING_SCHEMAS = ['objectionProceeding', 'objection', 'beroep'];

	/**
	 * Scan failures collected while listing objects, reported by the caller.
	 *
	 * @var array<int, string>
	 */
	private array $scanErrors = [];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterRowNormaliser $normaliser Tolerant findAll() row normaliser.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OpenRegisterRowNormaliser $normaliser,
	) {
	}//end __construct()

	/**
	 * Collect the cases that have at least one open Awb proceeding.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param bool $includeDeleted Whether soft-deleted objects are in scope.
	 * @param OutputInterface $output Console output.
	 *
	 * @return array<string, array<string, mixed>> Candidate cases keyed by case UUID.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function scan(object $objectService, bool $includeDeleted, OutputInterface $output): array {
		$closedObjection = $this->terminatedProceedingIds(objectService: $objectService, schema: 'bezwaarDecision');
		$closedAppeal = $this->terminatedProceedingIds(objectService: $objectService, schema: 'appealDecision');
		$closed = array_merge($closedObjection, $closedAppeal);

		// Report the closed set explicitly. An empty set here silently disables
		// the "only hold OPEN proceedings" rule, which is the difference between
		// a targeted repair and holding every case that ever saw a proceeding —
		// exactly the kind of inert safety check this whole programme is about.
		$output->writeln(
			'  terminal decisions found: ' . count($closed)
			. ' (bezwaarDecision=' . count($closedObjection) . ', appealDecision=' . count($closedAppeal) . ')'
		);

		$candidates = [];

		foreach (self::OPENING_SCHEMAS as $schemaSlug) {
			$proceedings = $this->listObjects(
				objectService: $objectService,
				schema: $schemaSlug,
				includeDeleted: $includeDeleted
			);

			$closedHit = 0;

			foreach ($proceedings as $proceeding) {
				$uuid = (string)$proceeding['uuid'];
				if ($this->isConcludedProceeding(uuid: $uuid, closed: $closed) === true) {
					// Terminal decision exists: this proceeding is concluded.
					$closedHit++;
					continue;
				}

				$caseId = $this->caseIdOf(proceeding: $proceeding['data']);
				if ($caseId === '') {
					continue;
				}

				if (isset($candidates[$caseId]) === false) {
					$candidates[$caseId] = ['schemas' => [], 'count' => 0];
				}

				$candidates[$caseId]['count']++;
				if (in_array($schemaSlug, $candidates[$caseId]['schemas'], true) === false) {
					$candidates[$caseId]['schemas'][] = $schemaSlug;
				}
			}//end foreach

			// `closed` is reported per schema on purpose: if it is 0 while
			// terminal decisions exist, the "only hold OPEN proceedings" rule
			// is silently inert and every concluded case would be held too.
			// That exact failure happened during development, and only showed
			// up because this counter was printed.
			$output->writeln(
				'  scanned ' . $schemaSlug . ': ' . count($proceedings) . ' object(s), '
				. $closedHit . ' already concluded'
			);
		}//end foreach

		return $candidates;
	}//end scan()

	/**
	 * The scan failures collected during the last scan() call.
	 *
	 * Never swallowed silently: a scan that fails and a schema that is
	 * genuinely empty are indistinguishable in the result, and that ambiguity
	 * is what let the original dead listener hide.
	 *
	 * @return array<int, string> One "schema: message" line per failure.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function getScanErrors(): array {
		return $this->scanErrors;
	}//end getScanErrors()

	/**
	 * Test whether a proceeding is already concluded, i.e. a terminal decision references it.
	 *
	 * @param string $uuid The proceeding UUID.
	 * @param array<int, string> $closed The proceeding UUIDs that carry a terminal decision.
	 *
	 * @return bool True when the proceeding is concluded.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function isConcludedProceeding(string $uuid, array $closed): bool {
		return ($uuid !== '' && in_array($uuid, $closed, true) === true);
	}//end isConcludedProceeding()

	/**
	 * List the proceeding UUIDs that already carry a terminal decision.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $schema The decision schema slug.
	 *
	 * @return array<int, string> Proceeding UUIDs that are concluded.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function terminatedProceedingIds(object $objectService, string $schema): array {
		$ids = [];

		foreach ($this->listObjects(objectService: $objectService, schema: $schema, includeDeleted: true) as $decision) {
			foreach (['objectionProceeding', 'beroep', 'appeal', 'objection'] as $key) {
				$ref = ($decision['data'][$key] ?? null);
				if (is_string($ref) === true && $ref !== '') {
					$ids[] = $ref;
				}
			}
		}

		return $ids;
	}//end terminatedProceedingIds()

	/**
	 * List every object of a schema in the dossiq register.
	 *
	 * Returns an empty array when the schema does not exist on this instance,
	 * so an instance that never installed a given Awb schema is a no-op rather
	 * than a failure.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $schema Schema slug.
	 * @param bool $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @return array<int, array<string, mixed>> Rendered objects.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function listObjects(object $objectService, string $schema, bool $includeDeleted): array {
		try {
			$filters = [];
			if ($includeDeleted === true) {
				$filters['_includeDeleted'] = true;
			}

			$objects = $objectService
				->setRegister(self::REGISTER)
				->setSchema($schema)
				->findAll(
					[
						'limit' => null,
						'filters' => $filters,
					],
					false,
					false
				);

			if (is_array($objects) === false) {
				return [];
			}

			return array_map(
				function (mixed $row): array {
					return $this->normaliser->normalise(row: $row);
				},
				$objects
			);
		} catch (\Throwable $e) {
			// Never swallow silently: a scan that fails and a schema that is
			// genuinely empty are indistinguishable in the result, and that
			// ambiguity is what let the original dead listener hide.
			$this->scanErrors[] = $schema . ': ' . $e->getMessage();
			return [];
		}//end try
	}//end listObjects()

	/**
	 * Read the case UUID a proceeding relates to.
	 *
	 * @param array<string, mixed> $proceeding The rendered proceeding object.
	 *
	 * @return string The case UUID, or '' when absent.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function caseIdOf(array $proceeding): string {
		$case = ($proceeding['case'] ?? null);
		if (is_string($case) === true) {
			return trim($case);
		}

		// An extended render inlines the related object instead of its id.
		if (is_array($case) === true) {
			return (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		}

		return '';
	}//end caseIdOf()
}//end class
