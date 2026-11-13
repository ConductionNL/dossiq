<?php

/**
 * OpenRegister Sharing Gateway Test
 *
 * Pins how the sharing code reaches OpenRegister's services: nothing when
 * OpenRegister is not installed, nothing (and a warning) when a service does
 * not resolve or is an older shape than this code calls, and the service
 * itself otherwise.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for OpenRegisterSharingGateway.
 */
#[CoversClass(OpenRegisterSharingGateway::class)]
final class OpenRegisterSharingGatewayTest extends TestCase {
	/**
	 * The logger the gateway warns on.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A gateway over a container that serves the given services by class name.
	 *
	 * @param array<string,object> $services  The services, keyed by class name.
	 * @param bool                 $installed Whether OpenRegister is installed.
	 *
	 * @return OpenRegisterSharingGateway
	 */
	private function gateway(array $services, bool $installed = true): OpenRegisterSharingGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('openregister')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);

		return new OpenRegisterSharingGateway($appManager, $container, $this->logger);
	}

	/**
	 * An object answering every method the gateway checks for.
	 *
	 * @return object
	 */
	private function fullShape(): object {
		return new class {
			public function mint(): void {
			}

			public function revoke(): void {
			}

			public function setDisabled(): void {
			}

			public function resolve(): void {
			}

			public function read(): void {
			}

			public function getNotesForObject(): void {
			}

			public function createOutgoingShare(): void {
			}

			public function setStatus(): void {
			}
		};
	}

	/**
	 * Without OpenRegister installed every accessor answers null, and the container is never asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryServiceIsNull(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$gateway = new OpenRegisterSharingGateway($appManager, $container, $this->logger);

		$this->assertNull($gateway->objectService());
		$this->assertNull($gateway->accessLinkService());
		$this->assertNull($gateway->accessLinkReader());
		$this->assertNull($gateway->noteService());
		$this->assertNull($gateway->federationShareService());
	}

	/**
	 * Each accessor resolves its own OpenRegister class.
	 *
	 * @return void
	 */
	public function testEachAccessorResolvesItsService(): void {
		$objects = new \stdClass();
		$links = $this->fullShape();
		$reader = $this->fullShape();
		$notes = $this->fullShape();
		$federation = $this->fullShape();
		$gateway = $this->gateway(
			[
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Service\Sharing\AccessLinkService' => $links,
				'OCA\OpenRegister\Service\Sharing\AccessLinkReader' => $reader,
				'OCA\OpenRegister\Service\NoteService' => $notes,
				'OCA\OpenRegister\Service\FederationShareService' => $federation,
			]
		);

		$this->assertSame($objects, $gateway->objectService());
		$this->assertSame($links, $gateway->accessLinkService());
		$this->assertSame($reader, $gateway->accessLinkReader());
		$this->assertSame($notes, $gateway->noteService());
		$this->assertSame($federation, $gateway->federationShareService());
	}

	/**
	 * An ObjectService that does not resolve is null, with a warning naming the cause.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceIsNullAndWarned(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: ObjectService unavailable',
			['exception' => 'not registered: OCA\OpenRegister\Service\ObjectService']
		);

		$this->assertNull($this->gateway([])->objectService());
	}

	/**
	 * A sharing service that does not resolve is null, with a warning naming the class.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSharingServiceIsNullAndWarned(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: OpenRegister service unavailable',
			[
				'class' => 'OCA\OpenRegister\Service\Sharing\AccessLinkReader',
				'exception' => 'not registered: OCA\OpenRegister\Service\Sharing\AccessLinkReader',
			]
		);

		$this->assertNull($this->gateway([])->accessLinkReader());
	}

	/**
	 * A sharing service missing a method this code calls is refused as an older shape.
	 *
	 * @return void
	 */
	public function testAnOlderShapedServiceIsRefused(): void {
		$older = new class {
			public function mint(): void {
			}

			public function revoke(): void {
			}

			public function resolve(): void {
			}
		};

		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: OpenRegister service is an older shape than this code calls',
			['class' => 'OCA\OpenRegister\Service\Sharing\AccessLinkService', 'missing' => 'setDisabled']
		);

		$this->assertNull($this->gateway(['OCA\OpenRegister\Service\Sharing\AccessLinkService' => $older])->accessLinkService());
	}

	/**
	 * A federation service without both methods is null, silently; one that does not resolve is null with a warning.
	 *
	 * @return void
	 */
	public function testAFederationServiceNeedsBothMethods(): void {
		$halfShape = new class {
			public function createOutgoingShare(): void {
			}
		};

		$this->assertNull($this->gateway(['OCA\OpenRegister\Service\FederationShareService' => $halfShape])->federationShareService());

		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: OR FederationShareService unavailable (federation leaf not present)',
			['exception' => 'not registered: OCA\OpenRegister\Service\FederationShareService']
		);
		$this->assertNull($this->gateway([])->federationShareService());
	}

	/**
	 * toArray keeps arrays, serialises JsonSerializable objects, and answers [] for anything else.
	 *
	 * @return void
	 */
	public function testToArrayNormalisesValues(): void {
		$gateway = $this->gateway([]);
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['uuid' => 'case-1'];
			}
		};

		$this->assertSame(['a' => 1], $gateway->toArray(['a' => 1]));
		$this->assertSame(['uuid' => 'case-1'], $gateway->toArray($serialisable));
		$this->assertSame([], $gateway->toArray(new \stdClass()));
		$this->assertSame([], $gateway->toArray('case-1'));
		$this->assertSame([], $gateway->toArray(null));
	}
}
