<?php

/**
 * The uuid of an OpenRegister object payload.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec exclude shared payload read of the timeline listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

/**
 * The payload's `@self.id`, falling back to its top-level `id`.
 *
 * @spec exclude shared payload read of the timeline listeners, covered by their own specs
 */
trait ReadsPayloadIdentifier {

	/**
	 * The object's uuid: `@self.id` when set, else the top-level `id`, else empty.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return string
	 */
	private function identifier(array $payload): string {
		$fromSelf = (string)($payload['@self']['id'] ?? '');
		if ($fromSelf !== '') {
			return $fromSelf;
		}

		return (string)($payload['id'] ?? '');
	}//end identifier()
}//end trait
