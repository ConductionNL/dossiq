<?php

/**
 * Contract of AnonymisedCaseExport
 *
 * The public surface of AnonymisedCaseExport that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of AnonymisedCaseExport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnonymisedCaseExportInterface {

	/**
	 * The listed cases, anonymised, and the export written to the processing log.
	 *
	 * A case that cannot be found is left out. A case type that cannot be
	 * read refuses the whole export: without its identifying fields nothing
	 * says what must be removed, and an export that might hold them does not leave.
	 *
	 * @param array<int, mixed> $caseIds The cases of the filtered list.
	 * @param string            $userId  Who exports.
	 *
	 * @return array<int, array<string, mixed>> The anonymised cases, in list order.
	 *
	 * @throws RuntimeException When nothing is listed, too much is, or a case type cannot be read.
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
	 */
	public function export(array $caseIds, string $userId): array;
}//end interface
