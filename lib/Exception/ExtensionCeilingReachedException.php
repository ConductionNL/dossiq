<?php

/**
 * Dossiq extension-ceiling exception.
 *
 * A term definition allows a number of ordinary extensions (Awb 4:14, and for
 * a Woo request Woo art. 4.4 lid 2: one). This is the refusal when they are
 * used up. It extends `RuntimeException` and keeps the message it always had,
 * so every caller that caught the bare runtime error still does; a caller that
 * owes its client a 409 can now tell this refusal apart from a broken store.
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
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
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;

/**
 * The term's extensions are used up (REQ-WTR-003).
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class ExtensionCeilingReachedException extends RuntimeException {
}//end class
