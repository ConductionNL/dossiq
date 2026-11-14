<?php

/**
 * File Restriction Mirror Test
 *
 * A document's access exception is applied to its file through OpenRegister's
 * FileRestrictionService, which is optional: an instance without it reports
 * unavailable and refuses to restrict or lift rather than serving the bytes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror
 */
class FileRestrictionMirrorTest extends TestCase {

	/**
	 * Define OpenRegister's service class, so the mirror sees it installed.
	 *
	 * @return void
	 */
	private function defineService(): void {
		if (class_exists(FileRestrictionMirror::SERVICE) === false) {
			eval('namespace OCA\OpenRegister\Service\File; class FileRestrictionService {}');
		}
	}//end defineService()

	/**
	 * A recorder standing in for OpenRegister's service.
	 *
	 * @return object The recorder, with its calls in `$calls`.
	 */
	private function recorder(): object {
		return new class {
			/** @var list<array<int, mixed>> */
			public array $calls = [];

			public function restrict(int $fileId, array $users, array $groups, string $reason): void {
				$this->calls[] = ['restrict', $fileId, $users, $groups, $reason];
			}

			public function lift(int $fileId): void {
				$this->calls[] = ['lift', $fileId];
			}
		};
	}//end recorder()

	/**
	 * A container answering the service id with the given value or exception.
	 *
	 * @param mixed $answer The service, or a Throwable to throw.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(mixed $answer): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$get       = $container->method('get')->with(FileRestrictionMirror::SERVICE);
		if ($answer instanceof \Throwable) {
			$get->willThrowException($answer);
		} else {
			$get->willReturn($answer);
		}

		return $container;
	}//end container()

	/**
	 * Without OpenRegister's class the mirror is unavailable and refuses, without asking the container.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheMirrorIsUnavailableAndRefuses(): void {
		$this->assertFalse(class_exists(FileRestrictionMirror::SERVICE));
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$mirror = new FileRestrictionMirror(container: $container);

		$this->assertFalse($mirror->isAvailable());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister on this instance cannot restrict a file');
		$mirror->restrict(fileId: 7, users: ['hanna'], groups: [], reason: 'r');
	}//end testWithoutOpenRegisterTheMirrorIsUnavailableAndRefuses()

	/**
	 * Restrict and lift reach OpenRegister's service with exactly what they were given.
	 *
	 * @return void
	 */
	public function testRestrictAndLiftAreHandedToOpenRegister(): void {
		$this->defineService();
		$service = $this->recorder();
		$mirror  = new FileRestrictionMirror(container: $this->container(answer: $service));

		$this->assertTrue($mirror->isAvailable());
		$mirror->restrict(fileId: 42, users: ['hanna', 'pieter'], groups: ['woo-team'], reason: 'Bijzondere persoonsgegevens');
		$mirror->lift(fileId: 42);

		$this->assertSame(
			expected: [
				['restrict', 42, ['hanna', 'pieter'], ['woo-team'], 'Bijzondere persoonsgegevens'],
				['lift', 42],
			],
			actual: $service->calls
		);
	}//end testRestrictAndLiftAreHandedToOpenRegister()

	/**
	 * A container that cannot build the service makes the mirror unavailable, and lift refuses.
	 *
	 * @return void
	 */
	public function testAContainerThatCannotBuildTheServiceRefuses(): void {
		$this->defineService();
		$mirror = new FileRestrictionMirror(container: $this->container(answer: new RuntimeException('no service')));

		$this->assertFalse($mirror->isAvailable());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister on this instance cannot restrict a file');
		$mirror->lift(fileId: 42);
	}//end testAContainerThatCannotBuildTheServiceRefuses()

	/**
	 * A container answering something that is not an object counts as no service.
	 *
	 * @return void
	 */
	public function testANonObjectAnswerIsNoService(): void {
		$this->defineService();
		$mirror = new FileRestrictionMirror(container: $this->container(answer: 'not a service'));

		$this->assertFalse($mirror->isAvailable());
		$this->expectException(RuntimeException::class);
		$mirror->restrict(fileId: 1, users: [], groups: ['g'], reason: 'r');
	}//end testANonObjectAnswerIsNoService()
}//end class
