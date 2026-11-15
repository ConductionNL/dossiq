<?php

/**
 * Dossiq legal-hold applier.
 *
 * The write half of the legal-hold backfill: walk the candidate cases the
 * {@see AwbProceedingScanner} found, report each one, and — only when the
 * operator passed --apply — place the hold through OpenRegister's
 * LegalHoldService.
 *
 * Safety posture, unchanged from the command it was split out of:
 * - Idempotent. A case that already carries an active hold is skipped.
 * - Additive only. It places holds; it never releases one and never deletes or
 *   range-updates anything. Retention data is legal-retention data.
 * - The reason string names this remediation explicitly, so a backfilled hold
 *   is never mistaken for a contemporaneous one in an audit.
 * - A case that cannot be resolved is reported, never silently dropped: a hold
 *   that cannot be placed is a finding.
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

use OCA\OpenRegister\Service\HoldOrder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports and (optionally) places the backfilled Awb legal holds.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class LegalHoldApplier {
	/**
	 * Walk the candidate cases, reporting each and holding it when applying.
	 *
	 * @param array<string, array<string, mixed>> $candidates Cases keyed by UUID.
	 * @param object $objectMapper OpenRegister object mapper.
	 * @param object $legalHoldService OpenRegister legal hold service.
	 * @param bool $apply Whether to write.
	 * @param bool $includeDeleted Whether soft-deleted cases are in scope.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function reportAndApply(
		array $candidates,
		object $objectMapper,
		object $legalHoldService,
		bool $apply,
		bool $includeDeleted,
		OutputInterface $output,
	): int {
		$run = new HoldRun(objectMapper: $objectMapper, legalHoldService: $legalHoldService, apply: $apply, includeDeleted: $includeDeleted);
		$tally = ['held' => 0, 'already' => 0, 'unresolved' => 0];

		$output->writeln('');
		$output->writeln('<info>Cases with at least one OPEN Awb proceeding:</info>');

		foreach ($candidates as $caseId => $meta) {
			[$outcome, $line] = $this->holdOne(run: $run, candidate: ['id' => (string)$caseId, 'meta' => $meta]);
			$output->writeln($line);
			$tally[$outcome]++;
		}

		foreach ($this->summary(run: $run, tally: $tally + ['candidates' => count($candidates)]) as $line) {
			$output->writeln($line);
		}

		return Command::SUCCESS;
	}//end reportAndApply()

	/**
	 * Report one candidate, and hold it when applying.
	 *
	 * @param HoldRun                                       $run       The run.
	 * @param array{id: string, meta: array<string, mixed>} $candidate The case UUID and its metadata.
	 *
	 * @return array{0: string, 1: string} The tally it counts toward (held, already, unresolved) and its report line.
	 */
	private function holdOne(HoldRun $run, array $candidate): array {
		$caseId = $candidate['id'];
		$described = $caseId . ' — ' . $this->describe(meta: $candidate['meta']);

		$caseObject = $this->findCase(run: $run, caseId: $caseId);
		if ($caseObject === null) {
			// A dangling reference: the proceeding names a case that does not
			// exist (or is soft-deleted and not in scope). Reported, never
			// silently dropped — a hold that cannot be placed is a finding.
			return ['unresolved', '  <comment>[unresolved]</comment> ' . $described];
		}

		if ($run->legalHoldService->hasActiveHold($caseObject) === true) {
			return ['already', '  <comment>[already held]</comment> ' . $described];
		}

		if ($run->apply === false) {
			return ['held', '  <info>[would hold]</info> ' . $described];
		}

		try {
			$run->legalHoldService->placeHold(object: $caseObject, order: new HoldOrder(reason: $this->reasonFor(meta: $candidate['meta'])));
			return ['held', '  <info>[HELD]</info> ' . $described];
		} catch (\Throwable $e) {
			return ['unresolved', '  <error>[FAILED]</error> ' . $caseId . ' — ' . $e->getMessage()];
		}
	}//end holdOne()

	/**
	 * The closing lines of a run: the counts, and the dry-run notice.
	 *
	 * @param HoldRun            $run   The run.
	 * @param array<string, int> $tally The counts: candidates, held, already, unresolved.
	 *
	 * @return array<int, string> The lines.
	 */
	private function summary(HoldRun $run, array $tally): array {
		$heldLabel = '  would hold  = ';
		if ($run->apply === true) {
			$heldLabel = '  held        = ';
		}

		$lines = [
			'',
			'  candidates  = ' . $tally['candidates'],
			$heldLabel . $tally['held'],
			'  already held= ' . $tally['already'],
			'  unresolved  = ' . $tally['unresolved'],
		];
		if ($run->apply === false) {
			$lines[] = '';
			$lines[] = '<comment>Dry run — nothing was written. Re-run with --apply to place these holds.</comment>';
		}

		return $lines;
	}//end summary()

	/**
	 * Resolve a case ObjectEntity by UUID.
	 *
	 * Mirrors the fixed listener exactly (procest#693): `find()` with RBAC and
	 * multitenancy disabled, because occ has no session user and no active
	 * organisation, and an organisation-scoped read would find nothing and
	 * silently reopen the same hole this command exists to close.
	 *
	 * @param HoldRun $run    The run (object mapper, and whether soft-deleted cases are in scope).
	 * @param string  $caseId The case UUID.
	 *
	 * @return object|null The case ObjectEntity, or null when unresolvable.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function findCase(HoldRun $run, string $caseId): ?object {
		try {
			$caseObject = $run->objectMapper->find(
				identifier: $caseId,
				includeDeleted: $run->includeDeleted,
				_rbac: false,
				_multitenancy: false
			);

			if (is_object($caseObject) === true) {
				return $caseObject;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}//end try
	}//end findCase()

	/**
	 * Build the hold reason, naming the remediation so it is auditable.
	 *
	 * @param array<string, mixed> $meta Candidate metadata.
	 *
	 * @return string The reason recorded on the hold.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function reasonFor(array $meta): string {
		$schemas = implode('/', ($meta['schemas'] ?? []));

		return 'Awb-procedure (' . $schemas . ') geregistreerd — archivering opgeschort '
			. '[backfill procest#694: hold ontbrak doordat de listener nooit heeft gelopen; '
			. 'geplaatst op de datum van herstel, niet terugwerkend]';
	}//end reasonFor()

	/**
	 * Render a one-line description of a candidate.
	 *
	 * @param array<string, mixed> $meta Candidate metadata.
	 *
	 * @return string Human-readable description.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function describe(array $meta): string {
		return ($meta['count'] ?? 0) . ' open proceeding(s): ' . implode(', ', ($meta['schemas'] ?? []));
	}//end describe()
}//end class
