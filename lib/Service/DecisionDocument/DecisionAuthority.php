<?php

/**
 * Dossiq Decision Authority.
 *
 * The seam through which a decision document asks whether the actor may decide it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Whether an actor holds the delegated authority to decide a decision document.
 *
 * The decision document names the act (`authorityAct`); who holds it is
 * decidiq's mandate registry, reached through the delegated-authority guard
 * (capability C17, lane R9). Until that guard is bound under this interface
 * in the container, a document that names an act is refused as
 * indeterminate, never approved without the check.
 *
 * @spec openspec/specs/decision-document/spec.md
 */
interface DecisionAuthority {

	/**
	 * Refuse when the actor may not decide this act on this case.
	 *
	 * @param string $caseId The case.
	 * @param string $act The act the definition names.
	 * @param string $actor The deciding user.
	 * @param array<string, mixed> $document The decision document.
	 *
	 * @return void
	 *
	 * @throws RefusedException 403 when the actor lacks the authority; 503 when it cannot be checked.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function assertMayDecide(string $caseId, string $act, string $actor, array $document): void;
}//end interface
