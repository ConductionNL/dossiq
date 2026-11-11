<?php

/**
 * Golden test for DocumentApprovalClearance::forDocument: every answer, what is
 * asked of decidiq, and the log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

class DocumentApprovalClearanceGoldenTest extends TestCase {

	private array $calls = [];

	private array $logged = [];

	private function clearance(mixed $routes, mixed $answer, bool $withClearance = true, string $failAt = ''): DocumentApprovalClearance {
		$calls = &$this->calls;
		$route = new class ($calls, $routes, $failAt) {
			public function __construct(private array &$calls, private mixed $routes, private string $failAt) {
			}

			public function routesWithStagesFor(string $subject): mixed {
				$this->calls[] = ['routes', $subject];
				if ($this->failAt === 'routes') {
					throw new RuntimeException('routes down');
				}

				return $this->routes;
			}
		};
		$clear = new class ($calls, $answer, $failAt) {
			public function __construct(private array &$calls, private mixed $answer, private string $failAt) {
			}

			public function clearanceFor(array $routes): mixed {
				$this->calls[] = ['clearance', $routes];
				if ($this->failAt === 'clearance') {
					throw new RuntimeException('clearance down');
				}

				return $this->answer;
			}
		};
		$container = new class ($route, $withClearance ? $clear : null) implements ContainerInterface {
			public function __construct(private object $route, private ?object $clear) {
			}

			public function get(string $id): mixed {
				if (str_ends_with($id, 'Service\\ApprovalRouteService') === true) {
					return $this->route;
				}

				if ($this->clear !== null && str_ends_with($id, 'Service\\SubjectClearanceService') === true) {
					return $this->clear;
				}

				throw new class ('absent') extends RuntimeException implements NotFoundExceptionInterface {
				};
			}

			public function has(string $id): bool {
				return true;
			}
		};
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [(string) $level, (string) $message, $context];
			}
		};

		return new DocumentApprovalClearance(container: $container, logger: $logger);
	}//end clearance()

	public function testEveryAnswer(): void {
		$routes = [['id' => 'r1']];
		$this->assertSame(
			['routed' => true, 'cleared' => false, 'waitingOn' => [['stage' => 's']]],
			$this->clearance($routes, ['cleared' => 'yes', 'waitingOn' => [['stage' => 's']]])->forDocument(' doc-1 ')
		);
		$this->assertSame([['routes', 'doc-1'], ['clearance', $routes]], $this->calls);
		$this->assertSame(['routed' => true, 'cleared' => true, 'waitingOn' => ['x']], $this->clearance($routes, ['cleared' => true, 'waitingOn' => 'x'])->forDocument('doc-1'));
		$this->assertSame(['routed' => true, 'cleared' => false, 'waitingOn' => []], $this->clearance($routes, [])->forDocument('doc-1'));

		$this->calls = [];
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance($routes, [])->forDocument('  '));
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance($routes, [], withClearance: false)->forDocument('doc-1'));
		$this->assertSame([], $this->calls);
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance([], [])->forDocument('doc-1'));
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance('none', [])->forDocument('doc-1'));
		$this->assertSame([['routes', 'doc-1'], ['routes', 'doc-1']], $this->calls);
		$this->assertSame([], $this->logged);
	}//end testEveryAnswer()

	public function testAFailingDecidiqIsLoggedAndAnswersUnrouted(): void {
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance([['id' => 'r']], [], failAt: 'routes')->forDocument('doc-2', 'zaak'));
		$this->assertSame(DocumentApprovalClearance::NOT_ROUTED, $this->clearance([['id' => 'r']], [], failAt: 'clearance')->forDocument('doc-3'));
		$this->assertSame(
			[
				['warning', 'Dossiq could not read the approval clearance of document doc-2: routes down', ['app' => 'dossiq', 'document' => 'doc-2', 'schema' => 'zaak']],
				['warning', 'Dossiq could not read the approval clearance of document doc-3: clearance down', ['app' => 'dossiq', 'document' => 'doc-3', 'schema' => 'informatieobject']],
			],
			$this->logged
		);
	}//end testAFailingDecidiqIsLoggedAndAnswersUnrouted()
}//end class
