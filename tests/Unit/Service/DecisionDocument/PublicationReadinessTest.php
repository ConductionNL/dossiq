<?php

/**
 * A case with an assembled decision reads ready to publish, unless it was published already.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\DecisionDocument\PublicationReadiness;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\DecisionDocument\PublicationReadiness
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\DecisionDocument\CaseDecisionLink
 */
class PublicationReadinessTest extends TestCase {

	/**
	 * The marker on the case schema.
	 *
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 *
	 * @return PublicationReadiness
	 */
	private function readiness(?LoggerInterface $logger = null): PublicationReadiness {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['case_schema' => 'case'][$key] ?? $default
		);

		return new PublicationReadiness(settingsService: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end readiness()

	/**
	 * An unpublished case reads ready; a published one keeps its state; a missing one is left alone.
	 *
	 * @return void
	 */
	public function testTheCaseReadsReadyUnlessPublished(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Open']);
		$register->seed(schema: 'case', uuid: 'case-2', row: ['title' => 'Done', 'wooPublicationStatus' => 'published']);

		$this->readiness()->markCaseReady(objectService: $register, register: 'dossiq', caseId: 'case-1');
		$this->readiness()->markCaseReady(objectService: $register, register: 'dossiq', caseId: 'case-2');
		$this->readiness()->markCaseReady(objectService: $register, register: 'dossiq', caseId: 'missing');

		self::assertSame('ready', $register->row(schema: 'case', uuid: 'case-1')['wooPublicationStatus']);
		self::assertSame('Open', $register->row(schema: 'case', uuid: 'case-1')['title']);
		self::assertSame('published', $register->row(schema: 'case', uuid: 'case-2')['wooPublicationStatus']);
		self::assertSame([], $register->row(schema: 'case', uuid: 'missing'));
	}//end testTheCaseReadsReadyUnlessPublished()

	/**
	 * A case that cannot be marked is logged and the call returns.
	 *
	 * @return void
	 */
	public function testACaseThatCannotBeMarkedIsLogged(): void {
		$register = new class extends InMemoryRegister {
			public function find(
				int|string $id,
				mixed $_extend = null,
				bool $files = false,
				int|string $register = '',
				int|string $schema = '',
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?array {
				throw new RuntimeException('down');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->readiness(logger: $logger)->markCaseReady(objectService: $register, register: 'dossiq', caseId: 'case-1');
	}//end testACaseThatCannotBeMarkedIsLogged()
}//end class
