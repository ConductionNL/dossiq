<?php

/**
 * The DSO case mutations, recorded call by call.
 *
 * Every action that changes a DSO case answers the same way: 401 without a
 * session, 400 for a missing or invalid field, 404 for an unknown case, 403
 * when the per-object guard refuses, the service's answer otherwise, and a
 * logged 500 for anything unexpected. Each world records the reads, the guard,
 * the write, the log lines and the response, and is compared with a golden.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DsoController;
use OCA\Dossiq\Service\BeschikkingGenerationService;
use OCA\Dossiq\Service\Dso\DsoDoorsturenNotifier;
use OCA\Dossiq\Service\Dso\DsoObjectRepository;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\SamenwerkverzoekService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A request whose JSON body the controller reads through getContent().
 */
interface DsoGoldenJsonRequest extends IRequest {
	/**
	 * The raw body.
	 *
	 * @return string
	 */
	public function getContent(): string;
}//end interface

/**
 * Every DSO case mutation matches its recording.
 *
 * @covers \OCA\Dossiq\Controller\DsoController
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */
class DsoCaseMutationsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Each action and the body it acts on.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: mixed}>
	 */
	public static function actions(): array {
		return [
			'transition status' => ['transitionStatus', ['newStatus' => 'besluit', 'besluitdatum' => '2026-11-01', 'notes' => ' '], 'newStatus', 'onbekend'],
			'generate beschikking' => ['generateBeschikking', ['outcome' => 'granted', 'motivation' => 'Past in het plan'], 'outcome', 'maybe'],
			'initiate samenwerking' => ['initiateSamenwerking', ['requestedCompetentAuthority' => 'gm0363', 'rationale' => 'Ligt op de grens'], 'requestedCompetentAuthority', null],
			'doorsturen' => ['doorsturen', ['targetBevoegdGezag' => 'pv27', 'reason' => 'Provinciaal belang'], 'targetBevoegdGezag', null],
		];
	}//end actions()

	/**
	 * The action matches the recording in every world.
	 *
	 * @param string               $action  The controller action.
	 * @param array<string, mixed> $body    A body it acts on.
	 * @param string               $field   Its required field.
	 * @param mixed                $invalid A value of that field it refuses, or null when any value goes.
	 *
	 * @dataProvider actions
	 *
	 * @return void
	 */
	public function testTheActionMatchesTheRecording(string $action, array $body, string $field, mixed $invalid): void {
		$worlds = [
			'no session' => [false, $body, 'found'],
			'missing field' => [true, array_diff_key($body, [$field => 1]), 'found'],
			'unknown case' => [true, $body, 'unknown'],
			'guard refuses' => [true, $body, 'refused'],
			'done' => [true, $body, 'found'],
			'store fails' => [true, $body, 'failing'],
		];
		if ($invalid !== null) {
			$worlds['invalid field'] = [true, array_replace($body, [$field => $invalid]), 'found'];
		}

		$recorded = [];
		foreach ($worlds as $world => [$signedIn, $sent, $case]) {
			$this->log = [];
			$response = $this->controller(signedIn: $signedIn, body: $sent, case: $case)->$action('case-1');
			$this->log[] = ['status' => $response->getStatus(), 'data' => $response->getData()];
			$recorded[$world] = $this->log;
		}

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'dso-case-mutations/' . $name, value: $recorded);
	}//end testTheActionMatchesTheRecording()

	/**
	 * The controller over recording collaborators.
	 *
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $body     The JSON body.
	 * @param string               $case     `found`, `unknown`, `refused` or `failing`.
	 *
	 * @return DsoController
	 */
	private function controller(bool $signedIn, array $body, string $case): DsoController {
		$request = $this->createMock(DsoGoldenJsonRequest::class);
		$request->method('getContent')->willReturn((string)json_encode($body));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $user : null);

		$repository = $this->createMock(DsoObjectRepository::class);
		$repository->method('findZaak')->willReturnCallback(
			function (string $caseId) use ($case): ?array {
				$this->log[] = ['findZaak' => $caseId];
				if ($case === 'failing') {
					throw new RuntimeException('store down');
				}

				return ($case === 'unknown') ? null : ['id' => $caseId, 'title' => 'Dakkapel'];
			}
		);

		$cases = $this->createMock(DsoCaseService::class);
		$cases->method('authorizeZaakMutation')->willReturnCallback(
			function (array $zaak, IUser $by) use ($case): void {
				$this->log[] = ['authorizeZaakMutation' => [$zaak['id'], $by->getUID()]];
				if ($case === 'refused') {
					throw new RuntimeException('Not authorized');
				}
			}
		);
		$cases->method('transitionStatus')->willReturnCallback(
			function (string $caseId, string $newStatus, ?string $besluitdatum, ?string $notes, string $userId): array {
				$this->log[] = ['transitionStatus' => [$caseId, $newStatus, $besluitdatum, $notes, $userId]];
				return ['id' => $caseId, 'dsoStatus' => $newStatus];
			}
		);

		$decisions = $this->createMock(BeschikkingGenerationService::class);
		$decisions->method('generateBeschikking')->willReturnCallback(
			function (string $caseId, string $outcome, string $motivation): array {
				$this->log[] = ['generateBeschikking' => [$caseId, $outcome, $motivation]];
				return ['id' => 'b-1'];
			}
		);

		$samenwerk = $this->createMock(SamenwerkverzoekService::class);
		$samenwerk->method('initiateSamenwerking')->willReturnCallback(
			function (string $caseId, string $aangezochtGezag, string $rationale): array {
				$this->log[] = ['initiateSamenwerking' => [$caseId, $aangezochtGezag, $rationale]];
				return ['id' => 's-1'];
			}
		);

		$notifier = $this->createMock(DsoDoorsturenNotifier::class);
		$notifier->method('dispatchDoorgestuurd')->willReturnCallback(
			function (array $zaak, string $caseId, string $targetBevoegdGezag, string $reason, string $userId): void {
				$this->log[] = ['dispatchDoorgestuurd' => [$zaak['id'], $caseId, $targetBevoegdGezag, $reason, $userId]];
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

		return new DsoController(
			'dossiq',
			$request,
			$cases,
			$decisions,
			$samenwerk,
			$repository,
			$notifier,
			$session,
			$logger
		);
	}//end controller()
}//end class
