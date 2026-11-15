<?php

/**
 * Golden test for every routed term-instance address, canonical and deprecated.
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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeInterface;
use OCA\Dossiq\Controller\TermijnController;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Dossiq\Tests\Support\PhpInputStream;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the answer and the service calls of each term-instance route, reached through routes.php.
 *
 * @covers \OCA\Dossiq\Controller\TermijnController
 * @covers \OCA\Dossiq\Controller\DeadlineInstanceLegacyPathController
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Tests\Support\PhpInputStream
 */
class TermijnRoutesGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;
	use MatchesGoldenFiles;

	/**
	 * Every service call the route made, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The routes of the term-instance addresses, keyed by "VERB url".
	 *
	 * @return array<string, string> Route name per address.
	 */
	private static function termRoutes(): array {
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		preg_match_all(
			"#\['name' => '(\w+\#\w+)',\s*'url' => '(/api/(?:deadlines|termijn)/instances[^']*)',\s*'verb' => '(\w+)'\]#",
			$routes,
			$matches,
			PREG_SET_ORDER
		);

		$byAddress = [];
		foreach ($matches as $match) {
			$byAddress[$match[3] . ' ' . $match[2]] = $match[1];
		}

		return $byAddress;
	}//end termRoutes()

	/**
	 * Each address with a scenario: who calls, what body, how the services answer.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function cases(): array {
		$scenarios = [
			'anonymous' => '',
			'empty-body' => '{}',
			'full-body' => '{"caseId":"case-1","caseType":"bezwaar","duurDagen":14,"rationale":"wait","documentLink":"doc-1","pauseReason":"info","aanvullingDatum":"2026-03-02","newEinddatum":"2026-04-01","supervisorOverride":true,"voltooiDatum":"2026-05-03"}',
			'standard-extension' => '{"rationale":"more time","newEinddatum":"2026-04-01"}',
			'bad-date' => '{"aanvullingDatum":"not a date","newEinddatum":"not a date","voltooiDatum":"not a date"}',
			'service-throws' => '{"caseId":"case-1","caseType":"bezwaar"}',
			'not-found' => '{}',
		];

		$cases = [];
		foreach (array_keys(self::termRoutes()) as $address) {
			foreach ($scenarios as $scenario => $body) {
				$cases[$address . ' ' . $scenario] = [$address, $scenario, $body];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The route answers and calls the services as recorded.
	 *
	 * @param string $address  "VERB url" of the route.
	 * @param string $scenario The scenario name.
	 * @param string $body     The request body.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testTheRouteAnswersAsRecorded(string $address, string $scenario, string $body): void {
		$routeName = self::termRoutes()[$address];
		[$controllerName, $method] = explode('#', $routeName);
		$controller = $this->controller(name: $controllerName, scenario: $scenario);

		$arguments = [];
		if (str_contains($address, '{id}') === true) {
			$arguments = ['id' => 'inst-1'];
		}

		try {
			$response = PhpInputStream::with(
				$body,
				fn (): JSONResponse => $controller->$method(...$arguments)
			);
			$answer = ['status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (RuntimeException $e) {
			$answer = ['throws' => $e->getMessage()];
		}

		$canonicalAddress = str_replace(
			['/api/termijn/', '/pauze', '/hervat', '/verleng', '/voltooi'],
			['/api/deadlines/', '/pause', '/resume', '/extend', '/complete'],
			$address
		);
		$this->assertMatchesGolden(
			'termijn-routes/' . preg_replace('/[^a-z]+/', '-', strtolower($canonicalAddress . ' ' . $scenario)),
			($answer + ['calls' => $this->calls])
		);
	}//end testTheRouteAnswersAsRecorded()

	/**
	 * The controller a route name addresses, with services that record their calls.
	 *
	 * @param string $name     The controller part of the route name.
	 * @param string $scenario The scenario name.
	 *
	 * @return object The controller.
	 */
	private function controller(string $name, string $scenario): object {
		$termijn = $this->termijnController(scenario: $scenario);
		if ($name === 'termijn') {
			return $termijn;
		}

		$class = 'OCA\\Dossiq\\Controller\\' . ucfirst($name) . 'Controller';
		return new $class(appName: 'dossiq', request: $this->createMock(IRequest::class), termijn: $termijn);
	}//end controller()

	/**
	 * A TermijnController whose services record every call and answer per scenario.
	 *
	 * @param string $scenario The scenario name.
	 *
	 * @return TermijnController The controller.
	 */
	private function termijnController(string $scenario): TermijnController {
		$answer = function (string $service, string $method, array $args) use ($scenario): ?array {
			$this->calls[] = ['call' => $service . '::' . $method, 'args' => array_map(self::plain(...), $args)];
			if ($scenario === 'service-throws') {
				throw new RuntimeException('Refused by ' . $method);
			}

			if ($scenario === 'not-found') {
				return null;
			}

			return ['id' => 'inst-1', 'answeredBy' => $method];
		};

		$term = $this->createMock(TermijnService::class);
		foreach (['createTermijnInstance', 'getTermijnInstance', 'markTermijnCompleted'] as $method) {
			$term->method($method)->willReturnCallback(fn (...$args) => $answer('term', $method, $args));
		}

		$pause = $this->createMock(DeadlinePauseService::class);
		foreach (['registerPauze', 'resumeAfterPauze'] as $method) {
			$pause->method($method)->willReturnCallback(fn (...$args) => ($answer('pause', $method, $args) ?? []));
		}

		$extension = $this->createMock(DeadlineExtensionService::class);
		foreach (['requestExtension', 'requestSupervisorExtension'] as $method) {
			$extension->method($method)->willReturnCallback(fn (...$args) => ($answer('extension', $method, $args) ?? []));
		}

		$slugs = $this->createMock(CaseTypeSlugResolver::class);
		$slugs->method('toSlug')->willReturnCallback(fn (string $reference): string => 'slug-' . $reference);

		$session = $this->createMock(IUserSession::class);
		if ($scenario !== 'anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('behandelaar');
			$session->method('getUser')->willReturn($user);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['call' => 'logger::info', 'args' => [$message]];
		});

		return new TermijnController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			term: $term,
			pause: $pause,
			extension: $extension,
			dates: $this->caseDates(),
			caseTypeSlugs: $slugs,
			userSession: $session,
			logger: $logger,
		);
	}//end termijnController()

	/**
	 * A service argument as plain JSON data.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return mixed The value, a date as its UTC instant.
	 */
	private static function plain(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return ['date' => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
		}

		return $value;
	}//end plain()
}//end class
