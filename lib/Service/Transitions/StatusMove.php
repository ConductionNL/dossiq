<?php

/**
 * Dossiq Status Move value object.
 *
 * The status a `setStatus` step asks for, read once off the step config and
 * the case: the role and the literal name it names, the case's own case type,
 * and whether the flow author declared the phase optional.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * The status one `setStatus` step asks for, on one case.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class StatusMove {
	/**
	 * The literal status name the step named, or ''.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * The status role the step named, or ''.
	 *
	 * @var string
	 */
	public readonly string $role;

	/**
	 * The case's own case type, or ''.
	 *
	 * @var string
	 */
	public readonly string $caseTypeId;

	/**
	 * Whether the step declared its phase optional: only an explicit false
	 * `required` does.
	 *
	 * @var boolean
	 */
	public readonly bool $optional;

	/**
	 * Read the step and the case.
	 *
	 * @param array $actionConfig The step config: `status`, `role`, `required`.
	 * @param array $case         The case being walked.
	 */
	public function __construct(array $actionConfig, public readonly array $case) {
		$this->name = trim((string)($actionConfig['status'] ?? ''));
		$this->role = trim((string)($actionConfig['role'] ?? ''));
		$this->caseTypeId = (string)($case['caseType'] ?? '');
		$this->optional = ($actionConfig['required'] ?? true) === false;
	}//end __construct()

	/**
	 * Why this step cannot move the case at all, or null when it can try.
	 *
	 * @return string|null The error code.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function refusal(): ?string {
		if ($this->name === '' && $this->role === '') {
			return 'set_status_missing_status';
		}

		if ($this->caseTypeId === '') {
			return 'case_has_no_case_type';
		}

		return null;
	}//end refusal()

	/**
	 * What was tried, both directions when both were given: `role "x" or name "y"`.
	 *
	 * @return string The words.
	 *
	 * @spec openspec/changes/case-flow-status-roles/specs/status-transition-engine/spec.md
	 */
	public function tried(): string {
		$tried = [];
		if ($this->role !== '') {
			$tried[] = 'role "' . $this->role . '"';
		}

		if ($this->name !== '') {
			$tried[] = 'name "' . $this->name . '"';
		}

		return implode(' or ', $tried);
	}//end tried()
}//end class
