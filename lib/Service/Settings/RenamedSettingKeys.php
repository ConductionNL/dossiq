<?php

/**
 * Dossiq renamed setting keys.
 *
 * Carries a value posted under a setting's earlier key onto its current key,
 * so a client that still posts the old key keeps saving the setting.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

/**
 * Maps posted settings from earlier keys onto current keys.
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */
final class RenamedSettingKeys {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $earlierKeys Each current key's earlier key.
	 */
	public function __construct(
		private readonly array $earlierKeys,
	) {
	}//end __construct()

	/**
	 * A settings save with every earlier key carried onto its current key.
	 *
	 * A value posted under the current key wins over one under the earlier key.
	 *
	 * @param array<string, mixed> $data The settings as posted.
	 *
	 * @return array<string, mixed> The settings, current keys filled in.
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function carry(array $data): array {
		foreach ($this->earlierKeys as $current => $earlier) {
			if (array_key_exists($earlier, $data) === true && array_key_exists($current, $data) === false) {
				$data[$current] = $data[$earlier];
			}
		}

		return $data;
	}//end carry()
}//end class
