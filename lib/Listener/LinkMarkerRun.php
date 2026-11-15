<?php

/**
 * Dossiq link marker run.
 *
 * What applying one link-marker rule to one saved object shares between its steps.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Relation\LinkMarkerRule;

/**
 * One rule applied to one source: the rule, the source, the target it names and where the target lives.
 *
 * Built by {@see LinkMarkerListener} only; it never leaves it.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */
final class LinkMarkerRun {

	/**
	 * The rule.
	 *
	 * @var LinkMarkerRule
	 */
	public LinkMarkerRule $rule;

	/**
	 * The saved source object.
	 *
	 * @var array<string, mixed>
	 */
	public array $source = [];

	/**
	 * The target the source names.
	 *
	 * @var string
	 */
	public string $targetId = '';

	/**
	 * The OpenRegister object service, when there is one.
	 *
	 * @var object|null
	 */
	public ?object $objectService = null;

	/**
	 * The configured register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The target's configured schema.
	 *
	 * @var string
	 */
	public string $targetSchema = '';
}//end class
