<?php

/**
 * Dossiq Quota Limit Label
 *
 * Words a quota limit with its unit, for the refusal and the soft limit notice.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IL10N;

/**
 * A quota limit in words.
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
 */
class QuotaLimitLabel {
	/**
	 * The limit in words, with its unit, in the given language.
	 *
	 * @param IL10N  $l         The localisation to word it in.
	 * @param string $quotaType The quota type.
	 * @param int    $limit     The limit.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
	 */
	public function label(IL10N $l, string $quotaType, int $limit): string {
		if ($quotaType === 'active_users') {
			return $l->t('%s users', [(string)$limit]);
		}

		if ($quotaType === 'storage_mb') {
			return $l->t('%s MB of storage', [(string)$limit]);
		}

		return $l->t('%s cases per month', [(string)$limit]);
	}//end label()
}//end class
