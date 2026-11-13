<?php

/**
 * Dossiq evidence capture run.
 *
 * What filing one field capture shares between its steps.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-06-13-mobiel-inspectie-offline/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * One capture being filed: where evidence and inspections live, and which inspection.
 *
 * Built by {@see FieldEvidenceController} only; it never leaves it.
 *
 * @spec openspec/changes/archive/2026-06-13-mobiel-inspectie-offline/tasks.md#task-8
 */
final class EvidenceCaptureRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The field evidence schema.
	 *
	 * @var string
	 */
	public string $evidenceSchema = '';

	/**
	 * The field inspection schema.
	 *
	 * @var string
	 */
	public string $inspectionSchema = '';

	/**
	 * The inspection the capture is filed on.
	 *
	 * @var string
	 */
	public string $inspectionRef = '';
}//end class
