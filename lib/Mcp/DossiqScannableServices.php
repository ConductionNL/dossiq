<?php

/**
 * The services OpenRegister scans for dossiq's curated MCP tools.
 *
 * ADR-063: an app exposes a non-CRUD action to an assistant by marking a
 * service method `#[McpTool]`, and tells OpenRegister which classes to scan
 * through this list, registered under the alias
 * `OCA\OpenRegister\Mcp\IMcpScannableServices::dossiq`. A class missing here is
 * a tool nobody can call; a class listed here without an attributed method is a
 * dead seam. DossiqScannableServicesTest holds both directions.
 *
 * @category Mcp
 * @package  OCA\Dossiq\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
 */

declare(strict_types=1);

namespace OCA\Dossiq\Mcp;

use OCA\Dossiq\Service\ComplaintService;
use OCA\Dossiq\Service\Mcp\AppointmentTools;
use OCA\Dossiq\Service\Mcp\BeschikkingTools;
use OCA\Dossiq\Service\Mcp\CaseTools;
use OCA\Dossiq\Service\Mcp\IntakeTools;
use OCA\Dossiq\Service\Mcp\ReportingTools;
use OCA\Dossiq\Service\Mcp\TermTools;
use OCA\OpenRegister\Mcp\IMcpScannableServices;

/**
 * Exactly the dossiq classes that carry #[McpTool] methods.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
 */
class DossiqScannableServices implements IMcpScannableServices {

	/**
	 * The alias key OpenRegister resolves in dossiq's own container.
	 */
	public const ALIAS = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::dossiq';

	/**
	 * The scannable classes.
	 *
	 * @return list<class-string>
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-201-first-imcpscannableservices-opt-in-exact-enumeration
	 */
	public function getScannableServiceClasses(): array {
		return [
			ReportingTools::class,
			CaseTools::class,
			ComplaintService::class,
			TermTools::class,
			AppointmentTools::class,
			BeschikkingTools::class,
			IntakeTools::class,
		];
	}//end getScannableServiceClasses()
}//end class
