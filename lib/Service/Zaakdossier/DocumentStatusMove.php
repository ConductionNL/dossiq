<?php

/**
 * A document's status before and after one transition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * The status a document leaves and the one it enters.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 */
final class DocumentStatusMove {
	/**
	 * Constructor.
	 *
	 * @param string $from The stored status.
	 * @param string $to   The requested status.
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
