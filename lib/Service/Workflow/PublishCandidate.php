<?php

/**
 * Dossiq PublishCandidate
 *
 * A workflow definition asked to be published, with its decoded transitions.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

/**
 * The definition row, its transitions and its uuid, as the publish check reads them.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
final class PublishCandidate {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $definition  The definition row to check.
	 * @param array<int, mixed>    $transitions The row's decoded transitions.
	 * @param string               $id          The definition uuid, for the log.
	 */
	public function __construct(
		public readonly array $definition,
		public readonly array $transitions,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
