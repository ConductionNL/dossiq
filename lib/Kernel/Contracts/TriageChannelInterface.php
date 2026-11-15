<?php

/**
 * Contract of TriageChannel
 *
 * The public surface of TriageChannel that other components call, so they depend
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
 * Contract of TriageChannel.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TriageChannelInterface {

	/**
	 * Every item with its `channel` filled in.
	 *
	 * @param array<int, array<string, mixed>> $items The queue items.
	 *
	 * @return array<int, array<string, mixed>> The items, each with `channel`.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function annotate(array $items): array;

	/**
	 * How many items each channel holds, the largest first.
	 *
	 * @param array<int, array<string, mixed>> $items Annotated queue items.
	 *
	 * @return list<array{value: string, count: int}> One entry per channel.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function facet(array $items): array;

	/**
	 * Keep the items of the asked channels.
	 *
	 * @param array<int, array<string, mixed>> $items   Annotated queue items.
	 * @param string                           $channel One channel, a comma list as a list sends several, or empty or `all` for every channel.
	 *
	 * @return array<int, array<string, mixed>> The items kept.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function narrow(array $items, string $channel): array;
}//end interface
