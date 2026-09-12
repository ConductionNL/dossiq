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
	 * The FQCN OpenRegister's backend-state service actually answers to.
	 *
	 * The `Anonymisation` segment is the point. Filinq's own client asks for
	 * `OCA\OpenRegister\Service\AnonymisationBackendService`, which is not a
	 * class, catches the miss and returns a hardcoded `regex`.
	 *
	 * @var string
	 */
	private const OR_BACKEND_STATE = 'OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService';

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
	 * @param object|null $backendState OpenRegister's backend-state double, or
	 *                                  null when OpenRegister cannot be asked.
	 *
	 * @return object The double; its `$calls` holds every invocation, in order.
	 */
	private function givenFilinq(?object $backendState = null): object {
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
			static function (string $id) use ($service, $backendState): object {
				if ($id === self::FILINQ_ANONYMIZATION) {
					return $service;
				}

				if ($id === self::OR_BACKEND_STATE && $backendState !== null) {
					return $backendState;
				}

				throw new class('not registered') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}
		);

		return $service;
	}//end givenFilinq()

	/**
	 * An OpenRegister backend-state service reporting one effective method.
	 *
	 * Shaped like the real one: `getState()` returns a `BackendState` value
	 * object with a public readonly `effectiveMethod`, not an array.
	 *
	 * @param string $effectiveMethod The method OpenRegister reports.
	 *
	 * @return object The double.
	 */
	private function backendStateDouble(string $effectiveMethod): object {
		return new class($effectiveMethod) {

			/**
			 * Constructor.
			 *
			 * @param string $effectiveMethod The method to report.
			 */
			public function __construct(private string $effectiveMethod) {
			}

			/**
			 * OpenRegister's backend state.
			 *
			 * @return object The state value object.
			 */
			public function getState(): object {
				return new class($this->effectiveMethod) {

					/**
					 * Constructor.
					 *
					 * @param string $effectiveMethod The method in force.
					 */
					public function __construct(public readonly string $effectiveMethod) {
					}
				};
			}
		};
	}//end backendStateDouble()

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
	 * Entities found and no file produced is not called redacted either.
	 *
	 * 🔴 THE TRAP THIS PINS. The status was computed from the entity count
	 * alone, so filinq detecting a BSN and then naming no output file reported
	 * `redacted` on a document whose bytes are untouched. A status that says
	 * redacted looks identical whether or not anything was redacted, so the
	 * assertion here is on the effect: an anonymised file id came back, or the
	 * document is not redacted.
	 *
	 * @return void
	 */
	public function testEntitiesWithoutAnOutputFileAreNotCalledRedacted(): void {
		$service = new class {

			/**
			 * A detector that finds something.
			 *
			 * @param int $fileId The Nextcloud file id.
			 *
			 * @return array<string, mixed> Two entities.
			 */
			public function extractAndDetectEntities(int $fileId): array {
				return ['entities' => [['type' => 'BSN'], ['type' => 'PERSON']]];
			}

			/**
			 * An anonymisation that names no output.
			 *
			 * @param int $fileId The Nextcloud file id.
			 * @param array<int, mixed> $entities The entities to redact.
			 * @param string $outputFormat The output format.
			 * @param array<int, mixed> $unredacted Entities published unredacted.
			 * @param array<int, mixed> $overrides Acknowledged overrides.
			 * @param string $userId The acting user.
			 *
			 * @return array<string, mixed> A result with no file in it.
			 */
			public function anonymizeDocument(
				int $fileId,
				array $entities,
				string $outputFormat = 'pdf-only',
				array $unredacted = [],
				array $overrides = [],
				string $userId = '',
			): array {
				return ['warning' => 'the redaction pass produced no document'];
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

		$this->assertSame('no_output_produced', $outcome['status']);
		$this->assertNull($outcome['anonymizedFileId'], 'nothing came back to point a Woo officer at');
		$this->assertSame(2, $outcome['entityCount'], 'the detection itself did happen');
	}//end testEntitiesWithoutAnOutputFileAreNotCalledRedacted()

	/**
	 * The outcome names the detection backend OpenRegister actually reports.
	 *
	 * Not filinq's status. Filinq's `AnonymiserBackendStateClient` resolves
	 * `OCA\OpenRegister\Service\AnonymisationBackendService`, one namespace
	 * segment short of the class that exists, so it catches and answers
	 * `regex` on every instance whatever is configured. Reading OpenRegister
	 * directly is what makes a regex-only run visible on the case instead of
	 * being buried under an admin banner that is on everywhere.
	 *
	 * @return void
	 */
	public function testTheOutcomeNamesTheBackendOpenRegisterReports(): void {
		$this->givenFilinq($this->backendStateDouble('presidio'));

		$outcome = $this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);

		$this->assertSame('presidio', $outcome['detectionBackend']);
	}//end testTheOutcomeNamesTheBackendOpenRegisterReports()

	/**
	 * A backend that cannot be read is unknown, never assumed.
	 *
	 * The upstream defect is precisely an unreadable state answered with a
	 * confident constant, so this one reports null and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableBackendIsReportedAsUnknown(): void {
		$this->givenFilinq();

		$outcome = $this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);

		$this->assertNull($outcome['detectionBackend'], 'an unread backend must not be guessed');
	}//end testAnUnreadableBackendIsReportedAsUnknown()

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
	/**
	 * A file id arriving as a numeric string is accepted as a file id.
	 *
	 * Document records come out of OpenRegister, where a number can arrive as
	 * a string. Rejecting it would send a perfectly resolvable document to
	 * manual redaction.
	 *
	 * @return void
	 */
	public function testRedactAcceptsANumericStringFileId(): void {
		$filinq = $this->givenFilinq();

		$this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => '77']);

		$this->assertSame(77, $filinq->calls[0]['fileId']);
	}//end testRedactAcceptsANumericStringFileId()

	/**
	 * A document whose file lookup throws is refused, carrying the reason.
	 *
	 * @return void
	 */
	public function testRedactRefusesWhenTheFileLookupThrows(): void {
		$this->givenFilinq();
		$this->documents->method('getFileId')->willThrowException(new RuntimeException('gone from storage'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/file_unresolved: gone from storage/');

		$this->client()->redact('case-1', ['id' => 'doc-9', 'fileName' => 'besluit.pdf']);
	}//end testRedactRefusesWhenTheFileLookupThrows()

	/**
	 * With no session, filinq is still called, with an empty acting user.
	 *
	 * A background job has no session. Filinq's override audit takes an empty
	 * uid, so the redaction still runs rather than being refused for the want
	 * of a name to record.
	 *
	 * @return void
	 */
	public function testRedactRunsWithoutASessionAndRecordsNoActingUser(): void {
		$filinq = $this->givenFilinq();
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$client = new FilinqRedactionClient(
			$this->container,
			$this->documents,
			$session,
			$this->createMock(LoggerInterface::class)
		);

		$client->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);

		$this->assertSame('', $filinq->calls[1]['userId']);
	}//end testRedactRunsWithoutASessionAndRecordsNoActingUser()

	/**
	 * A filinq that throws mid-run is reported as a refusal, not a redaction.
	 *
	 * @return void
	 */
	public function testRedactRefusesWhenFilinqThrows(): void {
		$service = new class {

			/**
			 * Filinq's extraction, which fails here.
			 *
			 * @param int $fileId The Nextcloud file id.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function extractAndDetectEntities(int $fileId): array {
				throw new \RuntimeException('detector unavailable');
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

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/filinq_redaction_failed: detector unavailable/');

		$this->client()->redact('case-1', ['id' => 'doc-1', 'fileId' => 55]);
	}//end testRedactRefusesWhenFilinqThrows()

}//end class
