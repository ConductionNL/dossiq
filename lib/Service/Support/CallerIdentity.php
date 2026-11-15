<?php

/**
 * Dossiq: the fields that name which stored object a write targets
 *
 * OpenRegister reads `id` or `@self.id` in a payload as the object to write,
 * so a create whose payload carries one becomes an update of that object. A
 * create built from caller-supplied fields removes these keys first, so the
 * caller cannot point it at an object they reached no other way.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git-id>
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The identity keys a create removes from caller data, for `array_diff_key()`.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
final class CallerIdentity {

	/**
	 * The keys, as the keys of this map.
	 */
	public const KEYS = ['id' => true, 'uuid' => true, '@self' => true];
}//end class
