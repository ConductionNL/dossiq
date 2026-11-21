<?php

/**
 * DisclosableDocumentCheck tests: a set with an unread file or a document
 * under an access exception is refused, naming the documents.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\DisclosableDocumentCheck;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DisclosableDocumentCheck.
 *
 * @covers \OCA\Dossiq\Service\Publication\DisclosableDocumentCheck
 * @uses   \OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
class DisclosableDocumentCheckTest extends TestCase {

	/**
	 * Every file read and no exception: the set may go out.
	 *
	 * @return void
	 */
	public function testReadFilesWithoutAnExceptionMayGoOut(): void {
		$documents = [
			['fileName' => 'besluit.pdf', 'content' => '%PDF'],
			['title' => 'Bijlage', 'content' => '%PDF', 'accessException' => ['users' => [' '], 'groups' => []]],
		];

		$this->assertNull(actual: (new DisclosableDocumentCheck())->refusal(documents: $documents));
		$this->assertNull(actual: (new DisclosableDocumentCheck())->refusal(documents: []));
	}//end testReadFilesWithoutAnExceptionMayGoOut()

	/**
	 * An unread file is refused first, each named by file name, else title, else id.
	 *
	 * @return void
	 */
	public function testAnUnreadFileIsRefusedByNameBeforeAnException(): void {
		$documents = [
			['fileName' => 'nota.pdf', 'content' => ''],
			['title' => 'Mail', 'id' => 'io-2'],
			['id' => 'io-3', 'content' => null],
			['fileName' => 'geheim.pdf', 'content' => '%PDF', 'accessException' => ['users' => ['pieter']]],
		];

		$this->assertSame(
			expected: ['available' => false, 'reason' => 'documents_unreadable', 'documents' => ['nota.pdf', 'Mail', 'io-3']],
			actual: (new DisclosableDocumentCheck())->refusal(documents: $documents)
		);
	}//end testAnUnreadFileIsRefusedByNameBeforeAnException()

	/**
	 * A read document that names its only readers, by user or by group, is refused.
	 *
	 * @return void
	 */
	public function testADocumentUnderAnAccessExceptionIsRefused(): void {
		$documents = [
			['fileName' => 'open.pdf', 'content' => '%PDF'],
			['fileName' => 'geheim.pdf', 'content' => '%PDF', 'accessException' => ['users' => ['pieter']]],
			['title' => 'Intern', 'content' => '%PDF', 'accessException' => ['groups' => ['juridisch']]],
		];

		$this->assertSame(
			expected: ['available' => false, 'reason' => 'documents_restricted', 'documents' => ['geheim.pdf', 'Intern']],
			actual: (new DisclosableDocumentCheck())->refusal(documents: $documents)
		);
	}//end testADocumentUnderAnAccessExceptionIsRefused()
}//end class
