<?php

/**
 * The Woo redaction hand-off reaches filinq, or refuses.
 *
 * WHAT THIS GUARDS. `WOORedactionService::queueViaDocuDesk()` reported every
 * document `queued` and made no call: there was no client, no request, and no
 * filinq method named anywhere in the file. Any test written against it would
 * have asserted a string the method wrote itself. So the assertions here are
 * about the two filinq methods that must be invoked, in order, with the file
 * id this app resolved. Remove the call and they fail on the recording, not on
 * a return value.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the filinq anonymisation hand-off.
 *
 * @covers \OCA\Dossiq\Service\FilinqRedactionClient
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class FilinqRedactionClientTest extends TestCase {

	/**
	 * The FQCN filinq's anonymisation service answers to on a renamed instance.
	 *
	 * @var string
	 */
	private const FILINQ_ANONYMIZATION = 'OCA\Filinq\Service\AnonymizationService';

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var ZgwDocumentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ZgwDocumentService $documents;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Set up the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the client under test.
	 *
	 * @return FilinqRedactionClient The client.
	 */
	private function client(): FilinqRedactionClient {
		return new FilinqRedactionClient(
			$this->container,
			$this->documents,
			$this->userSession,
			$this->createMock(LoggerInterface::class)
		);
	}//end client()

	/**
	 * Point the container at a recording filinq anonymisation service.
	 *
	 * @return object The double; its `$calls` holds every invocation, in order.
	 */
	private function givenFilinq(): object {
		$service = new class {

			/**
			 * Every invocation, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Filinq's entity extraction.
			 *
			 * @param int $fileId The Nextcloud file id.
			 *
			 * @return array<string, mixed> The extraction result.
			 */
			public function extractAndDetectEntities(int $fileId): array {
				$this->calls[] = ['method' => 'extractAndDetectEntities', 'fileId' => $fileId];
				return ['entities' => [['type' => 'PERSON'], ['type' => 'BSN']]];
			}

			/**
			 * Filinq's anonymisation.
			 *
			 * @param int $fileId The Nextcloud file id.
			 * @param array<int, mixed> $entities The entities to redact.
			 * @param string $outputFormat The output format.
			 * @param array<int, mixed> $unredacted Entities published unredacted.
			 * @param array<int, mixed> $overrides Acknowledged overrides.
			 * @param string $userId The acting user.
			 *
			 * @return array<string, mixed> The anonymisation result.
			 */
			public function anonymizeDocument(
				int $fileId,
				array $entities,
				string $outputFormat = 'pdf-only',
				array $unredacted = [],
				array $overrides = [],
				string $userId = '',
			): array {
				$this->calls[] = [
					'method' => 'anonymizeDocument',
					'fileId' => $fileId,
					'entityCount' => count($entities),
					'userId' => $userId,
				];
				return ['anonymizedFileId' => 991];
			}
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === self::FILINQ_ANONYMIZATION) {
					return $service;
				}

				throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}
		);

		return $service;
	}//end givenFilinq()

	/**
	 * Filinq is asked to extract, then to anonymise, the resolved file.
	 *
	 * @return void
	 */
	public function testRedactExtractsThenAnonymisesTheFile(): void {
		$filinq = $this->givenFilinq();

		$outcome = $this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);

		$this->assertSame(
			[
				['method' => 'extractAndDetectEntities', 'fileId' => 55],
				['method' => 'anonymizeDocument', 'fileId' => 55, 'entityCount' => 2, 'userId' => 'alice'],
			],
			$filinq->calls,
			'both filinq steps must run, on the file this app resolved, as the acting user'
		);
		$this->assertSame('redacted', $outcome['status']);
		$this->assertSame(2, $outcome['entityCount']);
		$this->assertSame(991, $outcome['anonymizedFileId']);
	}//end testRedactExtractsThenAnonymisesTheFile()

	/**
	 * A document without a file id is looked up by its uuid and file name.
	 *
	 * @return void
	 */
	public function testRedactResolvesTheFileIdFromTheDocumentRecord(): void {
		$filinq = $this->givenFilinq();
		$this->documents->expects($this->once())
			->method('getFileId')
			->with('doc-9', 'besluit.pdf')
			->willReturn(123);

		$this->client()->redact('case-1', ['id' => 'doc-9', 'fileName' => 'besluit.pdf']);

		$this->assertSame(123, $filinq->calls[0]['fileId']);
	}//end testRedactResolvesTheFileIdFromTheDocumentRecord()

	/**
	 * A document whose bytes cannot be found is refused, not reported redacted.
	 *
	 * @return void
	 */
	public function testRedactRefusesWhenNoFileCanBeResolved(): void {
		$this->givenFilinq();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/file_unresolved/');

		$this->client()->redact('case-1', ['id' => 'doc-1']);
	}//end testRedactRefusesWhenNoFileCanBeResolved()

	/**
	 * A run that detected nothing is not called redacted.
	 *
	 * Filinq happily writes an output file when its detector returns no
	 * entities, and that file's content is the input's. Reporting `redacted`
	 * there would close a Woo task over a document that still names everybody
	 * in it, which is the `queued` lie one step further down the pipeline.
	 *
	 * @return void
	 */
	public function testARunThatDetectedNothingIsNotCalledRedacted(): void {
		$service = new class {

			/**
			 * A detector that finds nothing, as an unconfigured backend does.
			 *
			 * @param int $fileId The Nextcloud file id.
			 *
			 * @return array<string, mixed> An empty extraction.
			 */
			public function extractAndDetectEntities(int $fileId): array {
				return ['entities' => []];
			}

			/**
			 * Filinq still writes a file.
			 *
			 * @param int $fileId The Nextcloud file id.
			 * @param array<int, mixed> $entities The entities to redact.
			 * @param string $outputFormat The output format.
			 * @param array<int, mixed> $unredacted Entities published unredacted.
			 * @param array<int, mixed> $overrides Acknowledged overrides.
			 * @param string $userId The acting user.
			 *
			 * @return array<string, mixed> The anonymisation result.
			 */
			public function anonymizeDocument(
				int $fileId,
				array $entities,
				string $outputFormat = 'pdf-only',
				array $unredacted = [],
				array $overrides = [],
				string $userId = '',
			): array {
				return ['anonymizedFileId' => 4321];
			}
		};

		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === self::FILINQ_ANONYMIZATION) {
					return $service;
				}

				throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}
		);

		$outcome = $this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);

		$this->assertSame('no_entities_detected', $outcome['status']);
		$this->assertSame(0, $outcome['entityCount']);
	}//end testARunThatDetectedNothingIsNotCalledRedacted()

	/**
	 * An absent filinq is refused rather than answered.
	 *
	 * @return void
	 */
	public function testRedactRefusesWhenFilinqIsAbsent(): void {
		$this->container->method('get')->willThrowException(
			new class('absent') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
			}
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_unavailable/');

		$this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);
	}//end testRedactRefusesWhenFilinqIsAbsent()
}//end class
