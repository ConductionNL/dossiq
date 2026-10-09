<?php

/**
 * Dossiq Woo refusal grounds: the list could not be read.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use RuntimeException;

/**
 * Thrown instead of answering an empty list, which would read as "no grounds exist".
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
 */
class WooRefusalGroundsUnavailable extends RuntimeException {
}//end class
