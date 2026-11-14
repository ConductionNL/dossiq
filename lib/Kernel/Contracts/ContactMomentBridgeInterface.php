<?php

/**
 * Contract of ContactMomentBridge
 *
 * The public surface of ContactMomentBridge that other components call, so they depend
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
 * Contract of ContactMomentBridge.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactMomentBridgeInterface {

	/**
	 * Append one logged contact moment to pipelinq's record.
	 *
	 * @param string $caseId The case the moment was logged on.
	 * @param array<string, mixed> $moment The dossiq contact moment, as written.
	 *
	 * @return array{appended: bool, reason: string, indicators: array<int, array<string, mixed>>}
	 *   Whether it landed, why not when it did not, and the indicators pipelinq
	 *   named when it refused an outbound append.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
	 */
	public function append(string $caseId, array $moment): array;

	/**
	 * File an existing contact moment onto a further case.
	 *
	 * Through pipelinq's act, never by editing the set: the act records who
	 * filed it and when, and it is what guarantees no second record is made.
	 *
	 * @param string $momentId The contact moment.
	 * @param string $caseId The case to file it onto.
	 *
	 * @return array{filed: bool, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function fileOnAlsoCase(string $momentId, string $caseId): array;

	/**
	 * The contact moments on a case, by MEMBERSHIP, with the shared marker.
	 *
	 * Membership, not equality: one call filed on three cases is one record and
	 * each of the three has to see it. Reading only the primary reference shows
	 * it on one case and renders the other two as if the call never happened.
	 *
	 * @param string $caseId The case.
	 * @param string $partyId The party whose indicators travel with the panel.
	 *
	 * @return array{available: bool, moments: array<int, array<string, mixed>>, indicators: array<int, array<string, mixed>>}
	 *   The panel. `available` false means pipelinq did not answer, which is
	 *   not the same as a case with no contact moments.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function onCase(string $caseId, string $partyId = ''): array;

	/**
	 * Take a contact moment off one case.
	 *
	 * @param string $momentId The contact moment.
	 * @param string $caseId The case to take it off.
	 * @param string|null $newPrimary The next primary, when the primary is going.
	 *
	 * @return array{filed: bool, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array;
}//end interface
