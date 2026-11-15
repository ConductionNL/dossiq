<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The one seam that names pipelinq, and what it does when pipelinq is absent.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
class PipelinqGatewayTest extends TestCase {

	/**
	 * A gateway over a container that holds the given services.
	 *
	 * @param array<string, object> $services Keyed by class name.
	 *
	 * @return PipelinqGateway The gateway under test.
	 */
	private function gateway(array $services = []): PipelinqGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $services[$id];
			}
		);

		return new PipelinqGateway($container, $this->createMock(LoggerInterface::class));
	}//end gateway()

	/**
	 * An absent pipelinq is unavailable, and every ask says so.
	 *
	 * Not an empty result: a surface has to be able to tell "pipelinq says
	 * there is nothing" from "pipelinq is not installed".
	 *
	 * @return void
	 */
	public function testAnAbsentPipelinqIsUnavailable(): void {
		$gateway = $this->gateway();

		$this->assertFalse($gateway->isAvailable());

		$answer = $gateway->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'resolve',
			arguments: ['partyId' => 'p1'],
			fallback: ['fallback'],
		);

		$this->assertFalse($answer['answered'], 'An absent pipelinq did not answer; it was not asked.');
		$this->assertSame(['fallback'], $answer['value']);
		$this->assertNotSame('', $answer['reason']);
	}//end testAnAbsentPipelinqIsUnavailable()

	/**
	 * A pipelinq whose service lacks the method is refused before it is called.
	 *
	 * Returning it would fatal on the first call instead of degrading, which
	 * is the difference between a surface that says "unavailable" and a 500.
	 *
	 * @return void
	 */
	public function testAPartialServiceIsRefused(): void {
		$partial = new class {
			/**
			 * The method an older pipelinq has.
			 *
			 * @return array<int, mixed> Nothing.
			 */
			public function list(): array {
				return [];
			}
		};

		$gateway = $this->gateway([PipelinqGateway::CONTACT_MOMENTS => $partial]);

		$this->assertNotNull(
			$gateway->service(class: PipelinqGateway::CONTACT_MOMENTS, methods: ['list']),
			'The method it does have resolves.'
		);
		$this->assertNull(
			$gateway->service(class: PipelinqGateway::CONTACT_MOMENTS, methods: ['list', 'create']),
			'The method it does not have is refused, rather than fataling at the call.'
		);
	}//end testAPartialServiceIsRefused()

	/**
	 * A service that throws answers the fallback rather than the page.
	 *
	 * @return void
	 */
	public function testAThrowingServiceAnswersTheFallback(): void {
		$throwing = new class {
			/**
			 * @return array<int, mixed> Never.
			 */
			public function resolve(): array {
				throw new RuntimeException('pipelinq fell over');
			}
		};

		$answer = $this->gateway([PipelinqGateway::PARTY_INDICATORS => $throwing])->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'resolve',
			fallback: [],
		);

		$this->assertFalse($answer['answered']);
		$this->assertSame([], $answer['value']);
		$this->assertStringContainsString('fell over', $answer['reason']);
	}//end testAThrowingServiceAnswersTheFallback()

	/**
	 * A service that answers is reported as having answered.
	 *
	 * The control: without it, a gateway that answered `false` to everything
	 * would pass every test above.
	 *
	 * @return void
	 */
	public function testAPresentServiceAnswers(): void {
		$present = new class {
			/**
			 * @return array<int, string> The answer.
			 */
			public function resolve(): array {
				return ['overleden'];
			}

			/**
			 * @return array<int, mixed> Nothing, so isAvailable() passes.
			 */
			public function list(): array {
				return [];
			}
		};

		$gateway = $this->gateway(
			[
				PipelinqGateway::PARTY_INDICATORS => $present,
				PipelinqGateway::CONTACT_MOMENTS => $present,
			]
		);

		$this->assertTrue($gateway->isAvailable());

		$answer = $gateway->ask(
			class: PipelinqGateway::PARTY_INDICATORS,
			method: 'resolve',
			fallback: [],
		);

		$this->assertTrue($answer['answered']);
		$this->assertSame(['overleden'], $answer['value']);
	}//end testAPresentServiceAnswers()

	/**
	 * Only the gateway names pipelinq.
	 *
	 * A property of the tree rather than of a screen: six consumers resolving
	 * their own class would leave five copies looking healthy the day pipelinq
	 * renames one of them.
	 *
	 * @return void
	 */
	public function testOnlyTheGatewayNamesPipelinq(): void {
		$root = dirname(__DIR__, 4) . '/lib';
		$offenders = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			if (str_ends_with($path, 'Service/Pipelinq/PipelinqGateway.php') === true) {
				continue;
			}

			$contents = (string)file_get_contents($path);
			if (str_contains($contents, 'OCA\\Pipelinq') === true) {
				$offenders[] = substr($path, strlen($root));
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Every pipelinq class name belongs in the gateway: ' . implode(', ', $offenders)
		);
	}//end testOnlyTheGatewayNamesPipelinq()
}//end class
