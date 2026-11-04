<?php

/**
 * What a DSO case goes through: the status change notifier, the intake case payload and the case journal.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use OCA\Dossiq\Service\Lifecycle\CaseJournal;

/**
 * What a DSO case goes through: the status change notifier, the intake case payload and the case journal.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
final class DsoCaseSteps {
	/**
	 * Constructor.
	 *
	 * @param DsoStatusChangeNotifier $notifier    Emits the VergunningStatusChanged domain event
	 * @param DsoIntakeCasePayload    $intakeCases The case an intake record becomes
	 * @param CaseJournal             $journal     The case's activity record
	 */
	public function __construct(
		public readonly DsoStatusChangeNotifier $notifier,
		public readonly DsoIntakeCasePayload $intakeCases,
		public readonly CaseJournal $journal,
	) {
	}//end __construct()
}//end class
