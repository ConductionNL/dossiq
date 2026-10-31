<?php

/**
 * The channel a triage item came in on
 *
 * The intake log holds two kinds of entry: a message the mailbox received,
 * which carries a `mailMessageId` and no channel, and a message a channel
 * adapter handed over, which names its channel. The postvak lists both and
 * narrows to one channel, so every item says which it is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * Names, narrows to and counts the channels of the triage queue.
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
class TriageChannel {

	/**
	 * The channel of an entry the mailbox received.
	 */
	public const EMAIL = 'email';

	/**
	 * The filter value that keeps every channel.
	 */
	public const ALL = 'all';

	/**
	 * The channel one entry came in on.
	 *
	 * @param array<string, mixed> $item The intake log entry.
	 *
	 * @return string The channel id; `email` for a mailbox entry.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function channelOf(array $item): string {
		$channel = trim((string)($item['channel'] ?? ''));
		if ($channel === '') {
			return self::EMAIL;
		}

		return $channel;
	}//end channelOf()

	/**
	 * Every item with its `channel` filled in.
	 *
	 * @param array<int, array<string, mixed>> $items The queue items.
	 *
	 * @return array<int, array<string, mixed>> The items, each with `channel`.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function annotate(array $items): array {
		foreach ($items as $index => $item) {
			$items[$index]['channel'] = $this->channelOf(item: $item);
		}

		return $items;
	}//end annotate()

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
	public function narrow(array $items, string $channel): array {
		$wanted = array_values(
			array_filter(
				array_map(static fn (string $value): string => strtolower(trim($value)), explode(',', $channel)),
				static fn (string $value): bool => $value !== ''
			)
		);
		if ($wanted === [] || in_array(self::ALL, $wanted, true) === true) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				fn (array $item): bool => in_array(strtolower($this->channelOf(item: $item)), $wanted, true)
			)
		);
	}//end narrow()

	/**
	 * How many items each channel holds, the largest first.
	 *
	 * @param array<int, array<string, mixed>> $items Annotated queue items.
	 *
	 * @return list<array{value: string, count: int}> One entry per channel.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function facet(array $items): array {
		$counts = [];
		foreach ($items as $item) {
			$channel = $this->channelOf(item: $item);
			$counts[$channel] = (($counts[$channel] ?? 0) + 1);
		}

		$facet = [];
		foreach ($counts as $value => $count) {
			$facet[] = ['value' => (string)$value, 'count' => $count];
		}

		usort(
			$facet,
			static fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]
		);

		return $facet;
	}//end facet()
}//end class
