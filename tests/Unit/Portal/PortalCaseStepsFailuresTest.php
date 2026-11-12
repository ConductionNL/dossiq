<?php

/**
 * Portal case steps failures test
 *
 * A read that fails costs the resident the step list and not the page: every
 * missing piece (schema, case, case type) answers [], and a read that throws
 * is logged and answers [].
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseSteps;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Portal\PortalCaseSteps
 * @uses   \OCA\Dossiq\Portal\PortalCaseReads
 * @uses   \OCA\Dossiq\Service\Support\UnscopedObjectReader
 */
class PortalCaseStepsFailuresTest extends TestCase {
	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * The configured values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'];

	/**
	 * The settings over a given object service.
	 *
	 * @param ObjectServiceInterface|null $objects The object service.
	 *
	 * @return SettingsService
	 */
	private function settings(?ObjectServiceInterface $objects): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		return $settings;
	}//end settings()

	/**
	 * An object service that runs as the system and finds the given case.
	 *
	 * @param array<string, mixed>|null $case The case, or null.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(?array $case): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$entity = null;
		if ($case !== null) {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($case);
		}

		$objects->method('find')->willReturn($entity);
		$objects->expects($this->never())->method('searchObjectsBySlug');
		return $objects;
	}//end objects()

	/**
	 * Without an object service or a case id there is nothing to read.
	 *
	 * @return void
	 */
	public function testNoObjectServiceOrCaseIdAnswersNothing(): void {
		$this->assertSame([], (new PortalCaseSteps($this->settings(null), new NullLogger()))->forCase(self::CASE_ID));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('runAsSystem');
		$this->assertSame([], (new PortalCaseSteps($this->settings($objects), new NullLogger()))->forCase(''));
	}//end testNoObjectServiceOrCaseIdAnswersNothing()

	/**
	 * Without a configured status type schema the case is not read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaAnswersNothing(): void {
		$this->config['status_type_schema'] = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->expects($this->never())->method('find');

		$this->assertSame([], (new PortalCaseSteps($this->settings($objects), new NullLogger()))->forCase(self::CASE_ID));
	}//end testAnUnconfiguredSchemaAnswersNothing()

	/**
	 * A case that is not there, or has no case type, has no steps and no search.
	 *
	 * @return void
	 */
	public function testAMissingCaseOrCaseTypeAnswersNothing(): void {
		$this->assertSame([], (new PortalCaseSteps($this->settings($this->objects(null)), new NullLogger()))->forCase(self::CASE_ID));
		$this->assertSame(
			[],
			(new PortalCaseSteps($this->settings($this->objects(['id' => self::CASE_ID, 'caseType' => '  '])), new NullLogger()))->forCase(self::CASE_ID)
		);
	}//end testAMissingCaseOrCaseTypeAnswersNothing()

	/**
	 * A read that throws is logged with the case and answers [].
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAnswersNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('find')->willThrowException(new RuntimeException('store down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the public steps of a case could not be read',
			['case' => self::CASE_ID, 'error' => 'store down']
		);

		$this->assertSame([], (new PortalCaseSteps($this->settings($objects), $logger))->forCase(self::CASE_ID));
	}//end testAFailingReadIsLoggedAndAnswersNothing()
}//end class
