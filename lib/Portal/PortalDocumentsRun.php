<?php

/**
 * One listing of the documents a resident may see on a case.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * What listing one case's documents shares between its steps.
 *
 * Built by {@see PortalCaseDocuments} only; it never leaves it.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalDocumentsRun {

	/**
	 * The case being read.
	 *
	 * @var PortalCaseRead
	 */
	public PortalCaseRead $read;

	/**
	 * The informatieobject schema.
	 *
	 * @var string
	 */
	public string $infoSchema = '';

	/**
	 * The case object the files live on: register, schema and id.
	 *
	 * @var array<string, string>
	 */
	public array $file = [];

	/**
	 * The documents the decisions on the case link, by uuid, with the decision's date.
	 *
	 * @var array<string, string>
	 */
	public array $decisionDates = [];
}//end class
