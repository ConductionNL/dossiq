<?php

/**
 * When a case was received: its receipt dates, the receipt moment and the current time.
 *
 * @category Service
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\CaseReceiptDates;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * When a case was received: its receipt dates, the receipt moment and the current time.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */
final class ReceiptClock {
	/**
	 * Constructor.
	 *
	 * @param CaseReceiptDates $receiptDates The receipt and start dates a case is missing.
	 * @param ReceiptMoment    $receipt      Tells a receipt from a draft save.
	 * @param ITimeFactory     $time         The moment a draft is sent.
	 */
	public function __construct(
		public readonly CaseReceiptDates $receiptDates,
		public readonly ReceiptMoment $receipt,
		public readonly ITimeFactory $time,
	) {
	}//end __construct()
}//end class
