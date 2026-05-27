<?php

/**
 * Procest ZGW Base Controller
 *
 * Abstract base class for all ZGW API controllers. Serves as the identity
 * marker used by ZgwAuthMiddleware to decide which controllers require JWT
 * validation and scope enforcement.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCP\AppFramework\Controller;

/**
 * Abstract base class for all ZGW API controllers.
 *
 * ZgwAuthMiddleware uses `instanceof ZgwController` to identify which
 * controllers fall under ZGW JWT authentication and scope enforcement.
 * Any controller that handles a ZGW API endpoint must extend this class
 * so the middleware's guard is actually exercised.
 */
abstract class ZgwController extends Controller
{
}//end class
