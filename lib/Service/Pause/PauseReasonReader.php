<?php

/**
 * Dossiq PauseReasonReader.
 *
 * The pause reasons one case type declares, read once and handed on normalised.
 *
 * It is deliberately NOT part of {@see \OCA\Dossiq\Service\TermDeclarationReader}.
 * That one answers "how long", in plain integers, and every caller of it is a
 * service that moves a date. This one answers "what for", and its callers are
 * the pause act and the chasing. Folding the vocabulary into the day counts
 * would put a chase schedule in front of every service that computes a
 * deadline.
 *
 * A CASE TYPE THAT DECLARES NOTHING IS NOT A FAILURE. It is the state every
 * case type is in until somebody administers its reasons, and a pause on it
 * keeps working exactly as it did: a rationale, a suspended clock, no chasing.
 * So an unreadable case type answers an empty list rather than throwing, and
 * the only thing lost is the reminder nobody had configured.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Pause
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
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pause;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermDeclarationReader;
use Throwable;

/**
 * The reasons a case type allows a term to be suspended for (REQ-TERM-011).
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see PauseReason} is a vocabulary:
 * constants and pure functions over an array, with no state and nothing to
 * inject. Making it an instance would add a constructor dependency to every
 * class that names a category, to hide a `::` behind a `->`.
 */
class PauseReasonReader {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService The OpenRegister seam.
	 * @param TermDeclarationReader $declarations    The one walk from a case to its type.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermDeclarationReader $declarations,
	) {
	}//end __construct()

	/**
	 * Every reason one case type declares, normalised and in declared order.
	 *
	 * @param string $caseTypeId The CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The reasons, empty when none are declared.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function forCaseType(string $caseTypeId): array {
		$row = $this->caseTypeRow(caseTypeId: $caseTypeId);

		$reasons = [];
		foreach ((array)($row['pauseReasons'] ?? []) as $declared) {
			if (is_array($declared) === false) {
				continue;
			}

			$reason = PauseReason::normalise(row: $declared);
			if ($reason['key'] === '') {
				// A reason with no key cannot be stored on a pause, so it can
				// never be the reason in force. Offering it in a picker would
				// let a handler choose something that is silently dropped.
				continue;
			}

			$reasons[] = $reason;
		}//end foreach

		return $reasons;
	}//end forCaseType()

	/**
	 * Every reason behind one case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The reasons, empty when none are declared.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function forCase(string $caseId): array {
		$context = $this->declarations->caseContext(caseId: $caseId);
		if ($context['caseType'] === '') {
			return [];
		}

		return $this->forCaseType(caseTypeId: $context['caseType']);
	}//end forCase()

	/**
	 * One reason of a case, by its key.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $key    The reason key.
	 *
	 * @return array<string, mixed>|null The reason, or null when this case type does not declare it.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function findForCase(string $caseId, string $key): ?array {
		$wanted = trim($key);
		if ($wanted === '') {
			return null;
		}

		foreach ($this->forCase(caseId: $caseId) as $reason) {
			if ($reason['key'] === $wanted) {
				return $reason;
			}
		}//end foreach

		return null;
	}//end findForCase()

	/**
	 * The reason a suspended instance is running under.
	 *
	 * Read from the case type rather than copied onto the instance, so an
	 * administrator who lengthens an interval changes the pauses that are
	 * running now. The two facts the instance DOES keep for itself are the key
	 * and the party, because those are what the pause was registered as and
	 * re-reading them would let a re-declared reason rewrite history.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return array<string, mixed>|null The reason, or null when the pause carries none.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function forInstance(array $instance): ?array {
		$key = trim((string)($instance['pauseReason'] ?? ''));
		$caseId = trim((string)($instance['case'] ?? ''));
		if ($key === '' || $caseId === '') {
			return null;
		}

		return $this->findForCase(caseId: $caseId, key: $key);
	}//end forInstance()

	/**
	 * One case type row, or an empty array when it cannot be read.
	 *
	 * @param string $caseTypeId The CaseType UUID.
	 *
	 * @return array<string, mixed> The row, empty when unreadable.
	 */
	private function caseTypeRow(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema = (string)$this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($objectService === null || $caseTypeId === '' || $register === '' || $schema === '') {
			return [];
		}

		try {
			return ($this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseTypeId
			) ?? []);
		} catch (Throwable) {
			return [];
		}
	}//end caseTypeRow()
}//end class
