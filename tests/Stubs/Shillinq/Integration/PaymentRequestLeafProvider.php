<?php

/**
 * shillinq's payment-request leaf, as dossiq resolves it by name.
 *
 * dossiq reads a case's payment state through this leaf
 * ({@see \OCA\Dossiq\Service\Money\CasePaymentReader}) and resolves the class
 * at runtime, so the app stays installable without shillinq. That resilience
 * is only exercised in tests if something supplies the class: without this
 * stub, `class_exists()` is always false, the reader always answers `stale`,
 * and static analysis reports the whole lookup as dead code.
 *
 * The signature mirrors shillinq's real provider verbatim
 * (lib/Integration/PaymentRequestLeafProvider.php, LEAF_ID
 * `shillinq-payment-requests`). It answers an empty envelope, which is the
 * honest stub answer: no requests on this object.
 *
 * @category Stub
 * @package  OCA\Shillinq\Integration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

/**
 * Test stub for shillinq's payment-request leaf.
 */
class PaymentRequestLeafProvider {
	/**
	 * The leaf id shillinq registers this provider under.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'shillinq-payment-requests';

	/**
	 * The payment requests raised on one object.
	 *
	 * @param string $register The host object's register slug.
	 * @param string $schema The host object's schema slug.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor, fee}` envelope.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return ['items' => [], 'total' => 0, 'nextCursor' => null, 'fee' => null];
	}//end list()
}//end class
