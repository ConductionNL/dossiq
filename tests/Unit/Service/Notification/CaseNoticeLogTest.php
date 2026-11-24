<?php

/**
 * Unit tests for the case's notice log.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\Notification\CaseNoticeLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Notification\CaseNoticeLog
 */
class CaseNoticeLogTest extends TestCase {

	/**
	 * A record keeps the result's fields, adds the optional ones only when present, and keeps the caller's extras.
	 *
	 * @return void
	 */
	public function testARecordCarriesTheResultAndOnlyThePresentOptionalFields(): void {
		$log = new CaseNoticeLog(writer: null, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$result = [
			'moment' => 'extension',
			'template' => 'extension',
			'channel' => 'email',
			'status' => 'sent',
			'channelsTried' => [],
			'attemptedAt' => '2026-11-24T09:00:00+00:00',
			'sentAt' => '2026-11-24T09:00:01+00:00',
		];

		self::assertSame(
			expected: [
				'by' => 'anna',
				'moment' => 'extension',
				'template' => 'extension',
				'channel' => 'email',
				'status' => 'sent',
				'channelsTried' => [],
				'attemptedAt' => '2026-11-24T09:00:00+00:00',
				'sentAt' => '2026-11-24T09:00:01+00:00',
			],
			actual: $log->record(result: $result, extras: ['by' => 'anna'])
		);
	}//end testARecordCarriesTheResultAndOnlyThePresentOptionalFields()
}//end class
