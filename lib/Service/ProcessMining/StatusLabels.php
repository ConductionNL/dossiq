<?php

/**
 * The reader's labels of a process's status types
 *
 * A status type id resolved to its name in the reader's language, or the id
 * itself when the type is unknown or unnamed. The transition matrix and the
 * dwell-time analysis label their rows the same way, so they label them here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * Status type labels over one status type index, built by its owner.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class StatusLabels {

	/**
	 * Constructor.
	 *
	 * @param TranslatedText                      $text            Reads a language map in the reader's language.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TranslatedText $text,
		private readonly array $statusTypeIndex,
	) {
	}//end __construct()

	/**
	 * Resolve a statusType id to its human-readable label.
	 *
	 * @param string $statusId StatusType UUID.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function labelOf(string $statusId): string {
		if (isset($this->statusTypeIndex[$statusId]) === false) {
			return $statusId;
		}

		$entry = $this->statusTypeIndex[$statusId];

		// A status name is translatable, so the row carries a language map.
		$name = $this->text->forReader(value: ($entry['name'] ?? ($entry['title'] ?? '')));
		if ($name === '') {
			return $statusId;
		}

		return $name;
	}//end labelOf()
}//end class
