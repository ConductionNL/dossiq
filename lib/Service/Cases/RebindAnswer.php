<?php

/**
 * Dossiq RebindAnswer.
 *
 * One answer of a case on its way through a rebind: its comparable name, the
 * answer as read, and the source definition it was given under.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * An answer and its source definition.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class RebindAnswer {
	/**
	 * Constructor.
	 *
	 * @param string                                                 $key    The comparable name.
	 * @param array{name: string, definition: string, value: string} $entry  The answer.
	 * @param array<string, mixed>                                   $source Its definition, or [] when the source type declares none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $key,
		public readonly array $entry,
		public readonly array $source,
	) {
	}//end __construct()
}//end class
