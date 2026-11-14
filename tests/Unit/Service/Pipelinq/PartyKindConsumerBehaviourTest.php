<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use JsonSerializable;
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
 * Which party kinds a case type offers, where that answer came from, and how
 * an existing acceptance row is found before it is patched.
 *
 * @covers \OCA\Dossiq\Service\Pipelinq\PartyKindConsumer
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\People\PartyVocabulary
 */
class PartyKindConsumerBehaviourTest extends TestCase {

	private SettingsService&MockObject $settings;

	private PartyVocabulary $vocabulary;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->vocabulary = new PartyVocabulary($l10n);
	}//end setUp()

	/**
	 * A consumer over a pipelinq that holds the given services.
	 *
	 * @param array<string, object> $services Keyed by class name.
	 */
	private function consumer(array $services = []): PartyKindConsumer {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $services[$id];
			}
		);

		return new PartyKindConsumer(
			new PipelinqGateway($container, $this->createMock(LoggerInterface::class)),
			$this->vocabulary,
			$this->settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end consumer()

	/**
	 * A party kind registry answering fixed values and recording the record types asked.
	 */
	private function registry(mixed $kinds = null, mixed $vocabulary = null, mixed $acceptance = null): object {
		return new class($kinds, $vocabulary, $acceptance) {
			/** @var list<string> */
			public array $asked = [];

			public function __construct(private mixed $kinds, private mixed $vocabulary, private mixed $acceptance) {
			}

			public function kindsFor(string $recordType): mixed {
				$this->asked[] = $recordType;
				return $this->kinds;
			}

			public function vocabulary(): mixed {
				return $this->vocabulary;
			}

			public function acceptanceFor(string $recordType): mixed {
				$this->asked[] = $recordType;
				return $this->acceptance;
			}
		};
	}//end registry()

	/**
	 * The services an installed pipelinq answers with: the probe and the registry.
	 *
	 * @return array<string, object>
	 */
	private function installed(): array {
		return [
			PipelinqGateway::CONTACT_MOMENTS => new class {
				public function list(): array {
					return [];
				}
			},
		];
	}//end installed()

	/**
	 * An object service whose findAll answers or throws, recording saves and patches.
	 */
	private function objectService(mixed $rows): object {
		return new class($rows) {
			/** @var list<array<string, mixed>> */
			public array $saved = [];

			/** @var list<array<string, mixed>> */
			public array $patched = [];

			public function __construct(private mixed $rows) {
			}

			public function findAll(array $config): array {
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[] = $object;
				return $object;
			}

			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				$this->patched[] = ['id' => $objectId, 'data' => $data];
				return $data;
			}
		};
	}//end objectService()

	public function testWithoutPipelinqTheKindsAreDossiqsOwn(): void {
		$this->assertSame(
			['source' => 'dossiq', 'kinds' => $this->vocabulary->kinds()],
			$this->consumer()->kindsFor(caseType: 'permit')
		);
	}//end testWithoutPipelinqTheKindsAreDossiqsOwn()

	public function testPipelinqsKindsAreAskedForTheTrimmedTargetAndFilteredToArrays(): void {
		$registry = $this->registry(kinds: ['a' => ['key' => 'person'], 'b' => 'junk', 'c' => ['key' => 'pet']]);

		$result = $this->consumer([PipelinqGateway::PARTY_KINDS => $registry])->kindsFor(caseType: ' permit ');

		$this->assertSame(['source' => 'pipelinq', 'kinds' => [['key' => 'person'], ['key' => 'pet']]], $result);
		$this->assertSame(['dossiq:case:permit'], $registry->asked);
	}//end testPipelinqsKindsAreAskedForTheTrimmedTargetAndFilteredToArrays()

	public function testAnEmptyPipelinqAnswerIsStillPipelinqsAnswer(): void {
		$result = $this->consumer([PipelinqGateway::PARTY_KINDS => $this->registry(kinds: ['junk'])])->kindsFor(caseType: 'x');

		$this->assertSame(['source' => 'pipelinq', 'kinds' => []], $result);
	}//end testAnEmptyPipelinqAnswerIsStillPipelinqsAnswer()

	public function testANonArrayKindsAnswerFallsBackToDossiq(): void {
		$result = $this->consumer([PipelinqGateway::PARTY_KINDS => $this->registry(kinds: 'none')])->kindsFor(caseType: 'x');

		$this->assertSame('dossiq', $result['source']);
		$this->assertSame($this->vocabulary->kinds(), $result['kinds']);
	}//end testANonArrayKindsAnswerFallsBackToDossiq()

	public function testTheVocabularyComesFromPipelinqWhenItAnswers(): void {
		$registry = $this->registry(vocabulary: [5 => ['key' => 'person'], 6 => null]);

		$this->assertSame(
			['source' => 'pipelinq', 'kinds' => [['key' => 'person']]],
			$this->consumer([PipelinqGateway::PARTY_KINDS => $registry])->vocabulary()
		);
	}//end testTheVocabularyComesFromPipelinqWhenItAnswers()

	public function testTheVocabularyFallsBackToDossiq(): void {
		$this->assertSame(
			['source' => 'dossiq', 'kinds' => $this->vocabulary->kinds()],
			$this->consumer([PipelinqGateway::PARTY_KINDS => $this->registry(vocabulary: false)])->vocabulary()
		);
	}//end testTheVocabularyFallsBackToDossiq()

	public function testAcceptanceIsTrimmedAndReindexed(): void {
		$registry = $this->registry(acceptance: ['x' => ' person ', 'y' => 7]);

		$this->assertSame(
			['person', '7'],
			$this->consumer([PipelinqGateway::PARTY_KINDS => $registry])->acceptanceOf(caseType: 'permit')
		);
		$this->assertSame(['dossiq:case:permit'], $registry->asked);
	}//end testAcceptanceIsTrimmedAndReindexed()

	public function testAnUnreadableAcceptanceIsNull(): void {
		$this->assertNull($this->consumer()->acceptanceOf(caseType: 'permit'));
		$this->assertNull(
			$this->consumer([PipelinqGateway::PARTY_KINDS => $this->registry(acceptance: 'all')])->acceptanceOf(caseType: 'permit')
		);
	}//end testAnUnreadableAcceptanceIsNull()

	public function testAnEmptyAcceptanceIsAnEmptyListNotNull(): void {
		$this->assertSame(
			[],
			$this->consumer([PipelinqGateway::PARTY_KINDS => $this->registry(acceptance: [])])->acceptanceOf(caseType: 'permit')
		);
	}//end testAnEmptyAcceptanceIsAnEmptyListNotNull()

	public function testAFailingLookupCreatesARowInsteadOfPatching(): void {
		$service = $this->objectService(new RuntimeException('index down'));
		$this->settings->method('getObjectService')->willReturn($service);

		$result = $this->consumer($this->installed())->declareAcceptance(caseType: 'permit', kinds: ['person']);

		$this->assertSame(['declared' => true, 'target' => 'dossiq:case:permit', 'reason' => ''], $result);
		$this->assertSame([['recordType' => 'dossiq:case:permit', 'kinds' => ['person']]], $service->saved);
		$this->assertSame([], $service->patched);
	}//end testAFailingLookupCreatesARowInsteadOfPatching()

	public function testASerializableRowIsFoundByItsUuid(): void {
		$row = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['uuid' => ' u-1 ', 'recordType' => 'dossiq:case:permit'];
			}
		};
		$service = $this->objectService(
			[
				'not a row',
				['id' => 'other', 'recordType' => 'dossiq:case:other'],
				['id' => '  ', 'recordType' => 'dossiq:case:permit'],
				$row,
			]
		);
		$this->settings->method('getObjectService')->willReturn($service);

		$result = $this->consumer($this->installed())->declareAcceptance(caseType: 'permit', kinds: ['organisation', 'person']);

		$this->assertTrue($result['declared']);
		$this->assertSame([], $service->saved);
		$this->assertSame(
			[['id' => 'u-1', 'data' => ['recordType' => 'dossiq:case:permit', 'kinds' => ['organisation', 'person']]]],
			$service->patched
		);
	}//end testASerializableRowIsFoundByItsUuid()

	public function testAThrowingSettingsServiceMeansOpenRegisterIsUnavailable(): void {
		$this->settings->method('getObjectService')->willThrowException(new RuntimeException('not installed'));

		$this->assertSame(
			['declared' => false, 'target' => 'dossiq:case:permit', 'reason' => 'OpenRegister is unavailable'],
			$this->consumer($this->installed())->declareAcceptance(caseType: 'permit', kinds: ['person'])
		);
	}//end testAThrowingSettingsServiceMeansOpenRegisterIsUnavailable()

	public function testTheRolesAreDossiqsOwn(): void {
		$this->assertSame($this->vocabulary->roles(), $this->consumer()->roles());
		$this->assertNotSame([], $this->consumer()->roles());
	}//end testTheRolesAreDossiqsOwn()
}//end class
