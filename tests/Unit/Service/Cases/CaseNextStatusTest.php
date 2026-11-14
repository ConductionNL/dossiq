<?php

/**
 * DqTaak: the status a case moves to next, from its case type's order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseNextStatus;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Cases\CaseNextStatus
 */
class CaseNextStatusTest extends TestCase {

	/**
	 * The service over these rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 *
	 * @return CaseNextStatus The service.
	 */
	private function service(array $rows): CaseNextStatus {
		$objects = new class ($rows) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if (isset($this->rows['fail']) === true) {
					throw new \RuntimeException('register unreachable');
				}

				return $this->rows[$schema] ?? [];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');

		return new CaseNextStatus($settings, $l10n);
	}//end service()

	/**
	 * The status after the current one in the case type's order, in the user's language.
	 *
	 * @return void
	 */
	public function testTheNextStatusInOrder(): void {
		$service = $this->service([
			'case' => [['id' => 'c1', 'caseType' => 'ct', 'status' => 's2']],
			'statusType' => [
				['id' => 's3', 'order' => 3, 'name' => ['nl' => 'Beslissing op bezwaar', 'en' => 'Decision on the objection']],
				['id' => 's1', 'order' => 1, 'name' => 'Ontvangen'],
				['id' => 's2', 'order' => 2, 'name' => 'Hoorzitting'],
			],
		]);

		$this->assertSame(['name' => 'Beslissing op bezwaar', 'number' => 3], $service->forCase(caseId: 'c1'));
	}//end testTheNextStatusInOrder()

	/**
	 * The last status has no next one; an unreadable case gives no answer.
	 *
	 * @return void
	 */
	public function testTheLastStatusAndAnUnreadableCase(): void {
		$service = $this->service([
			'case' => [['id' => 'c1', 'caseType' => 'ct', 'status' => ['id' => 's2']]],
			'statusType' => [['id' => 's1', 'order' => 1, 'name' => 'Ontvangen'], ['id' => 's2', 'order' => 2, 'name' => 'Afgehandeld']],
		]);

		$this->assertSame([], $service->forCase(caseId: 'c1'));
		$this->assertNull($this->service([])->forCase(caseId: 'c1'));
	}//end testTheLastStatusAndAnUnreadableCase()

	/**
	 * A register that cannot be read is not "case not found": the failure
	 * reaches the controller.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsNotNotFound(): void {
		$this->expectException(\RuntimeException::class);
		$this->service(rows: ['fail' => []])->forCase(caseId: 'case-1');
	}//end testAnUnreadableRegisterIsNotNotFound()

	/**
	 * A case without a case type or a current status has no next status.
	 *
	 * @return void
	 */
	public function testACaseWithoutTypeOrStatusHasNoNextStatus(): void {
		$statuses = [['id' => 's1', 'order' => 1, 'name' => 'Ontvangen'], ['id' => 's2', 'order' => 2, 'name' => 'Afgehandeld']];

		$this->assertSame([], $this->service(['case' => [['id' => 'c1', 'status' => 's1']], 'statusType' => $statuses])->forCase(caseId: 'c1'));
		$this->assertSame([], $this->service(['case' => [['id' => 'c1', 'caseType' => 'ct']], 'statusType' => $statuses])->forCase(caseId: 'c1'));
		// A reference that is neither a uuid nor a row with an id is no reference.
		$this->assertSame(
			[],
			$this->service(['case' => [['id' => 'c1', 'caseType' => 'ct', 'status' => ['id' => ['nested']]]], 'statusType' => $statuses])->forCase(caseId: 'c1')
		);
	}//end testACaseWithoutTypeOrStatusHasNoNextStatus()

	/**
	 * A plain-string next name is trimmed; a status referenced through `@self` is found.
	 *
	 * @return void
	 */
	public function testAPlainNameAndASelfReference(): void {
		$service = $this->service([
			'case' => [['id' => 'c1', 'caseType' => ['@self' => ['id' => 'ct']], 'status' => ['@self' => ['id' => 's1']]]],
			'statusType' => [['id' => 's1', 'order' => 1, 'name' => 'Ontvangen'], ['id' => 's2', 'order' => 2, 'name' => '  In behandeling ']],
		]);

		$this->assertSame(['name' => 'In behandeling', 'number' => 2], $service->forCase(caseId: 'c1'));
	}//end testAPlainNameAndASelfReference()

	/**
	 * A language-tagged name with nothing in the user's language, Dutch or English is an empty name.
	 *
	 * @return void
	 */
	public function testANameWithNoUsableLanguageIsEmpty(): void {
		$service = $this->service([
			'case' => [['id' => 'c1', 'caseType' => 'ct', 'status' => 's1']],
			'statusType' => [['id' => 's1', 'order' => 1, 'name' => 'Ontvangen'], ['id' => 's2', 'order' => 2, 'name' => ['nl' => '  ', 'de' => 'Entscheidung']]],
		]);

		$this->assertSame(['name' => '', 'number' => 2], $service->forCase(caseId: 'c1'));
	}//end testANameWithNoUsableLanguageIsEmpty()

	/**
	 * Without OpenRegister, or without a configured register, nothing is read and the case gives no answer.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsRead(): void {
		$l10n = $this->createMock(IL10N::class);

		$noService = $this->createMock(SettingsService::class);
		$noService->method('getObjectService')->willReturn(null);
		$noService->method('getConfigValue')->willReturn('dossiq');
		$this->assertNull((new CaseNextStatus($noService, $l10n))->forCase(caseId: 'c1'));

		$objects = $this->getMockBuilder(\stdClass::class)->addMethods(['searchObjectsBySlug', 'searchObjects'])->getMock();
		$objects->expects($this->never())->method('searchObjectsBySlug');
		$objects->expects($this->never())->method('searchObjects');
		$noRegister = $this->createMock(SettingsService::class);
		$noRegister->method('getObjectService')->willReturn($objects);
		$noRegister->method('getConfigValue')->willReturn('');
		$this->assertNull((new CaseNextStatus($noRegister, $l10n))->forCase(caseId: 'c1'));
	}//end testWithoutARegisterNothingIsRead()
}//end class
