<?php

/**
 * Dossiq ItemArrivalMarker.
 *
 * Marks one requested item as received when its text is among what arrived,
 * stamping the moment only for an item that was not received before.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * Marks requested items against one answer's arrivals.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class ItemArrivalMarker {
	/**
	 * What arrived, trimmed and lower-cased, empties left out.
	 *
	 * @var array<int, string>
	 */
	private array $arrived = [];

	/**
	 * When it arrived.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $moment;

	/**
	 * Read one answer's arrivals.
	 *
	 * @param array<int, mixed> $received What arrived, by item text.
	 * @param DateTimeImmutable $moment   When.
	 */
	public function __construct(array $received, DateTimeImmutable $moment) {
		foreach ($received as $one) {
			$text = mb_strtolower(trim((string)$one));
			if ($text !== '') {
				$this->arrived[] = $text;
			}
		}

		$this->moment = $moment;
	}//end __construct()

	/**
	 * The item with its text trimmed and its received flag set; an item that
	 * arrives now for the first time gets the moment.
	 *
	 * @param array<string, mixed> $item A requested item with a non-empty text.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function mark(array $item): array {
		$text = trim((string)($item['item'] ?? ''));
		$already = (($item['received'] ?? false) === true);
		$nowHere = in_array(mb_strtolower($text), $this->arrived, true);

		$row = $item;
		$row['item'] = $text;
		$row['received'] = ($already || $nowHere);

		if ($already === false && $nowHere === true) {
			$row['receivedAt'] = $this->moment->format('c');
		}

		return $row;
	}//end mark()
}//end class
