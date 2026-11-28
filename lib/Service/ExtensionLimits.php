<?php

/**
 * Whether a term may be extended as asked, and by how many days it moves.
 *
 * The new end has to lie after the current one, the term's ceiling of
 * extensions holds unless a supervisor overrides it (Awb 4:14 lid 3), and the
 * move may not be longer than the case type declares. The ceiling comes from
 * the term's definition in the TermijnService cache, else the Awb default of
 * one. {@see DeadlineExtensionService} carries the extension through the
 * store, the engine timer, the event and the notice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use ReflectionClass;
use RuntimeException;

/**
 * The checks an extension passes before it is stored.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class ExtensionLimits {

	/**
	 * Constructor.
	 *
	 * @param TermijnService             $termService  Holds the cached term definitions.
	 * @param CaseDateNormaliser         $dates        Parses and measures the dates.
	 * @param TermDeclarationReader|null $declarations The case type's declared extension period; null skips that check.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermDeclarationReader $declarations = null,
	) {
	}//end __construct()

	/**
	 * Check the extension against the term, the ceiling and the declared period, and measure its days.
	 *
	 * @param ExtensionRun $run The extension.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deadline does not move forward or the ceiling is exhausted.
	 * @throws RefusedException When the move is longer than the case type declares.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function assertAllowed(ExtensionRun $run): void {
		$this->assertExtensionPermitted(run: $run);

		$current = (string)($run->instance['endDateCurrent'] ?? '');
		$run->daysImpact = $this->daysImpact(current: $current, newEndDate: $run->newEndDate);

		// The ceiling is measured to the day ASKED FOR. The Algemene termijnenwet
		// moves an end off a weekend or holiday by law; a fourteen day extension
		// that the roll carries to the Monday is still the fourteen days the
		// law allows, not sixteen the case type refuses.
		$this->assertWithinDeclaredPeriod(
			run: $run,
			days: $this->daysImpact(current: $current, newEndDate: $run->requestedEndDate)
		);
	}//end assertAllowed()

	/**
	 * Compute the number of days the deadline moves by.
	 *
	 * @param string $current Current einddatumActueel, empty when unset.
	 * @param string $newEndDate New deadline (YYYY-MM-DD).
	 *
	 * @return int Absolute number of days between the current and the new deadline.
	 *
	 * @spec openspec/specs/termijnbewaking-op-engine-timers/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function daysImpact(string $current, string $newEndDate): int {
		$currentDate = $this->dates->now();
		if ($current !== '' && $current !== 'now') {
			$currentDate = $this->dates->parse($current, 'einddatumActueel');
		}

		$newDate = $this->dates->parse($newEndDate, 'newEinddatum');

		return $this->dates->daysBetween(from: $currentDate, to: $newDate);
	}//end daysImpact()

	/**
	 * Validate the verlenging against the instance state and the AWB 4:14 ceiling.
	 *
	 * @param ExtensionRun $run The extension: the instance row, the new deadline and the mode.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deadline does not move forward or the ceiling is exhausted.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function assertExtensionPermitted(ExtensionRun $run): void {
		$current = (string)($run->instance['endDateCurrent'] ?? '');
		if ($current !== '' && $run->newEndDate <= $current) {
			throw new RuntimeException('newEinddatum must be later than current einddatumActueel');
		}

		$consumed = (int)($run->instance['countExtensions'] ?? 0);
		$maxExt = $this->resolveMaxExtensions(instance: $run->instance);
		if ($run->mode !== DeadlineExtensionService::MODE_SUPERVISOR && $consumed >= $maxExt) {
			throw new RuntimeException('AWB 4:14 lid 3: maximum aantal verlengingen al verbruikt (' . $maxExt . ')');
		}
	}//end assertExtensionPermitted()

	/**
	 * Refuse a verlenging longer than the case type declares (REQ-TERM-066).
	 *
	 * `caseType.extensionPeriod` existed and nothing read it but the ZGW
	 * mapping, so any case could be extended by any amount and the Awb does not
	 * allow that. The refusal names the rule and the declared period, per
	 * ADR-050, because a handler told only "no" retries with the same number.
	 *
	 * The supervisor path (Awb 4:14 lid 3) is separately authorised and passes,
	 * exactly as it passes the count ceiling: that is what the override is for.
	 *
	 * @param ExtensionRun $run  The extension: the instance and the mode.
	 * @param int          $days How many days the deadline moves by.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the move is longer than the declared period.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function assertWithinDeclaredPeriod(ExtensionRun $run, int $days): void {
		if ($run->mode === DeadlineExtensionService::MODE_SUPERVISOR || $this->declarations === null) {
			return;
		}

		$declared = $this->declarations->forCase(caseId: (string)($run->instance['case'] ?? ''));

		if ($declared['extensionAllowed'] === false) {
			throw new RefusedException(
				rule: 'extension-not-allowed',
				sentence: 'This case type does not allow the term to be extended.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$period = (int)$declared['extensionPeriodDays'];
		if ($period > 0 && $days > $period) {
			throw new RefusedException(
				rule: 'extension-beyond-declared-period',
				sentence: 'This case type allows an extension of at most ' . $period
					. ' days, and you asked for ' . $days . '.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertWithinDeclaredPeriod()

	/**
	 * Resolve the maximum number of extensions allowed for this instance.
	 *
	 * Looks up the TermijnDefinitie via the instance reference and reads
	 * aantalVerlengingen; falls back to 1 when missing (AWB default).
	 *
	 * @param array<string, mixed> $instance Instance row.
	 *
	 * @return int
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function resolveMaxExtensions(array $instance): int {
		// Prefer to look up the definition by the linked id.
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return 1;
		}

		$svcDef = $this->cachedDefinition(defId: $defId);

		if (is_array($svcDef) === true) {
			return (int)($svcDef['countExtensions'] ?? 1);
		}

		return 1;
	}//end resolveMaxExtensions()

	/**
	 * The TermijnDefinitie with this id from the TermijnService cache, or null.
	 *
	 * @param string $defId The definition id.
	 *
	 * @return array<string, mixed>|null The definition, or null.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function cachedDefinition(string $defId): ?array {
		// Walk the TermijnService cache by zaaktype if available. As a
		// safe fallback, return the default 1 — a real lookup would
		// call SettingsService->getObjectService()->find($defId) here,
		// but TermijnService already caches lookups by zaaktype which
		// is the data we actually need.
		$svcDef = null;
		try {
			$svcDef = $this->definitionIn(cache: $this->definitieCache(), defId: $defId);
		} catch (\Throwable $e) {
			$svcDef = null;
		}

		return $svcDef;
	}//end cachedDefinition()

	/**
	 * The TermijnService's definition cache, or null when it has none.
	 *
	 * @return mixed The cache.
	 *
	 * @throws \ReflectionException When the cache cannot be read.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function definitieCache(): mixed {
		$reflection = new ReflectionClass($this->termService);
		if ($reflection->hasProperty('definitieCache') === false) {
			return null;
		}

		$prop = $reflection->getProperty('definitieCache');

		return $prop->getValue($this->termService);
	}//end definitieCache()

	/**
	 * The definition with this id in the cache, or null.
	 *
	 * @param mixed $cache The TermijnService's definition cache.
	 * @param string $defId The definition id.
	 *
	 * @return array<string, mixed>|null The definition, or null.
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function definitionIn(mixed $cache, string $defId): ?array {
		if (is_array($cache) === false) {
			return null;
		}

		foreach ($cache as $row) {
			if (is_array($row) === true && (string)($row['id'] ?? '') === $defId) {
				return $row;
			}
		}

		return null;
	}//end definitionIn()
}//end class
