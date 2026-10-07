<?php

/**
 * Dossiq ServiceAccountUnavailableException.
 *
 * Thrown when a service account cannot be used, before anything runs. The
 * caller skips its work: nothing is written, and nothing reads as done.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ServiceAccount
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ServiceAccount;

use RuntimeException;

/**
 * No usable service account: nothing was written.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class ServiceAccountUnavailableException extends RuntimeException {
}//end class
