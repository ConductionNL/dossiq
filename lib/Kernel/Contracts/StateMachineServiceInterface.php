<?php

/**
 * Contract of StateMachineService
 *
 * The public surface of StateMachineService that other components call, so they depend
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
 * Contract of StateMachineService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StateMachineServiceInterface {

	/**
	 * Fields that may NOT be edited once a beschikking is frozen.
	 *
	 * Lives beside IMMUTABLE_STATUSES because the two halves of the same rule
	 * drift when they live apart, and the drift is silent.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [
		'rationale',
		'decision',
		'addressee',
		'decisionType',
		'legalRemediesClause',
		'feeAmount',
		'templateId',
		// The number and the backward pointer are what a bezwaar cites: a
		// signed beschikking that could be renumbered, or re-pointed at a
		// different predecessor, is not the one that was served (REQ-BES-012).
		'reference',
		'supersedes',
	];

	/**
	 * Fields written once, after signing, and never again.
	 *
	 * `supersededBy` is the forward pointer a correction writes on the
	 * beschikking it replaces: the ONLY field the freeze lets a successor
	 * write (REQ-BES-012). Empty to a value is allowed at every status; a
	 * value to anything else is refused, so a replaced beschikking can never
	 * be replaced a second time and the chain stays linear.
	 *
	 * @var array<int, string>
	 */
	public const WRITE_ONCE_FIELDS = [
		'supersededBy',
	];

	/**
	 * What a handler is told when a frozen beschikking refuses a write.
	 *
	 * REQ-BES-008 requires the refusal to name the way forward, so a handler
	 * who is told no is also told what to do instead.
	 *
	 * @var string
	 */
	public const IMMUTABLE_MESSAGE = 'This beschikking has been signed and cannot be edited. '
		. 'Issue a wijzigingsbeschikking or an intrekkingsbeschikking instead.';

	/**
	 * Refuse a delete on a beschikking that has been signed.
	 *
	 * A signed beschikking is evidence. Correcting it is a successor
	 * (REQ-BES-012); removing it is never an answer.
	 *
	 * @param array<string, mixed> $stored The beschikking as it is in the store.
	 *
	 * @return void
	 *
	 * @throws RuntimeException 'immutable' when the stored status is frozen.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function assertDeletable(array $stored): void;

	/**
	 * Refuse a content edit on a beschikking that has been signed.
	 *
	 * The STORED status decides. A payload that claims `draft` over a row that
	 * is `sent` must be refused, and it is, because `$changed` is only ever
	 * consulted for which fields the caller means to touch.
	 *
	 * Process events stay allowed at every status: `dispatch`, the bezwaar
	 * link and `archive` are not content, and a beschikking that could not
	 * record its own delivery would be unusable.
	 *
	 * @param array<string, mixed> $stored The beschikking as it is in the store.
	 * @param array<string, mixed> $changed The fields the caller intends to change, keyed by name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException 'immutable' when the stored status is frozen and a content field is touched,
	 *                          or a write-once field already holds a different value.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function assertMutable(array $stored, array $changed): void;

	/**
	 * Whether the given status locks the beschikking content.
	 *
	 * @param string $status The current status.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T16
	 */
	public function isImmutable(string $status): bool;
}//end interface
