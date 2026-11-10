<?php

/**
 * Dossiq concluded decision run.
 *
 * What projecting one concluded decision shares between its steps.
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
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCP\EventDispatcher\Event;

/**
 * One terminal decision being projected: the event, its case and its besluit.
 *
 * Built by {@see DecisionConcludedListener} only; it never leaves it.
 *
 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
final class DecisionConcludedRun {

	/**
	 * The concluded decision event.
	 *
	 * @var Event
	 */
	public Event $event;

	/**
	 * The case a flow decision is about, or '' for this app's own decision.
	 *
	 * @var string
	 */
	public string $flowCase = '';

	/**
	 * The terminal status, lower-cased.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The decision's id.
	 *
	 * @var string
	 */
	public string $decisionId = '';

	/**
	 * The decision's subject id.
	 *
	 * @var string
	 */
	public string $subjectId = '';

	/**
	 * The reference the case is looked up by.
	 *
	 * @var string
	 */
	public string $externalRef = '';

	/**
	 * The resolved case, or '' when none resolved.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The existing besluit the decision projects onto, or ''.
	 *
	 * @var string
	 */
	public string $besluitId = '';

	/**
	 * The domain record carrying the decisionRef, when one was found.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $decisionRecord = null;
}//end class
