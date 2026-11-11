<?php

/**
 * The bulk act hand-over, recorded answer by answer.
 *
 * Every answer of `POST` bulk job (401, the created job, each refusal the
 * hand-over can raise, and the logged 500) is driven here and compared with a
 * golden file together with what was handed over and every log line.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use Error;
use InvalidArgumentException;
use OCA\Dossiq\Controller\BulkJobHandoffController;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every hand-over answer matches the recording.
 *
 * @covers \OCA\Dossiq\Controller\BulkJobHandoffController
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */
class BulkJobHandoffControllerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Every world matches the recording.
	 *
	 * @return void
	 */
	public function testEveryAnswerMatchesTheRecording(): void {
		$params = ['action' => 'close', 'parameters' => ['result' => 'r-1'], 'selection' => ['ids' => ['c-1', 'c-2']], 'justification' => '  End of year  '];
		$reasoned = new class ('The engine is busy') extends RuntimeException {
			/**
			 * The reason.
			 *
			 * @return string
			 */
			public function getReason(): string {
				return 'engine-busy';
			}

			/**
			 * The details.
			 *
			 * @return array<string, int>
			 */
			public function getDetails(): array {
				return ['queued' => 4];
			}
		};
		$worlds = [
			'signed out' => [false, $params, null],
			'created' => [true, $params, null],
			'no justification' => [true, array_replace($params, ['justification' => '   ']), null],
			'parameters that are not a list' => [true, array_replace($params, ['parameters' => 'x', 'selection' => 7]), null],
			'mixed versions' => [true, $params, new MixedCaseTypeVersionsException(caseTypeTitle: 'Omgevingsvergunning', versions: ['1', '2.0'], counts: [1 => 3, '2.0' => 1])],
			'an invalid act' => [true, $params, new InvalidArgumentException('Unknown action: close')],
			'an engine refusal without a reason' => [true, $params, new RuntimeException('No job engine')],
			'an engine refusal with a reason' => [true, $params, $reasoned],
			'anything else' => [true, $params, new Error('typo')],
		];

		$recorded = [];
		foreach ($worlds as $name => [$signedIn, $sent, $failure]) {
			$this->log = [];
			$response = $this->controller(signedIn: $signedIn, params: $sent, failure: $failure)->create();
			$this->log[] = ['status' => $response->getStatus(), 'data' => $response->getData()];
			$recorded[$name] = $this->log;
		}

		$this->assertMatchesGolden(name: 'bulk-job-handoff/answers', value: $recorded);
	}//end testEveryAnswerMatchesTheRecording()

	/**
	 * The controller over recording collaborators.
	 *
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $params   The request parameters.
	 * @param Throwable|null       $failure  What the hand-over throws, or null.
	 *
	 * @return BulkJobHandoffController
	 */
	private function controller(bool $signedIn, array $params, ?Throwable $failure): BulkJobHandoffController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $user : null);

		$handoff = $this->createMock(BulkJobHandoff::class);
		$handoff->method('create')->willReturnCallback(
			function (string $actionId, array $parameters, array $selection, ?string $justification, string $actorUid) use ($failure): array {
				$this->log[] = ['create' => [$actionId, $parameters, $selection, $justification, $actorUid]];
				if ($failure !== null) {
					throw $failure;
				}

				return ['id' => 'job-1', 'status' => 'queued'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new BulkJobHandoffController('dossiq', $request, $handoff, $session, $logger);
	}//end controller()
}//end class
