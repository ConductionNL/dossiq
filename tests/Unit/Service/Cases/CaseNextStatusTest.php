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
}//end class
