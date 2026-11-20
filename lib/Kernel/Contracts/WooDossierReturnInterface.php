<?php

/**
 * Contract of SourceCollectionReturn
 *
 * The public surface of SourceCollectionReturn that other components call, so they depend
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
 * Contract of SourceCollectionReturn.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooDossierReturnInterface {

	/**
	 * Append the publication to the case's source dossier.
	 *
	 * The dossier is the resident's, in another app's register and tenant:
	 * read under the handler's own scope it is not there, and the decision
	 * never came back to it (rehearsal R3, "the source dossier is gone"). So
	 * it is read and written unscoped, and only this one collection: the one
	 * the case names, and only while its owner is still the case's requester,
	 * the check the intake made (REQ-WRI-003).
	 *
	 * @param array<string, mixed> $case          The case, with `wooRequest` and `portalSubject`.
	 * @param string               $publicationId The publication uuid.
	 * @param string               $title         What the item's note says.
	 *
	 * @return bool True when the dossier holds the publication afterwards.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	public function append(array $case, string $publicationId, string $title): bool;

	/**
	 * Tell the resident the decision on their request is published, once.
	 *
	 * Only on the first publish: a republish keeps the link the resident
	 * already has, and a withdrawal leaves it on the case, so a case that
	 * already carries `wooPublicationUrl` tells nobody again.
	 *
	 * @param array<string, mixed> $case          The case, as it was before this publish.
	 * @param string               $caseId        The case uuid.
	 * @param string               $publicationId The publication uuid.
	 *
	 * @return bool Whether the resident was told.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function tellTheResident(array $case, string $caseId, string $publicationId): bool;
}//end interface
