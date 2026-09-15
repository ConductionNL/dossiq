<?php

/**
 * A declared tool assumes no typing, and dossiq ships no recogniser.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use OCA\Dossiq\Mcp\DossiqToolProvider;
use OCA\Dossiq\Mcp\Tool\DossiqCaseAuthorizer;
use OCA\Dossiq\Mcp\Tool\DossiqCaseReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Decision D13 places the assistant in hermiq, so dictation is hermiq's.
 *
 * What is left for dossiq is that a dictated instruction reaches a declared
 * tool exactly as a typed one does. A tool whose contract names a keyboard
 * affordance or a typed format has quietly assumed the second, and this test
 * fails naming it.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-assistant-via-hermiq/spec.md
 */
class DeclaredToolAssumesNoTypingTest extends TestCase {

	/**
	 * Words that only make sense to somebody at a keyboard.
	 *
	 * @var array<string>
	 */
	private const TYPING_TERMS = [
		'keyboard',
		'keystroke',
		'shortcut',
		'press enter',
		'type the',
		'typed',
		'paste',
		'copy-paste',
		'clipboard',
		'command line',
		'autocomplete',
	];

	/**
	 * The directories a speech recogniser would have to live in.
	 *
	 * @var array<string>
	 */
	private const SOURCE_DIRS = ['lib', 'src'];

	/**
	 * Names of the browser and server speech APIs dossiq must not reach for.
	 *
	 * @var array<string>
	 */
	private const RECOGNISER_APIS = [
		'SpeechRecognition',
		'webkitSpeechRecognition',
		'speechSynthesis',
		'whisper.cpp',
		'vosk',
	];

	/**
	 * No declared tool's contract names a keyboard affordance or a typed format.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-assistant-via-hermiq/spec.md
	 */
	public function testNoDeclaredToolAssumesItWasTyped(): void {
		$provider = new DossiqToolProvider(
			caseReader: $this->createMock(DossiqCaseReader::class),
			authorizer: $this->createMock(DossiqCaseAuthorizer::class),
		);

		$tools = $provider->getTools();
		$this->assertNotEmpty($tools, 'dossiq declares no tool at all, so this test cannot see one.');

		foreach ($tools as $tool) {
			$contract = strtolower((string)json_encode($tool));
			foreach (self::TYPING_TERMS as $term) {
				$this->assertStringNotContainsString(
					$term,
					$contract,
					'Declared tool "' . ($tool['id'] ?? '?') . '" names "' . $term . '" in its contract, '
					. 'so it assumes the instruction was typed. A dictated instruction must reach it the same way.'
				);
			}
		}
	}//end testNoDeclaredToolAssumesItWasTyped()

	/**
	 * A tool contract that names a keyboard is what this test is for.
	 *
	 * The scanner above passes trivially on a tree that has no such tool, and a
	 * test that cannot fail reports the same green as one that passed. So this
	 * runs the same scan over a contract that does name one and asserts it is
	 * caught.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-assistant-via-hermiq/spec.md
	 */
	public function testAToolThatAssumesTypingIsCaught(): void {
		$offending = [
			'id' => 'dossiq.example',
			'description' => 'Press enter to confirm the case id you typed.',
		];

		$contract = strtolower((string)json_encode($offending));
		$hits = [];
		foreach (self::TYPING_TERMS as $term) {
			if (str_contains($contract, $term) === true) {
				$hits[] = $term;
			}
		}

		$this->assertNotEmpty($hits, 'The scan misses a contract that plainly names a keyboard.');
		$this->assertContains('press enter', $hits);
	}//end testAToolThatAssumesTypingIsCaught()

	/**
	 * dossiq ships no speech recognition: the assistant, and dictating to it,
	 * are hermiq's under D13.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-assistant-via-hermiq/spec.md
	 */
	public function testDossiqShipsNoRecogniser(): void {
		$root = dirname(__DIR__, 3);
		$found = [];

		foreach (self::SOURCE_DIRS as $dir) {
			$path = $root . '/' . $dir;
			if (is_dir($path) === false) {
				continue;
			}

			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
			foreach ($files as $file) {
				if ($file->isFile() === false) {
					continue;
				}

				if (in_array($file->getExtension(), ['php', 'js', 'vue', 'ts'], true) === false) {
					continue;
				}

				$contents = (string)file_get_contents($file->getPathname());
				foreach (self::RECOGNISER_APIS as $api) {
					if (str_contains($contents, $api) === true) {
						$found[] = $file->getPathname() . ' names ' . $api;
					}
				}
			}
		}

		$this->assertSame(
			[],
			$found,
			'dossiq reaches for speech recognition, which belongs to hermiq under decision D13.'
		);
	}//end testDossiqShipsNoRecogniser()

}//end class
