<?php

/**
 * One case being drained, with the blob decoded off it.
 *
 * Built by `CasePlanMigrationService::migrateCase()` before it commits, and handed through the commit,
 * verify and clear steps.
 *
 * @category CasePlanDrain
 * @package  OCA\Dossiq\Service\CasePlanDrain
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CasePlanDrain;

/**
 * One case being drained, with the blob decoded off it.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
final class CaseWithBlob {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $blob   The decoded blob.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly array $blob,
	) {
	}//end __construct()
}//end class
