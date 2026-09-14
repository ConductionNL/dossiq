<?php

/**
 * A settable `php://input` for controller tests.
 *
 * Three dossiq controllers read their body with
 * `file_get_contents('php://input')` because `OCP\IRequest::getContent()` is
 * protected on the concrete request. Under PHPUnit that stream is empty, so a
 * test could only ever drive the empty-body path. This wrapper stands in for
 * `php://` while one call runs and is torn down immediately after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

/**
 * Stream wrapper serving a canned `php://input`.
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */
class PhpInputStream {
	/**
	 * The body `php://input` answers with.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * The read cursor.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * The stream context, set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Run a callback with `php://input` answering the given body.
	 *
	 * @param string $body The request body.
	 * @param callable $run The call under test.
	 *
	 * @return mixed Whatever the callback returns.
	 */
	public static function with(string $body, callable $run): mixed {
		self::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', self::class);

		try {
			return $run();
		} finally {
			stream_wrapper_restore('php');
			self::$body = '';
		}
	}//end with()

	/**
	 * Open the stream.
	 *
	 * @param string $path The requested path.
	 * @param string $mode The open mode.
	 * @param int $options The open options.
	 * @param string|null $openedPath The resolved path, by reference.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		$openedPath = $path;
		return true;
	}//end stream_open()

	/**
	 * Read from the stream.
	 *
	 * @param int $count The number of bytes requested.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * Accept and discard a write, so an incidental `php://` write cannot fail.
	 *
	 * @param string $data The bytes written.
	 *
	 * @return int
	 */
	public function stream_write(string $data): int {
		return strlen($data);
	}//end stream_write()

	/**
	 * Whether the cursor is past the end.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * The current cursor.
	 *
	 * @return int
	 */
	public function stream_tell(): int {
		return $this->position;
	}//end stream_tell()

	/**
	 * Move the cursor.
	 *
	 * @param int $offset The offset.
	 * @param int $whence The origin.
	 *
	 * @return bool
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
		$length = strlen(self::$body);
		$target = match ($whence) {
			SEEK_CUR => ($this->position + $offset),
			SEEK_END => ($length + $offset),
			default => $offset,
		};

		if ($target < 0 || $target > $length) {
			return false;
		}

		$this->position = $target;
		return true;
	}//end stream_seek()

	/**
	 * Stream metadata, enough for file_get_contents().
	 *
	 * @return array<string, mixed>
	 */
	public function stream_stat(): array {
		return ['size' => strlen(self::$body)];
	}//end stream_stat()

	/**
	 * Path metadata, enough for file_get_contents().
	 *
	 * @param string $path The path.
	 * @param int $flags The flags.
	 *
	 * @return array<string, mixed>
	 */
	public function url_stat(string $path, int $flags): array {
		return ['size' => strlen(self::$body)];
	}//end url_stat()

	/**
	 * Set an option; nothing here needs one.
	 *
	 * @param int $option The option.
	 * @param int $arg1 The first argument.
	 * @param int $arg2 The second argument.
	 *
	 * @return bool
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool {
		return false;
	}//end stream_set_option()
}//end class
