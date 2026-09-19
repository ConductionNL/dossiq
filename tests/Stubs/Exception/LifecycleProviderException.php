<?php

/**
 * Test stub mirroring OpenRegister's LifecycleProviderException.
 *
 * OpenRegister raises this when a provider-mode lifecycle cannot be read, and
 * TransitionController maps it to HTTP 502 rather than to an empty action list.
 * CaseActionProvider throws it deliberately, so the type has to resolve in the
 * dossiq unit suite and under the static analysers without the OR app on the
 * classpath.
 *
 * 🔴 THE PARENT IS COPIED, NOT PARAPHRASED. Upstream extends RuntimeException
 * so every existing lifecycle catch site keeps behaving as it did; a stub that
 * extended Exception would let a test pass on a catch that never fires live.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A provider-mode lifecycle could not be read.
 */
class LifecycleProviderException extends RuntimeException {
}//end class
