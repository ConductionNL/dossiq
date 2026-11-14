<?php

/**
 * Contract of PartyIndicatorReader
 *
 * The public surface of PartyIndicatorReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of PartyIndicatorReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyIndicatorReaderInterface {

	/**
	 * The party uuid a person link names, '' when it names none.
	 *
	 * A user or a contact link carries no party, which is not an error: it
	 * is what every link written before the party model looks like.
	 *
	 * @param array<string, mixed> $link The link, as the contacts listing answers it.
	 *
	 * @return string The party uuid.
	 *
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function partyUuidOf(array $link): string;

	/**
	 * The label of the indicator refusing an outbound message to one party,
	 * or null when nothing refuses it.
	 *
	 * Per party rather than per case, on purpose: one protected party must
	 * not silence the letter to the other five.
	 *
	 * @param string $partyUuid The party, '' when the link names none.
	 *
	 * @return string|null The refusing indicator's label.
	 *
	 * @spec openspec/specs/roles-decisions/spec.md#requirement-an-indicator-on-a-party-is-surfaced-where-the-act-is-offered-req-role-013
	 */
	public function sendRefusalFor(string $partyUuid): ?string;
}//end interface
