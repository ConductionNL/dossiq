<?php

/**
 * RecallSampleStore Unit Tests
 *
 * Where a case's Recall samples are kept: written in the configured schema
 * with their derived progress left out and their id kept, read back for the
 * one case only, nothing without a schema, and a refusal (with the cause, and
 * an error logged) when the register will not store a sample.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\RecallSampleStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RecallSampleStore.
 *
 * @covers \OCA\Dossiq\Review\RecallSampleStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
final class RecallSampleStoreTest extends TestCase {

	/**
	 * The register the store writes to.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A store over the in-memory register with the given sample schema.
	 *
	 * @param string               $schema The configured recall sample schema.
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 *
	 * @return RecallSampleStore
	 */
	private function store(string $schema = 'recallSample', ?LoggerInterface $logger = null): RecallSampleStore {
		$this->register = new InMemoryRegister();
		$config = ['register' => 'dossiq', 'recall_sample_schema' => $schema];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		return new RecallSampleStore(settingsService: $settings, logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class));
	}//end store()

	/**
	 * A new sample is stored without its derived fields; saving it again keeps its id.
	 *
	 * @return void
	 */
	public function testASampleIsStoredWithoutItsDerivedFieldsAndKeepsItsId(): void {
		$store = $this->store();

		$first = $store->save(sample: ['case' => 'case-x', 'documents' => ['doc-1'], 'progress' => ['judged' => 0], '@self' => ['x' => 1]]);
		$this->assertSame(expected: 'generated-1', actual: $first['id']);
		$this->assertSame(
			expected: ['case' => 'case-x', 'documents' => ['doc-1'], 'id' => 'generated-1'],
			actual: $this->register->row(schema: 'recallSample', uuid: 'generated-1')
		);

		$again = $store->save(sample: $first + ['batch' => 'batch-s']);
		$this->assertSame(expected: 'generated-1', actual: $again['id']);
		$this->assertSame(expected: 'batch-s', actual: $this->register->row(schema: 'recallSample', uuid: 'generated-1')['batch']);
		$this->assertCount(expectedCount: 1, haystack: $this->register->all(schema: 'recallSample'));
	}//end testASampleIsStoredWithoutItsDerivedFieldsAndKeepsItsId()

	/**
	 * Only the case's own samples are read back.
	 *
	 * @return void
	 */
	public function testOnlyTheCasesOwnSamplesAreRead(): void {
		$store = $this->store();
		$this->register->seed(schema: 'recallSample', uuid: 's-1', row: ['case' => 'case-x']);
		$this->register->seed(schema: 'recallSample', uuid: 's-2', row: ['case' => 'case-y']);

		$rows = $store->rows(caseId: 'case-x');

		$this->assertCount(expectedCount: 1, haystack: $rows);
		$this->assertSame(expected: 'case-x', actual: $rows[0]['case']);
		$this->assertSame(expected: [], actual: $store->rows(caseId: ''));
	}//end testOnlyTheCasesOwnSamplesAreRead()

	/**
	 * Without a sample schema nothing is read, and a save is refused.
	 *
	 * @return void
	 */
	public function testWithoutASchemaNothingIsReadAndASaveIsRefused(): void {
		$store = $this->store(schema: '');

		$this->assertSame(expected: [], actual: $store->rows(caseId: 'case-x'));
		try {
			$store->save(sample: ['case' => 'case-x']);
			$this->fail(message: 'A save without a schema must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'review-recall-sample-unavailable', actual: $e->getRule());
			$this->assertNull(actual: $e->getPrevious());
		}
	}//end testWithoutASchemaNothingIsReadAndASaveIsRefused()

	/**
	 * A register that will not store the sample is logged and refused with the cause.
	 *
	 * @return void
	 */
	public function testARegisterThatWillNotStoreIsLoggedAndRefused(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: a Recall sample could not be stored',
			['exception' => 'Save refused for recallSample']
		);
		$store = $this->store(logger: $logger);
		$this->register->refusedSchemas = ['recallSample'];

		try {
			$store->save(sample: ['case' => 'case-x']);
			$this->fail(message: 'A refused save must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 503, actual: $e->getStatus());
			$this->assertInstanceOf(expected: \RuntimeException::class, actual: $e->getPrevious());
		}
	}//end testARegisterThatWillNotStoreIsLoggedAndRefused()
}//end class
