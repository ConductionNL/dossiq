<?php

/**
 * A retired step whose configuration has no faithful equivalent in its replacement.
 *
 * Thrown by {@see RetiredNodeTranslator} rather than guessing. The rewriter
 * leaves such a step in place and the repair step logs the message, which
 * names what could not be carried over, so an administrator can rebuild the
 * step by hand instead of finding out from a run that did something else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use RuntimeException;

/**
 * The step cannot be carried over faithfully; the message says why.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class UnmappableStep extends RuntimeException {
}//end class
