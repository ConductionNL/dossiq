<?php

/**
 * Dossiq case notice log.
 *
 * Each requester notice and its result, kept on the case's outbound
 * communications: read as the system, appended, written back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a notice on the case; a record that cannot be written is logged, the notice stands.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */
final class CaseNoticeLog {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param CaseFieldWriter|null $writer Partial writes to the stored case.
	 * @param LoggerInterface      $logger Logger.
	 */
	public function __construct(
		private readonly ?CaseFieldWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The record of this notice, from the transport's answer and the caller's extras.
	 *
	 * @param array<string, mixed> $result The delivery result.
	 * @param array<string, mixed> $extras More keys from the caller.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
	 */
	public function record(array $result, array $extras): array {
		$record = $extras + [
			'moment' => $result['moment'],
			'template' => $result['template'],
			'channel' => $result['channel'],
			'status' => $result['status'],
			'channelsTried' => $result['channelsTried'],
			'attemptedAt' => $result['attemptedAt'],
		];
		foreach (['messageId', 'sentAt', 'reasonCode', 'reason'] as $key) {
			if (isset($result[$key]) === true) {
				$record[$key] = $result[$key];
			}
		}

		return $record;
	}//end record()

	/**
	 * Append the record to the case's outbound communications; a failed write is logged.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $location The object service, register, schema and case id.
	 * @param array<string, mixed>                                 $record   The record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
	 */
	public function append(array $location, array $record): void {
		try {
			$list = $this->storedNotices(location: $location);
			$list[] = $record;
			$this->writeNotices(location: $location, list: $list);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq requester notice: case {case} could not record the notice',
				['case' => $location[3], 'reason' => $e->getMessage()]
			);
		}//end try
	}//end append()

	/**
	 * The notices the stored case already carries, as a list.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $location The object service, register, schema and case id.
	 *
	 * @return array<int|string, mixed>
	 */
	private function storedNotices(array $location): array {
		[$objectService, $register, $schema, $caseId] = $location;
		$stored = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId)
		);
		$list = [];
		if (is_array($stored) === true) {
			$list = ($stored['outboundCommunications'] ?? []);
		}

		if (is_array($list) === false) {
			$list = [];
		}

		return $list;
	}//end storedNotices()

	/**
	 * Write the case's outbound communications.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $location The object service, register, schema and case id.
	 * @param array<int|string, mixed>                             $list     The notices, the new one last.
	 *
	 * @return void
	 */
	private function writeNotices(array $location, array $list): void {
		[$objectService, $register, $schema, $caseId] = $location;
		$this->writer->write(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			case: ['id' => $caseId],
			changes: ['outboundCommunications' => array_values($list)],
		);
	}//end writeNotices()
}//end class
