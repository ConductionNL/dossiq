<?php

/**
 * Golden record of the document access exception endpoints.
 *
 * Records, per world, the status and body `update` and `destroy` answer with
 * and every call they make, so a refactor cannot change who may set or remove
 * an access exception, or in which order the checks run.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-only-a-caller-who-may-change-every-case-of-the-document-sets-the-exception
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\DocumentAccessExceptionController;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseWriteGuard;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for DocumentAccessExceptionController update and destroy.
 *
 * @covers \OCA\Dossiq\Controller\DocumentAccessExceptionController
 */
class DocumentAccessExceptionGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the two endpoints distinguish, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testUpdateAndDestroyAcrossWorlds(): void {
		$allowed = ['user' => true, 'refusedCase' => null, 'readable' => true, 'valid' => true, 'store' => 'ok'];
		$worlds = [
			'not signed in' => ['user' => false] + $allowed,
			'may not change a case of the document' => ['refusedCase' => 'case-9'] + $allowed,
			'document not readable' => ['readable' => false] + $allowed,
			'invalid exception' => ['valid' => false] + $allowed,
			'store fails' => ['store' => 'fail'] + $allowed,
			'allowed' => $allowed,
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			foreach (['update', 'destroy'] as $endpoint) {
				$this->calls = [];
				$response = $this->controllerFor(world: $world)->$endpoint('doc-1');
				$record[$name][$endpoint] = [
					'status' => $response->getStatus(),
					'body' => $response->getData(),
					'calls' => $this->calls,
				];
			}
		}

		$this->assertMatchesGolden(name: 'document-access-exception', value: $record);
	}//end testUpdateAndDestroyAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DocumentAccessExceptionController
	 */
	private function controllerFor(array $world): DocumentAccessExceptionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (['users' => ['u-1'], 'groups' => ['g-1'], 'reason' => 'privacy'][$key] ?? $default)
		);

		$caseWrites = $this->createMock(DocumentCaseWriteGuard::class);
		$caseWrites->method('refusedCase')->willReturnCallback(
			function (IUser $user, string $documentId) use ($world): ?string {
				$this->calls[] = 'refusedCase ' . $documentId;
				return $world['refusedCase'];
			}
		);

		$reader = $this->createMock(InformatieobjectReader::class);
		$reader->method('loadReadable')->willReturnCallback(
			function (IUser $user, string $infoObjectId) use ($world): array|JSONResponse {
				$this->calls[] = 'loadReadable ' . $infoObjectId;
				if ($world['readable'] === false) {
					return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
				}

				return ['id' => $infoObjectId];
			}
		);

		return new DocumentAccessExceptionController('dossiq', $request, $session, $caseWrites, $reader, $this->writerFor(world: $world));
	}//end controllerFor()

	/**
	 * A writer that records each call and answers per the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return DocumentAccessExceptionWriter
	 */
	private function writerFor(array $world): DocumentAccessExceptionWriter {
		$writer = $this->createMock(DocumentAccessExceptionWriter::class);
		$writer->method('validated')->willReturnCallback(
			function (array $input) use ($world): array {
				$this->calls[] = 'validated ' . json_encode($input);
				if ($world['valid'] === false) {
					throw new InvalidArgumentException('Give at least one user or group');
				}

				return $input;
			}
		);
		$writer->method('set')->willReturnCallback(
			function (IUser $user, array $record, array $exception) use ($world): array {
				$this->calls[] = 'set ' . $record['id'] . ' by ' . $user->getUID();
				if ($world['store'] === 'fail') {
					throw new RuntimeException('register offline');
				}

				return $exception + ['setBy' => $user->getUID()];
			}
		);
		$writer->method('remove')->willReturnCallback(
			function (IUser $user, array $record, string $reason = '') use ($world): bool {
				$this->calls[] = 'remove ' . $record['id'] . ' by ' . $user->getUID() . ' because ' . $reason;
				if ($world['store'] === 'fail') {
					throw new RuntimeException('register offline');
				}

				return true;
			}
		);

		return $writer;
	}//end writerFor()
}//end class
