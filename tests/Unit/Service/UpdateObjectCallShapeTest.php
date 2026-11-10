<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The writes that replace a case go through openregister's
 * `updateObject(string $objectId, array $data, ...)`.
 *
 * The object service here is a mock of the published contract, so a call in
 * any other shape is a TypeError, and both writers catch that and report a
 * failed member rather than throwing it.
 *
 * @covers \OCA\Dossiq\BulkAction\SetCaseAttributeAction
 * @covers \OCA\Dossiq\Service\Support\CaseAssigneeWriter
 */
final class UpdateObjectCallShapeTest extends TestCase {

	/**
	 * The payloads the object service received, keyed by object id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Settings answering the register, the case schema and the object service.
	 *
	 * @return SettingsService The double.
	 */
	private function settings(): SettingsService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('updateObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->written[$objectId] = $data;

				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '3',
				'case_schema' => '11',
				default => '',
			}
		);

		return $settings;
	}//end settings()

	/**
	 * A committed attribute change is written onto the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testACommittedAttributeChangeIsWritten(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setObject(['confidentiality' => 'vertrouwelijk']);

		$result = (new SetCaseAttributeAction(settingsService: $this->settings(), l10n: $l10n))->apply(
			object: $object,
			parameters: ['property' => 'confidentiality', 'value' => 'openbaar'],
			commit: true,
		);

		$this->assertSame(expected: BulkJobMember::OUTCOME_APPLIED, actual: $result->getOutcome());
		$this->assertSame(expected: 'openbaar', actual: $this->written['case-1']['confidentiality']);
	}//end testACommittedAttributeChangeIsWritten()

	/**
	 * A reassigned case is written with its new handler and a journal entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function testAReassignedCaseIsWritten(): void {
		$writer = new CaseAssigneeWriter(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$written = $writer->reassignOne(
			caseId: 'case-2',
			case: ['assignee' => 'jan', 'activity' => '[]'],
			toUser: 'marieke',
			actorId: 'coord',
			batchId: 'batch-1',
		);

		$this->assertTrue(condition: $written);
		$this->assertSame(expected: 'marieke', actual: $this->written['case-2']['assignee']);
		$journal = json_decode($this->written['case-2']['activity'], true);
		$this->assertSame(expected: 'batch-1', actual: end($journal)['batchId']);
	}//end testAReassignedCaseIsWritten()
}//end class
