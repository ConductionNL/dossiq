<?php

/**
 * Test stub mirroring OpenRegister's LifecycleSubjectNotFoundException.
 *
 * OpenRegister raises this when the object a lifecycle call names does not
 * exist, and TransitionController maps it to HTTP 404 rather than to the 422 a
 * refused move answers. CaseActionProvider throws it deliberately, so the type
 * has to resolve in the dossiq unit suite and under the static analysers
 * without the OR app on the classpath.
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
 * The object a transition was asked for does not exist.
 */
class LifecycleSubjectNotFoundException extends RuntimeException {
}//end class
