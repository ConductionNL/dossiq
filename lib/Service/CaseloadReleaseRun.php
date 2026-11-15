<?php

/**
 * Dossiq CaseloadReleaseRun.
 *
 * One caseload release while it is being carried out: who leaves, who
 * receives, why, which cases, who orders it, whether it starts now, and, as
 * the release goes, the batch id every case in it shares and the cases it
 * covers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one release, built and read by CaseReassignmentService only.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class CaseloadReleaseRun {
	/**
	 * The id shared by every case in this act, set once the preview is read.
	 *
	 * @var string
	 */
	public string $batchId = '';

	/**
	 * The cases the act covers, set once the preview is read.
	 *
	 * @var array<int, string>
	 */
	public array $caseIds = [];

	/**
	 * Constructor.
	 *
	 * @param string                    $fromUser      The departing handler.
	 * @param string                    $toUser        The receiving handler.
	 * @param string                    $justification Why the caseload is being moved.
	 * @param array<string, mixed>|null $filter        Optional filter, e.g. ['caseType' => 'uuid'].
	 * @param string                    $actorId       The acting coordinator.
	 * @param bool                      $commit        Whether to start the act, or leave it rehearsed.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $fromUser,
		public readonly string $toUser,
		public readonly string $justification,
		public readonly ?array $filter,
		public readonly string $actorId,
		public readonly bool $commit,
	) {
	}//end __construct()
}//end class
