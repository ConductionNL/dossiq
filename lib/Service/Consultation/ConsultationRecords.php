<?php

/**
 * Where consultations, their dependencies and the obligations they wait on are read and written.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
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
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

use OCA\Dossiq\Service\Obligations\ObligationService;

/**
 * Where consultations, their dependencies and the obligations they wait on are read and written.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */
final class ConsultationRecords {
	/**
	 * Constructor.
	 *
	 * @param ConsultationRepository      $repository      OpenRegister reads/writes for consultations
	 * @param ConsultationDependencyGraph $dependencyGraph `dependsOn` cycle detection
	 * @param ObligationService           $obligations     The one mechanism a case waits on.
	 */
	public function __construct(
		public readonly ConsultationRepository $repository,
		public readonly ConsultationDependencyGraph $dependencyGraph,
		public readonly ObligationService $obligations,
	) {
	}//end __construct()
}//end class
