<?php

/**
 * One submission on its way to a case per declared destination.
 *
 * Built by `IntakeFanOut::submit()` and never handed out: each destination
 * adds the case it opened, or itself to the failed list.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * The state of one fan-out.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
final class FanOutRun {
	/**
	 * The cases opened so far, `{id, caseType, department, title}`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $created = [];

	/**
	 * The destinations that opened no case, with why.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $failed = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $submission   The submitted values every case starts from.
	 * @param string               $submissionId The submission's own identifier.
	 */
	public function __construct(
		public readonly array $submission,
		public readonly string $submissionId,
	) {
	}//end __construct()
}//end class
