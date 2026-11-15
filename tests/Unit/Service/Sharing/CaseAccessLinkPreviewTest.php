<?php

/**
 * When the holder preview of an access link answers nothing.
 *
 * Characterisation written against the unsplit holderPreview() and run green
 * on it before it was split.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\AccessLinkProjection;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Sharing\CaseAccessLinkService
 */
class CaseAccessLinkPreviewTest extends TestCase {

	/**
	 * Error lines logged, with their context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $errors = [];

	/**
	 * The anchors resolved.
	 *
	 * @var array<int, string>
	 */
	public array $resolved = [];

	/**
	 * The preview through a gateway answering the given collaborators.
	 *
	 * @param object|null $service The OR AccessLinkService.
	 * @param object|null $reader  The OR AccessLinkReader.
	 *
	 * @return CaseAccessLinkService
	 */
	private function links(?object $service, ?object $reader): CaseAccessLinkService {
		$gateway = $this->createMock(OpenRegisterSharingGateway::class);
		$gateway->method('accessLinkService')->willReturn($service);
		$gateway->method('accessLinkReader')->willReturn($reader);

		$projection = $this->createMock(AccessLinkProjection::class);
		$projection->method('strip')->willReturnCallback(static fn (array $body): array => ['stripped' => $body]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new CaseAccessLinkService(gateway: $gateway, projection: $projection, logger: $logger);
	}//end links()

	/**
	 * A link service that resolves known anchors to a link.
	 *
	 * @return object
	 */
	private function service(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param CaseAccessLinkPreviewTest $test The test.
			 */
			public function __construct(private readonly CaseAccessLinkPreviewTest $test) {
			}//end __construct()

			/**
			 * @param string $anchor The anchor.
			 *
			 * @return array<string, string>|null
			 */
			public function resolve(string $anchor): ?array {
				$this->test->resolved[] = $anchor;
				if ($anchor === 'unknown') {
					return null;
				}

				return ['anchor' => $anchor];
			}
		};
	}//end service()

	/**
	 * A reader that answers the given body, or throws it.
	 *
	 * @param mixed $body The body.
	 *
	 * @return object
	 */
	private function reader(mixed $body): object {
		return new class($body) {
			/**
			 * @param mixed $body The body.
			 */
			public function __construct(private readonly mixed $body) {
			}//end __construct()

			/**
			 * @param array<string, string> $link The link.
			 *
			 * @return mixed
			 */
			public function read(array $link): mixed {
				if ($this->body instanceof \Throwable) {
					throw $this->body;
				}

				return $this->body;
			}
		};
	}//end reader()

	/**
	 * The preview is the stripped body; every missing piece or failure answers nothing.
	 *
	 * @return void
	 */
	public function testThePreviewAnswersNothingWhenAnyPieceIsMissing(): void {
		$this->assertSame(['stripped' => ['subject' => 'x']], $this->links(service: $this->service(), reader: $this->reader(['subject' => 'x']))->holderPreview(' a-1 '));
		$this->assertSame(['a-1'], $this->resolved);

		$this->assertNull($this->links(service: null, reader: $this->reader([]))->holderPreview('a-2'));
		$this->assertNull($this->links(service: $this->service(), reader: null)->holderPreview('a-3'));
		$this->assertSame(['a-1'], $this->resolved);

		$this->assertNull($this->links(service: $this->service(), reader: $this->reader(['x']))->holderPreview('unknown'));
		$this->assertNull($this->links(service: $this->service(), reader: $this->reader('text'))->holderPreview('a-4'));
		$this->assertSame([], $this->errors);

		$this->assertNull($this->links(service: $this->service(), reader: $this->reader(new \RuntimeException('down')))->holderPreview('a-5'));
		$this->assertSame([['CaseAccessLinkService: could not read a link preview', ['exception' => 'down']]], $this->errors);
	}//end testThePreviewAnswersNothingWhenAnyPieceIsMissing()
}//end class
