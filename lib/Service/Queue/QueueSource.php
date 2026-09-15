<?php

/**
 * The contract a mechanism declares to reach a person's queue.
 *
 * WHY THIS IS A CONTRACT AND NOT SIX QUERIES ON A PAGE. A My Work page that
 * hard-codes the queries it knows about grows a seventh the day somebody adds
 * a mechanism, and the seventh is the one nobody remembers. That is not a
 * hypothetical: dossiq's My Work tile showed engine tasks and nothing else,
 * and its own note said so plainly, while assigned cases, consultations,
 * approvals and mentions each had a page of their own.
 *
 * So a mechanism declares four things, and the queue is built from the
 * declarations: what it is called, how it is read for a given person, what an
 * item points at, and what closes it. Adding a mechanism means adding a
 * source, not editing a page. {@see \OCA\Dossiq\Tests\Unit\Architecture\AsksAPersonDeclaresASourceTest}
 * fails the build when a mechanism that asks a person declares nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * A declared queue source.
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
interface QueueSource {
	/**
	 * The source's name.
	 *
	 * Stable, lower-case and hyphenated. It is written into every item id and
	 * into the reader's own grouping preference, so renaming one loses those.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function name(): string;

	/**
	 * What the reader sees above this source's group.
	 *
	 * Translated, because it is on screen.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string;

	/**
	 * What takes an item off the queue.
	 *
	 * Prose the reader can check, shown beside the group. An item never leaves
	 * because somebody dismissed it, so this sentence is the only answer to
	 * "why is this still here".
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string;

	/**
	 * The mechanisms this source covers.
	 *
	 * A notification key as `notification:<key>`, a flow node as
	 * `flow:<ClassShortName>`. The architecture test joins these against what
	 * the tree actually ships, so a mechanism can never quietly reach nobody.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array;

	/**
	 * What is waiting on this person, from this mechanism.
	 *
	 * Throws rather than returning an empty list when the read fails. An empty
	 * list means "nothing is waiting", and a source that cannot answer must
	 * not be able to say that: ADR-102, and the reason the queue names an
	 * unavailable source instead of quietly showing fewer items.
	 *
	 * @param string $userId The person to read for.
	 *
	 * @return array<int, QueueItem> The items, in no particular order.
	 *
	 * @throws \RuntimeException When the source cannot be read.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function itemsFor(string $userId): array;
}//end interface
