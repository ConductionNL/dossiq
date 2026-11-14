<?php

/**
 * Unit tests for PartyKindConsumer::declareAcceptance(): a case type writes
 * which party kinds it accepts into pipelinq's register, as a create the first
 * time and as a patch after, and says why when it cannot.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Pipelinq\PartyKindConsumer
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\People\PartyVocabulary
 */
class PartyKindAcceptanceTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A consumer whose gateway finds pipelinq installed or not.
	 *
	 * @param bool $pipelinq Whether pipelinq's contact-moment provider answers.
	 */
	private function consumer(bool $pipelinq = true): PartyKindConsumer {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($pipelinq): object {
				if ($pipelinq === false || $id !== PipelinqGateway::CONTACT_MOMENTS) {
					throw new RuntimeException('nothing answers to ' . $id);
				}

				return new class {
					public function list(): array {
						return [];
					}
				};
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PartyKindConsumer(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			new PartyVocabulary($l10n),
			$this->settings,
			$this->logger
		);
	}//end consumer()

	/**
	 * An object service holding the given acceptance rows, recording writes.
	 *
	 * @param list<mixed> $rows The rows findAll answers.
	 */
	private function objectService(array $rows = []): object {
		return new class($rows) {
			/** @var list<array<string, mixed>> */
			public array $writes = [];

			/** @var list<array<string, mixed>> */
			public array $searches = [];

			public function __construct(private array $rows) {
			}

			public function findAll(array $config): array {
				$this->searches[] = $config;
				return $this->rows;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->writes[] = ['save', $object, $register, $schema];
				return $object;
			}

			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				$this->writes[] = ['patch', $objectId, $data, $register, $schema];
				return $data;
			}
		};
	}//end objectService()

	public function testTheTargetIsTheCaseTypeUnderTheDossiqPrefix(): void {
		$this->assertSame('dossiq:case:omgevingsvergunning', $this->consumer()->targetFor(' omgevingsvergunning '));
	}//end testTheTargetIsTheCaseTypeUnderTheDossiqPrefix()

	public function testAFirstDeclarationCreatesTheRowWithDedupedKinds(): void {
		$service = $this->objectService();
		$this->settings->method('getObjectService')->willReturn($service);

		$result = $this->consumer()->declareAcceptance('bezwaar', [' person ', 'organisation', 'person', '', 3]);

		$this->assertSame(['declared' => true, 'target' => 'dossiq:case:bezwaar', 'reason' => ''], $result);
		$this->assertSame(
			[['save', ['recordType' => 'dossiq:case:bezwaar', 'kinds' => ['person', 'organisation', '3']], 'pipelinq', 'partyKindAcceptance']],
			$service->writes
		);
		$this->assertSame(
			[['filters' => ['register' => 'pipelinq', 'schema' => 'partyKindAcceptance', 'recordType' => 'dossiq:case:bezwaar'], 'limit' => 10]],
			$service->searches
		);
	}//end testAFirstDeclarationCreatesTheRowWithDedupedKinds()

	public function testARedeclarationPatchesTheMatchingRowOnly(): void {
		$service = $this->objectService(
			[
				'not a row',
				['recordType' => 'dossiq:case:other', 'id' => 'row-0'],
				['recordType' => 'dossiq:case:bezwaar', 'id' => ' '],
				['recordType' => 'dossiq:case:bezwaar', 'uuid' => 'row-2'],
			]
		);
		$this->settings->method('getObjectService')->willReturn($service);

		$result = $this->consumer()->declareAcceptance('bezwaar', ['person']);

		$this->assertTrue($result['declared']);
		$this->assertSame(
			[['patch', 'row-2', ['recordType' => 'dossiq:case:bezwaar', 'kinds' => ['person']], 'pipelinq', 'partyKindAcceptance']],
			$service->writes
		);
	}//end testARedeclarationPatchesTheMatchingRowOnly()

	public function testNoKindsMeansNoDeclarationAndNoLookup(): void {
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame(
			['declared' => false, 'target' => 'dossiq:case:bezwaar', 'reason' => 'no kinds were named'],
			$this->consumer()->declareAcceptance('bezwaar', [' ', ''])
		);
	}//end testNoKindsMeansNoDeclarationAndNoLookup()

	public function testWithoutAWritableObjectServiceOpenRegisterIsUnavailable(): void {
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls(
			null,
			new class {
			},
			$this->throwException(new RuntimeException('boot'))
		);
		$consumer = $this->consumer();
		$expected = ['declared' => false, 'target' => 'dossiq:case:bezwaar', 'reason' => 'OpenRegister is unavailable'];

		$this->assertSame($expected, $consumer->declareAcceptance('bezwaar', ['person']));
		$this->assertSame($expected, $consumer->declareAcceptance('bezwaar', ['person']));
		$this->assertSame($expected, $consumer->declareAcceptance('bezwaar', ['person']));
	}//end testWithoutAWritableObjectServiceOpenRegisterIsUnavailable()

	public function testWithoutPipelinqNothingIsWritten(): void {
		$service = $this->objectService();
		$this->settings->method('getObjectService')->willReturn($service);

		$this->assertSame(
			['declared' => false, 'target' => 'dossiq:case:bezwaar', 'reason' => 'pipelinq is not installed'],
			$this->consumer(pipelinq: false)->declareAcceptance('bezwaar', ['person'])
		);
		$this->assertSame([], $service->writes);
	}//end testWithoutPipelinqNothingIsWritten()

	public function testAFailingWriteIsLoggedAndItsMessageIsTheReason(): void {
		$service = new class {
			public function findAll(array $config): array {
				return [];
			}

			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('schema partyKindAcceptance not found');
			}
		};
		$this->settings->method('getObjectService')->willReturn($service);
		$this->logger->expects($this->once())->method('debug')->with(
			$this->stringContains('schema partyKindAcceptance not found'),
			['target' => 'dossiq:case:bezwaar']
		);

		$this->assertSame(
			['declared' => false, 'target' => 'dossiq:case:bezwaar', 'reason' => 'schema partyKindAcceptance not found'],
			$this->consumer()->declareAcceptance('bezwaar', ['person'])
		);
	}//end testAFailingWriteIsLoggedAndItsMessageIsTheReason()
}//end class
