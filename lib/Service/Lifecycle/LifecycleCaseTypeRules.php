<?php

/**
 * What one case type declares about the acts on its cases.
 *
 * `CaseTypeReader` answers the four questions the transition engine asks
 * (suspension, extension, the period, the initial status) and its return shape
 * is that contract. The acts in `lifecycle-acts-on-the-case` ask six more:
 * three role groups, whether the process owns the status, and the silence
 * period with its warning. Widening the engine's reader to carry them would
 * put this change's vocabulary into every caller of the engine's.
 *
 * So this is a second reader over the same row, and deliberately not a second
 * COPY of the engine's answers: nothing here restates suspensionAllowed or
 * initialStatus. The two readers overlap in the row they read and in nothing
 * they answer.
 *
 * 🔑 AN UNREADABLE CASE TYPE GRANTS NOTHING AND DECLARES NOTHING. A role that
 * cannot be read is an empty group, which the acts translate into "only an
 * administrator", and a silence period that cannot be read is off. Per
 * ADR-102 the one declaration that fails the other way is process-owned
 * status: see {@see ProcessOwnedStatusRule}, where an unresolvable process
 * refuses the hand-set rather than falling through to it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\Archival\ReadsConfiguredRows;
use OCA\Dossiq\Service\SettingsService;

/**
 * Reads the case type declarations the lifecycle acts are gated by.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
class LifecycleCaseTypeRules {

	use ReadsConfiguredRows;

	/**
	 * How many days before an automatic close the applicant is warned when the
	 * case type declares a silence period and no warning.
	 *
	 * Seven rather than zero, because closing with no notice is the part of
	 * the act that would be indefensible, and a case type that forgot to state
	 * a warning has not decided to skip it.
	 *
	 * @var int
	 */
	public const DEFAULT_WARNING_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The settings bridge, for {@see ReadsConfiguredRows}.
	 *
	 * @return SettingsService The bridge to OpenRegister plus app config.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through every rule read in this class
	 */
	protected function settings(): SettingsService {
		return $this->settingsService;
	}//end settings()

	/**
	 * The group a named act needs on this case type.
	 *
	 * @param string $caseTypeId The case type UUID.
	 * @param string $act One of `finish`, `abort`, `archive`.
	 *
	 * @return string The group id, empty when the case type names none.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function roleFor(string $caseTypeId, string $act): string {
		$field = ([
			'finish' => 'finishingRole',
			'abort' => 'abortingRole',
			'archive' => 'archivingRole',
		][$act] ?? '');

		if ($field === '') {
			return '';
		}

		return trim((string)($this->row(caseTypeId: $caseTypeId)[$field] ?? ''));
	}//end roleFor()

	/**
	 * Whether this case type declares that the process owns the status.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return bool True when no status may be hand-set on its cases.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function processOwnsStatus(string $caseTypeId): bool {
		$declared = ($this->row(caseTypeId: $caseTypeId)['processOwnedStatus'] ?? false);

		return in_array($declared, [true, 1, '1', 'true'], true);
	}//end processOwnsStatus()

	/**
	 * Which workflow definition the process is, when one is declared.
	 *
	 * Read so {@see ProcessOwnedStatusRule} can tell "this case type has a
	 * process" from "this case type says the process owns the status and there
	 * is no process", which ADR-102 makes two different answers.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return string The workflow definition id, empty when none is declared.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function processOf(string $caseTypeId): string {
		$declared = ($this->row(caseTypeId: $caseTypeId)['workflowDefinition'] ?? '');
		if (is_array($declared) === true) {
			$declared = ($declared['id'] ?? ($declared['@self']['id'] ?? ''));
		}

		return trim((string)$declared);
	}//end processOf()

	/**
	 * After how many days of silence this case type closes a case itself.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return int The period in days, 0 when the case type declares none.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function silenceDays(string $caseTypeId): int {
		$days = (int)($this->row(caseTypeId: $caseTypeId)['autoCloseAfterSilenceDays'] ?? 0);

		return max(0, $days);
	}//end silenceDays()

	/**
	 * How many days before that close the applicant is warned.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return int The warning lead time in days.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function warningDays(string $caseTypeId): int {
		$days = (int)($this->row(caseTypeId: $caseTypeId)['autoCloseWarningDays'] ?? 0);
		if ($days <= 0) {
			return self::DEFAULT_WARNING_DAYS;
		}

		return $days;
	}//end warningDays()

	/**
	 * The case type row itself.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 *
	 * @spec exclude one read behind every rule above; each of those carries the requirement
	 */
	private function row(string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		return $this->findRow(schemaKey: 'case_type_schema', id: $caseTypeId);
	}//end row()
}//end class
