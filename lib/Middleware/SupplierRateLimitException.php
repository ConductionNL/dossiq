<?php

/**
 * Supplier Rate Limit Exception
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-04-supplier-scope-security/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Middleware;

class SupplierRateLimitException extends \Exception
{
}
