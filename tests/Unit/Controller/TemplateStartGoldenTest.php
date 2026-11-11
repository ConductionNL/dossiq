<?php

/**
 * Golden record of starting a case from a template.
 *
 * Records, per world, the status and body the endpoint answers with and every
 * call it makes, so a refactor cannot change who may start from a template or
 * how each outcome is answered.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TemplateStartController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Starter\CaseTemplateService;
use OCA\Dossiq\Service\Starter\ContentTemplateService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for TemplateStartController::startFromTemplate.
 *
 * @covers \OCA\Dossiq\Controller\TemplateStartController
 */
class TemplateStartGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testStartAcrossWorlds(): void {
		$overrides = ['overrides' => ['title' => 'Mijn bezwaar']];
		$allowed = ['user' => true, 'read' => true, 'start' => ['ok' => true, 'caseId' => 'case-9']];
		$worlds = [
			'not signed in' => [$overrides, ['user' => false] + $allowed],
			'may not read the template' => [$overrides, ['read' => false] + $allowed],
			'started with overrides' => [$overrides, $allowed],
			'started with overrides that are not a list' => [['overrides' => 'x'], $allowed],
			'not a template' => [[], ['start' => ['ok' => false, 'reason' => 'not_found']] + $allowed],
			'refused otherwise' => [[], ['start' => ['ok' => false, 'reason' => 'not_a_template']] + $allowed],
			'starting fails' => [[], ['start' => 'throw'] + $allowed],
		];

		$record = [];
		foreach ($worlds as $name => [$params, $world]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $params, world: $world)->startFromTemplate('tpl-1');
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'template-start', value: $record);
	}//end testStartAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return TemplateStartController
	 */
	private function controllerFor(array $params, array $world): TemplateStartController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $this->createMock(IUser::class) : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseReadAccess ' . $caseId;
				return $world['read'];
			}
		);

		$cases = $this->createMock(CaseTemplateService::class);
		$cases->method('startFrom')->willReturnCallback(
			function (string $templateId, array $overrides = []) use ($world): array {
				$this->calls[] = 'startFrom ' . json_encode([$templateId, $overrides]);
				if ($world['start'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return $world['start'];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = 'error: ' . $message;
			}
		);

		return new TemplateStartController(
			request: $request,
			cases: $cases,
			content: $this->createMock(ContentTemplateService::class),
			guard: $guard,
			session: $session,
			logger: $logger,
		);
	}//end controllerFor()
}//end class
