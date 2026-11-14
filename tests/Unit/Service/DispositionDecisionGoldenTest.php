<?php

/**
 * Approving and rejecting a complaint disposition.
 *
 * Golden over DispositionService::approveDisposition() and
 * rejectDisposition() before they share one decision write: without
 * OpenRegister the decision is refused; otherwise the approval status and the
 * deciding user are patched onto the stored disposition of the configured
 * schema, the decision is logged, and the stored disposition comes back, or
 * the written fields with the id when the store answers no row.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DispositionService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Both decisions write, log and answer the same way.
 *
 * @covers \OCA\Dossiq\Service\DispositionService
 *
 * @spec openspec/specs/case-management/spec.md
 */
class DispositionDecisionGoldenTest extends TestCase {
	/**
	 * Both decisions, with the status they write and the verb they log.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function decisions(): array {
		return [
			'approve' => ['approveDisposition', 'approved', 'approved'],
			'reject' => ['rejectDisposition', 'rejected', 'rejected'],
		];
	}//end decisions()

	/**
	 * Without OpenRegister the decision is refused and nothing is logged.
	 *
	 * @param string $method The decision.
	 *
	 * @dataProvider decisions
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheDecisionIsRefused(string $method): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::never())->method('info');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available');
		(new DispositionService(settingsService: $settings, logger: $logger))->$method('d-1', 'u-1');
	}//end testWithoutOpenRegisterTheDecisionIsRefused()

	/**
	 * The decision is patched, logged, and the stored disposition answered.
	 *
	 * @param string $method The decision.
	 * @param string $status The approval status it writes.
	 * @param string $verb   The verb it logs.
	 *
	 * @dataProvider decisions
	 *
	 * @return void
	 */
	public function testTheDecisionIsPatchedLoggedAndAnswered(string $method, string $status, string $verb): void {
		$store = $this->store(answer: ['id' => 'd-1', 'complaint' => 'c-1', 'approvalStatus' => $status]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Disposition d-1 ' . $verb . ' by u-1', ['app' => Application::APP_ID]);

		$result = (new DispositionService(settingsService: $this->settings(store: $store), logger: $logger))->$method('d-1', 'u-1');

		self::assertSame(expected: [['d-1', ['approvalStatus' => $status, 'goedkeurder' => 'u-1'], 'dossiq', 'disposition']], actual: $store->calls);
		self::assertSame(expected: ['id' => 'd-1', 'complaint' => 'c-1', 'approvalStatus' => $status], actual: $result);
	}//end testTheDecisionIsPatchedLoggedAndAnswered()

	/**
	 * When the store answers no row, the written fields come back with the id.
	 *
	 * @param string $method The decision.
	 * @param string $status The approval status it writes.
	 *
	 * @dataProvider decisions
	 *
	 * @return void
	 */
	public function testWithoutAStoredRowTheWrittenFieldsComeBack(string $method, string $status): void {
		$store = $this->store(answer: null);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$result = (new DispositionService(settingsService: $this->settings(store: $store), logger: $logger))->$method('d-1', 'u-1');

		self::assertSame(expected: ['approvalStatus' => $status, 'goedkeurder' => 'u-1', 'id' => 'd-1'], actual: $result);
	}//end testWithoutAStoredRowTheWrittenFieldsComeBack()

	/**
	 * Settings answering the given store, the register and the disposition schema.
	 *
	 * @param object $store The object service.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(object $store): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => 'dossiq', 'complaint_disposition_schema' => 'disposition'][$key] ?? '')
		);

		return $settings;
	}//end settings()

	/**
	 * An object service that records each patch and answers the given row.
	 *
	 * @param array<string, mixed>|null $answer What a patch answers.
	 *
	 * @return object The store.
	 */
	private function store(?array $answer): object {
		return new class ($answer) {
			/**
			 * The patches, as id, data, register and schema.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $answer What a patch answers.
			 */
			public function __construct(private readonly ?array $answer) {
			}//end __construct()

			/**
			 * Record a patch.
			 *
			 * @param string               $objectId The object.
			 * @param array<string, mixed> $data     The changes.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return array<string, mixed>|null The configured answer.
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): ?array {
				$this->calls[] = [$objectId, $data, $register, $schema];

				return $this->answer;
			}//end patchObject()
		};
	}//end store()
}//end class
