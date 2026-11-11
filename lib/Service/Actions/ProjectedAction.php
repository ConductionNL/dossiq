<?php

/**
 * One stored automaticAction on its way to becoming a flow.
 *
 * Carries the action, the provenance marker that identifies it and, once
 * resolved, the steps it becomes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
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
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

/**
 * An identified action and the flow steps it becomes.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
final class ProjectedAction {
	/**
	 * The steps the action becomes; empty until resolved.
	 *
	 * @var array<int, array{type: string, config: array<string, mixed>}>
	 */
	public array $steps = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $action The stored automaticAction.
	 * @param string               $marker Its provenance marker.
	 */
	public function __construct(
		public readonly array $action,
		public readonly string $marker,
	) {
	}//end __construct()

	/**
	 * The action's type, as stored.
	 *
	 * @return string The type, or an empty string.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function type(): string {
		return (string)($this->action['type'] ?? '');
	}//end type()
}//end class
