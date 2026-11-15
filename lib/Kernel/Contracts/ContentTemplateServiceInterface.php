<?php

/**
 * Contract of ContentTemplateService
 *
 * The public surface of ContentTemplateService that other components call, so they depend
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
 * Contract of ContentTemplateService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContentTemplateServiceInterface {

	/**
	 * The templates of one kind that this case type may use.
	 *
	 * A template scoped to no case type is offered on every case type. A
	 * template scoped to some is offered only on those, which is what keeps a
	 * bezwaar note template off a vergunning case.
	 *
	 * @param string $kind       The kind, one of {@see self::KINDS}.
	 * @param string $caseTypeId The case type the handler is working on.
	 * @param string $search     Part of the name, or '' for all of them.
	 *
	 * @return array<int, array<string, mixed>>|null The templates, or null when
	 *                                               the store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function offered(string $kind, string $caseTypeId, string $search = ''): ?array;
}//end interface
