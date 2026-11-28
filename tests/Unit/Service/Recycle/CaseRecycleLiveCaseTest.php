<?php

/**
 * Unit tests for reading the live case behind the retention clocks.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
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

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\Recycle\DeletionWindowReader;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Recycle\CaseRecycleService
 * @uses   \OCA\Dossiq\Service\Recycle\CaseStoreLocation
 * @uses   \OCA\Dossiq\Service\Recycle\DeletedCaseVisibility
 * @uses   \OCA\Dossiq\Service\Recycle\DeletionWindowReader
 * @uses   \OCA\Dossiq\Service\Recycle\LiveCaseReader
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRecycleLiveCaseTest extends TestCase {

	/**
	 * The service over an object service that answers the case, throws, or is absent.
	 *
	 * @param string $answer 'case', 'throw' or 'absent'.
	 * @param string $schema The configured case schema.
	 *
	 * @return CaseRecycleService The service.
	 */
	private function service(string $answer, string $schema = 'case'): CaseRecycleService {
		$objectService = new class($answer) {
			/**
			 * Constructor.
			 *
			 * @param string $answer What find() does.
			 */
			public function __construct(private readonly string $answer) {
			}

			/**
			 * The case, or a failure.
			 *
			 * @param string $id      The case id.
			 * @param mixed  $options The find options.
			 *
			 * @return array<string, string> The case.
			 */
			public function find(string $id, mixed $options): array {
				if ($this->answer === 'throw') {
					throw new RuntimeException(message: 'store down');
				}

				return ['id' => $id, 'title' => 'Dakkapel'];
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		if ($answer === 'absent') {
			$objectService = null;
		}

		$settings->method('getObjectService')->willReturn(value: $objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => $schema,
				default => $default,
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new CaseRecycleService(
			settingsService: $settings,
			windowReader: new DeletionWindowReader(settingsService: $settings, logger: $logger),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $logger
		);
	}//end service()

	/**
	 * A configured store answers the case as an array.
	 *
	 * @return void
	 */
	public function testTheLiveCaseIsReadFromTheStore(): void {
		self::assertSame(
			expected: ['id' => 'case-1', 'title' => 'Dakkapel'],
			actual: $this->service(answer: 'case')->liveCase(caseId: 'case-1')
		);
	}//end testTheLiveCaseIsReadFromTheStore()

	/**
	 * No id, no store, no case schema or a failing store each answer null.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsNull(): void {
		self::assertNull(actual: $this->service(answer: 'case')->liveCase(caseId: ''));
		self::assertNull(actual: $this->service(answer: 'absent')->liveCase(caseId: 'case-1'));
		self::assertNull(actual: $this->service(answer: 'case', schema: '')->liveCase(caseId: 'case-1'));
		self::assertNull(actual: $this->service(answer: 'throw')->liveCase(caseId: 'case-1'));
	}//end testAnUnreadableCaseIsNull()
}//end class
