<?php

/**
 * DossierRegister: the object service, the register and the case schema, or the exception naming what is missing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Custody
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Built over an in-memory register; only the settings bridge is mocked.
 *
 * @covers \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class DossierRegisterTest extends TestCase {

	/**
	 * The rule every refusal in these tests carries.
	 */
	private const RULE = 'case-unreadable';

	/**
	 * The sentence every refusal in these tests carries.
	 */
	private const SENTENCE = 'The case could not be read, nothing was changed.';

	/**
	 * The register under test, over `$store` and the given configuration.
	 *
	 * @param object|null           $store  The object service, or null when OpenRegister is absent.
	 * @param array<string, string> $config The configuration values.
	 *
	 * @return DossierRegister
	 */
	private function register(?object $store, array $config = ['register' => 'dossiq', 'case_schema' => 'case']): DossierRegister {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(value: $store);
		$settings->method('getConfigValue')->willReturnCallback(
			callback: static fn (string $key): string => ($config[$key] ?? '')
		);

		return new DossierRegister(settingsService: $settings);
	}//end register()

	/**
	 * Without OpenRegister the context says so.
	 *
	 * @return void
	 */
	public function testContextWithoutOpenRegisterThrows(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available');

		$this->register(store: null)->context();
	}//end testContextWithoutOpenRegisterThrows()

	/**
	 * Without a configured register the context says so.
	 *
	 * @return void
	 */
	public function testContextWithoutARegisterThrows(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Dossier register not configured');

		$this->register(store: new InMemoryRegister(), config: ['case_schema' => 'case'])->context();
	}//end testContextWithoutARegisterThrows()

	/**
	 * An unset schema key is named in the exception.
	 *
	 * @return void
	 */
	public function testAnUnsetSchemaNamesItsKey(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Dossiq schema custody_schema not configured');

		$this->register(store: new InMemoryRegister())->schema(key: 'custody_schema');
	}//end testAnUnsetSchemaNamesItsKey()

	/**
	 * A stored case is answered as stored.
	 *
	 * @return void
	 */
	public function testRequireCaseAnswersTheStoredCase(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Case one']);

		$case = $this->register(store: $store)->requireCase(caseId: 'c-1', rule: self::RULE, sentence: self::SENTENCE);

		self::assertSame(expected: ['title' => 'Case one', 'id' => 'c-1'], actual: $case);
	}//end testRequireCaseAnswersTheStoredCase()

	/**
	 * A case that is not there is unprocessable, with the given rule and sentence.
	 *
	 * @return void
	 */
	public function testAnAbsentCaseIsUnprocessable(): void {
		try {
			$this->register(store: new InMemoryRegister())->requireCase(caseId: 'nope', rule: self::RULE, sentence: self::SENTENCE);
			self::fail(message: 'An absent case was answered.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
			self::assertSame(expected: self::RULE, actual: $e->getRule());
			self::assertSame(expected: self::SENTENCE, actual: $e->getSentence());
			self::assertNull(actual: $e->getPrevious());
		}
	}//end testAnAbsentCaseIsUnprocessable()

	/**
	 * A read that fails is indeterminate and keeps the failure underneath.
	 *
	 * @return void
	 */
	public function testAFailedReadIsIndeterminate(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Case one']);
		$store->refusedToCaller = ['case'];

		try {
			$this->register(store: $store)->requireCase(caseId: 'c-1', rule: self::RULE, sentence: self::SENTENCE);
			self::fail(message: 'A refused read was answered.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
			self::assertSame(expected: self::RULE, actual: $e->getRule());
			self::assertInstanceOf(expected: RuntimeException::class, actual: $e->getPrevious());
		}
	}//end testAFailedReadIsIndeterminate()

	/**
	 * Without a case schema the read is indeterminate too: nothing says the case is not there.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaIsIndeterminate(): void {
		try {
			$this->register(store: new InMemoryRegister(), config: ['register' => 'dossiq'])->requireCase(
				caseId: 'c-1',
				rule: self::RULE,
				sentence: self::SENTENCE
			);
			self::fail(message: 'A case was answered without a case schema.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
			self::assertSame(expected: 'Dossiq schema case_schema not configured', actual: $e->getPrevious()?->getMessage());
		}
	}//end testAnUnconfiguredCaseSchemaIsIndeterminate()

	/**
	 * A save writes the payload over the case, under the case schema and its own uuid.
	 *
	 * @return void
	 */
	public function testSaveCaseWritesOverTheStoredCase(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'case', uuid: 'c-1', row: ['title' => 'Old']);

		$this->register(store: $store)->saveCase(caseId: 'c-1', payload: ['title' => 'New']);

		self::assertSame(expected: ['title' => 'New', 'id' => 'c-1'], actual: $store->row(schema: 'case', uuid: 'c-1'));
		self::assertSame(expected: 1, actual: $store->writes);
	}//end testSaveCaseWritesOverTheStoredCase()

	/**
	 * Without a case schema a save writes nothing.
	 *
	 * @return void
	 */
	public function testSaveCaseWithoutACaseSchemaWritesNothing(): void {
		$store = new InMemoryRegister();

		try {
			$this->register(store: $store, config: ['register' => 'dossiq'])->saveCase(caseId: 'c-1', payload: ['title' => 'New']);
			self::fail(message: 'A save ran without a case schema.');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'Dossiq schema case_schema not configured', actual: $e->getMessage());
		}

		self::assertSame(expected: 0, actual: $store->writes);
	}//end testSaveCaseWithoutACaseSchemaWritesNothing()
}//end class
