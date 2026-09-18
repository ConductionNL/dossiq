<?php

/**
 * Who a letter an action files is addressed to.
 *
 * Shared by the two handlers that file an outgoing document on a case, so
 * both name the addressee the same way and neither leaves a letter that says
 * it went out without saying to whom.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\Service\People\CaseRoleProjection;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;

/**
 * The addressed parties of a case, for a handler that files a letter.
 *
 * Requires the using class to hold a `$container`, which both handlers do and
 * which is how they already reach the dossier service. Through the container
 * rather than the constructor for the same reason: a handler is built
 * whenever the Flow node catalogue is, and a constructor dependency would
 * drag the party stack into every catalogue read.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */
trait AddressesTheCase {

	/**
	 * The parties a letter leaving this case is addressed to.
	 *
	 * 🔴 THE ROLES ARE TRIED IN ORDER AND THE FIRST THAT ANSWERS WINS. They
	 * are not equal claims: a party somebody wrote `geadresseerde` on was put
	 * there for this letter, and the requester is who a letter goes to when
	 * nobody said otherwise. Merging them would post the beschikking to the
	 * applicant as well as to the addressee. `initiator` is last, because it
	 * is what the requester is called on a case whose links predate the
	 * generic roles.
	 *
	 * An unavailable writer answers with no addressee rather than refusing:
	 * the letter is the act, and a letter filed without a recipient can be
	 * given one on its properties dialog, where a letter never filed cannot.
	 *
	 * @param string $caseId The case the letter is on.
	 *
	 * @return array<int, string> The addressed parties, [] when there are none.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	protected function addressedParties(string $caseId): array {
		if (trim($caseId) === '') {
			return [];
		}

		// `has()` rather than a swallowed `get()`. An absent writer is a
		// question the container can answer, and asking it keeps the "it
		// threw, so pretend there is nobody" shape out of this file: a writer
		// that EXISTS and cannot be built is a real error and belongs in the
		// handler's own failure path, not silently in an empty addressee.
		if ($this->container->has(CorrespondentWriter::class) === false) {
			return [];
		}

		$writer = $this->container->get(CorrespondentWriter::class);
		if (($writer instanceof CorrespondentWriter) === false) {
			return [];
		}

		return $writer->addressedParties(
			caseId: $caseId,
			roles: [
				DocumentCorrespondents::ROLE_RECIPIENT,
				'aanvrager',
				CaseRoleProjection::INITIATOR,
			],
		);
	}//end addressedParties()
}//end trait
