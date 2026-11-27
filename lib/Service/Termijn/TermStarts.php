<?php

/**
 * Dossiq term starts.
 *
 * Works out how a statutory term starts (the definition it starts under,
 * its start date and duration) and stores the new instance with its end
 * counted and rolled. Split out of
 * {@see \OCA\Dossiq\Service\TermijnService}, which keeps the start event,
 * the engine timer, the case mirror and every later write of the term.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\TermStart;
use RuntimeException;

/**
 * The start of a statutory term and its first stored instance.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class TermStarts {

	/**
	 * Constructor.
	 *
	 * @param TermDefinitions   $definitions What a case type's term definitions say.
	 * @param TermInstanceStore $store       Where a term instance is written.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly TermDefinitions $definitions,
		private readonly TermInstanceStore $store,
	) {
	}//end __construct()

	/**
	 * The start of a term, with the definition it starts under.
	 *
	 * @param string                                                                                            $caseId The case uuid.
	 * @param array{caseType: string, startDate: DateTimeImmutable|null, resolution: array<string, mixed>|null} $start  `caseType` the zaaktype
	 *                                                                                                                  identifier; `startDate`
	 *                                                                                                                  optional start (defaults to
	 *                                                                                                                  now); `resolution` the
	 *                                                                                                                  resolution a caller already
	 *                                                                                                                  made.
	 *
	 * @return TermStart
	 *
	 * @throws NoTermijnDefinitieException When no TermijnDefinitie matches the zaaktype.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function begin(string $caseId, array $start): TermStart {
		[$caseType, $startDate, $resolution] = [$start['caseType'], $start['startDate'], $start['resolution']];
		$start = new TermStart();
		$start->caseId = $caseId;
		$start->caseType = $caseType;
		$start->startDate = ($startDate ?? new DateTimeImmutable());
		$start->resolution = $resolution;
		$start->definitie = $this->definitionToStart(start: $start);
		$start->durationDays = (int)($start->definitie['standardDurationDays'] ?? 0);

		return $start;
	}//end begin()

	/**
	 * The definition the term starts from: the resolution's, or the case type's active one.
	 *
	 * @param TermStart $start The term start.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NoTermijnDefinitieException When the case type has no active definition.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function definitionToStart(TermStart $start): array {
		$definitie = (($start->resolution['definition'] ?? null) ?? $this->definitions->activeFor(caseType: $start->caseType));
		if ($definitie === null) {
			// A DISTINCT type, because this is the one refusal a caller can
			// act on and the one that must not be swallowed at debug level:
			// it means no statutory clock started for this case at all.
			throw new NoTermijnDefinitieException(
				message: 'No active TermijnDefinitie configured for zaaktype "' . $start->caseType . '" (REQ-TERM-001-A)'
			);
		}

		return $definitie;
	}//end definitionToStart()

	/**
	 * Store the new statutory term instance.
	 *
	 * @param TermStart $start The term start.
	 *
	 * @return array<string, mixed> The stored instance.
	 *
	 * @throws RuntimeException When the instance could not be persisted.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function store(TermStart $start): array {
		$saved = $this->store->save(schemaConfigKey: 'termijn_instance_schema', object: $this->startedInstance(start: $start));
		if ($saved === null) {
			throw new RuntimeException(
				'Failed to persist TermijnInstance for zaak "' . $start->caseId . '" (persistence unavailable)'
			);
		}

		return $saved;
	}//end store()

	/**
	 * The new instance: its definition, its kind, and its end counted and rolled.
	 *
	 * @param TermStart $start The term start.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function startedInstance(TermStart $start): array {
		// COUNTED AND ROLLED IN ONE CALL. The Algemene termijnenwet roll used to
		// sit here, one line below the count, and the two always ran together.
		// They live together now, on the class that reads what the definition
		// declares, so no caller can take the count without the roll.
		$endDate = $this->definitions
			->endDateFor(start: $start->startDate, days: $start->durationDays, definitie: $start->definitie)
			->format('Y-m-d');

		$instance = [
			'case' => $start->caseId,
			'deadlineDefinition' => (string)($start->definitie['id'] ?? ''),
			// The kind this instance carries. A term created here is the one the
			// Awb sets and the citizen is told about, and every instance written
			// before the four kinds existed is one of these too, which is why
			// {@see TermKind::ofInstance()} reads an absent kind as statutory.
			'kind' => TermKind::STATUTORY,
			'startDate' => $start->startDate->format('Y-m-d\TH:i:sP'),
			'endDateCalculated' => $endDate,
			'endDateCurrent' => $endDate,
			'status' => 'lopend',
			'countExtensions' => 0,
			'notificatiesVerstuurd' => [],
		];

		// Which rule produced this term, recorded rather than re-derivable. A
		// term somebody disputes has to be explainable a year later, and the
		// configuration will have changed by then.
		if ($start->resolution !== null) {
			$instance['resolvedFrom'] = (string)($start->resolution['resolvedFrom'] ?? '');
			$instance['resolutionSnapshot'] = (array)($start->resolution['snapshot'] ?? []);
		}

		return $instance;
	}//end startedInstance()
}//end class
