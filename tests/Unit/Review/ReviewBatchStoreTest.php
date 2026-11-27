<?php

/**
 * ReviewBatchStore Unit Tests
 *
 * A batch saved into the batch schema without its identifiers, created
 * first and replaced by its uuid after; a register that refuses the write
 * answered as `review-batch-unavailable` (503) with the cause, and logged
 * with the case.
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
use OCA\Dossiq\Review\ReviewBatchStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ReviewBatchStore.
 *
 * @covers \OCA\Dossiq\Review\ReviewBatchStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
final class ReviewBatchStoreTest extends TestCase {

	/**
	 * The register the store writes to.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A store over the in-memory register.
	 *
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 *
	 * @return ReviewBatchStore
	 */
	private function store(?LoggerInterface $logger = null): ReviewBatchStore {
		$this->register = new InMemoryRegister();
		$config = ['register' => 'dossiq', 'review_batch_schema' => 'reviewBatch'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		return new ReviewBatchStore(settingsService: $settings, logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class));
	}//end store()

	/**
	 * A new batch is stored without its identifiers; saving it again replaces it under its id.
	 *
	 * @return void
	 */
	public function testABatchIsCreatedAndThenReplacedUnderItsId(): void {
		$store = $this->store();

		$first = $store->save(batch: ['case' => 'case-x', 'name' => 'Mail', '@self' => ['x' => 1]]);
		$this->assertSame(expected: 'generated-1', actual: $first['id']);
		$this->assertSame(
			expected: ['case' => 'case-x', 'name' => 'Mail', 'id' => 'generated-1'],
			actual: $this->register->row(schema: 'reviewBatch', uuid: 'generated-1')
		);

		$again = $store->save(batch: $first + ['task' => 'task-1']);
		$this->assertSame(expected: 'generated-1', actual: $again['id']);
		$this->assertSame(expected: 'task-1', actual: $this->register->row(schema: 'reviewBatch', uuid: 'generated-1')['task']);
		$this->assertCount(expectedCount: 1, haystack: $this->register->all(schema: 'reviewBatch'));
	}//end testABatchIsCreatedAndThenReplacedUnderItsId()

	/**
	 * A register that refuses the write is answered as unavailable with the cause, and logged with the case.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsUnavailableAndLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq: a Review batch could not be written',
			$this->callback(callback: static fn (array $context): bool => $context['app'] === 'dossiq' && $context['case'] === 'case-x')
		);
		$store = $this->store(logger: $logger);
		$this->register->refusedSchemas = ['reviewBatch'];

		try {
			$store->save(batch: ['case' => 'case-x', 'name' => 'Mail']);
			$this->fail(message: 'A refused write must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'review-batch-unavailable', actual: $e->getRule());
			$this->assertSame(expected: 503, actual: $e->getStatus());
			$this->assertSame(expected: 'The Review batch cannot be stored, so nothing was assigned.', actual: $e->getSentence());
			$this->assertInstanceOf(expected: \RuntimeException::class, actual: $e->getPrevious());
		}
	}//end testARefusedWriteIsUnavailableAndLogged()
}//end class
