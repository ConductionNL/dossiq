<?php

/**
 * PortalWooAnswerController tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PortalWooAnswerController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Portal\PortalWooAnswer;
use OCA\Dossiq\Portal\WooAnswerSubmission;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\PortalWooAnswerController
 * @uses   \OCA\Dossiq\Portal\WooAnswerSubmission
 */
class PortalWooAnswerControllerTest extends TestCase {

	/** @var PortalAssertionVerifier&MockObject */
	private PortalAssertionVerifier $verifier;

	/** @var PortalWooAnswer&MockObject */
	private PortalWooAnswer $answers;

	/** @var IThrottler&MockObject */
	private IThrottler $throttler;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var list<string> */
	private array $tempFiles = [];

	protected function setUp(): void {
		$this->verifier = $this->createMock(PortalAssertionVerifier::class);
		$this->answers = $this->createMock(PortalWooAnswer::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->with('X-Portal-Subject')->willReturn('jwt-token');
		$this->request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$this->request->method('getParam')->willReturnMap([['requestId', '', 'req-1'], ['antwoord', '', 'Mijn antwoord']]);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			@unlink($file);
		}
	}

	private function controller(): PortalWooAnswerController {
		return new PortalWooAnswerController('dossiq', $this->request, $this->verifier, $this->answers, $this->throttler, $this->logger);
	}

	private function tempFile(string $content): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'woo');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;

		return $path;
	}

	private function citizen(): void {
		$this->verifier->method('verify')->with('jwt-token')->willReturn(['sub' => 'bsn-1', 'audience' => 'citizen']);
	}

	public function testARejectedAssertionIsThrottledAndUnauthorized(): void {
		$this->verifier->method('verify')->willReturn(null);
		$this->throttler->expects($this->once())->method('registerAttempt')->with('dossiq_portal_assertion', '192.0.2.7');
		$this->answers->expects($this->never())->method('submit');

		$response = $this->controller()->answer();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthorized'], $response->getData());
	}

	public function testAFailingThrottlerIsLoggedAndStillUnauthorized(): void {
		$this->verifier->method('verify')->willReturn(null);
		$this->throttler->method('registerAttempt')->willThrowException(new RuntimeException('cache down'));
		$this->logger->expects($this->once())->method('warning')
			->with('PortalWooAnswerController: registerAttempt failed', ['error' => 'cache down']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->answer()->getStatus());
	}

	public function testAnAssertionForAnotherAudienceIsForbidden(): void {
		$this->verifier->method('verify')->willReturn(['sub' => 'x', 'audience' => 'employee']);
		$this->throttler->expects($this->never())->method('registerAttempt');
		$this->answers->expects($this->never())->method('submit');

		$response = $this->controller()->answer();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}

	public function testAnAnswerWithoutFilesIsSubmittedForTheSubject(): void {
		$this->verifier->method('verify')->willReturn(['sub' => 'kvk-9', 'audience' => 'client']);
		$this->request->method('getUploadedFile')->with('bijlagen')->willReturn(null);
		$this->answers->expects($this->once())->method('submit')
			->with($this->callback(
				fn (WooAnswerSubmission $s): bool => $s->subjectRef === 'kvk-9'
					&& $s->requestId === 'req-1'
					&& $s->answer === 'Mijn antwoord'
					&& $s->attachments === []
			))
			->willReturn(['body' => ['id' => 'ans-1'], 'status' => Http::STATUS_CREATED]);

		$response = $this->controller()->answer();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'ans-1'], $response->getData());
	}

	public function testANonStringParameterIsSubmittedAsEmpty(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturn('jwt-token');
		$this->request->method('getParam')->willReturn(['nested']);
		$this->citizen();
		$this->answers->expects($this->once())->method('submit')
			->with($this->callback(fn (WooAnswerSubmission $s): bool => $s->requestId === '' && $s->answer === ''))
			->willReturn(['body' => [], 'status' => Http::STATUS_UNPROCESSABLE_ENTITY]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->answer()->getStatus());
	}

	public function testASingleUploadIsReadWithItsBaseName(): void {
		$this->citizen();
		$path = $this->tempFile('PDF-bytes');
		$this->request->method('getUploadedFile')->willReturn(['name' => '../../etc/brief.pdf', 'tmp_name' => $path, 'size' => 9]);
		$this->answers->expects($this->once())->method('submit')
			->with($this->callback(
				fn (WooAnswerSubmission $s): bool => $s->attachments === [['name' => 'brief.pdf', 'content' => 'PDF-bytes']]
			))
			->willReturn(['body' => ['ok' => true], 'status' => Http::STATUS_OK]);

		$this->assertSame(['ok' => true], $this->controller()->answer()->getData());
	}

	public function testMultipleUploadsSkipUnreadableEntries(): void {
		$this->citizen();
		$first = $this->tempFile('one');
		$this->request->method('getUploadedFile')->willReturn([
			'name' => ['a.txt', 'b.txt', 'c.txt'],
			'tmp_name' => [$first, '', '/nonexistent/woo-upload'],
			'size' => [3, 0, 1],
		]);
		$this->answers->expects($this->once())->method('submit')
			->with($this->callback(fn (WooAnswerSubmission $s): bool => $s->attachments === [['name' => 'a.txt', 'content' => 'one']]))
			->willReturn(['body' => [], 'status' => Http::STATUS_OK]);

		$this->controller()->answer();
	}

	public function testMoreThanFiveFilesIsRefused(): void {
		$this->citizen();
		$this->request->method('getUploadedFile')->willReturn([
			'name' => array_fill(0, 6, 'x.txt'),
			'tmp_name' => array_fill(0, 6, '/nonexistent'),
			'size' => array_fill(0, 6, 1),
		]);
		$this->answers->expects($this->never())->method('submit');

		$response = $this->controller()->answer();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'invalid', 'message' => 'Send at most five files of at most 10 MB each.'], $response->getData());
	}

	public function testAFileOverTenMegabytesIsRefused(): void {
		$this->citizen();
		$this->request->method('getUploadedFile')->willReturn(['name' => 'big.pdf', 'tmp_name' => '/nonexistent', 'size' => 10485761]);
		$this->answers->expects($this->never())->method('submit');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->answer()->getStatus());
	}

	public function testAFileOfExactlyTenMegabytesIsAccepted(): void {
		$this->citizen();
		$this->request->method('getUploadedFile')->willReturn(['name' => 'max.pdf', 'tmp_name' => '/nonexistent', 'size' => 10485760]);
		$this->answers->expects($this->once())->method('submit')->willReturn(['body' => [], 'status' => Http::STATUS_OK]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->answer()->getStatus());
	}
}
