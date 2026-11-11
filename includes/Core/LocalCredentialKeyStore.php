<?php
/**
 * Site-local credential key storage.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

// Native no-replace/locking primitives are required; every suppressed failure is checked.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
// Base64 is the exact non-executable storage encoding, not code obfuscation.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

/** Read-only consumption with explicitly authorized, no-replace provisioning. */
final class LocalCredentialKeyStore {
	public const STATE_OPTION = 'scalyn_mail_relay_local_key_state';
	private const ERROR       = 'Secure credential storage is unavailable. Restore the site key or check private storage permissions.';
	/**
	 * Explicit trusted root, not a cached blog identity.
	 *
	 * @var ?string
	 */
	private ?string $content_root;
	/**
	 * Unit-test-only reference probe.
	 *
	 * @var ?\Closure
	 */
	private ?\Closure $has_references;
	/**
	 * Explicit unit-fixture marker reader; production always reads the database.
	 *
	 * @var ?\Closure
	 */
	private ?\Closure $read_state;
	/**
	 * Explicit mocked marker writer; production always uses guarded native add_option.
	 *
	 * @var ?\Closure
	 */
	private ?\Closure $write_state;
	/**
	 * Select a trusted content root and optional unit-test reference probe.
	 *
	 * @param ?string   $content_root Trusted root; defaults to the configured WordPress content directory.
	 * @param ?\Closure $has_references Unit-test-only reference probe; production uses the repository.
	 * @param ?\Closure $read_state Unit-test-only marker reader; returns null only for proven absence.
	 * @param ?\Closure $write_state Explicit mocked marker writer; omitted in production.
	 */
	public function __construct( ?string $content_root = null, ?\Closure $has_references = null, ?\Closure $read_state = null, ?\Closure $write_state = null ) {
		$this->content_root   = $content_root;
		$this->has_references = $has_references;
		$this->read_state     = $read_state;
		$this->write_state    = $write_state;
	}
	/**
	 * Fail without exposing filesystem, SQL or key details.
	 *
	 * @throws \RuntimeException Always, with the fixed safe service message.
	 */
	private function fail(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed, nonsecret service error.
		throw new \RuntimeException( self::ERROR );
	}
	/**
	 * Resolve a positive current site identity on each operation.
	 */
	private function blog(): int {
		$id = get_current_blog_id();
		if ( ! is_int( $id ) || $id < 1 ) {
			$this->fail();
		}
		return $id;
	}
	/**
	 * Canonicalize only the trusted root, permitting an administrator root symlink.
	 */
	private function root(): string {
		$root = $this->content_root;
		if ( null === $root ) {
			$root = defined( 'SCALYN_MAIL_RELAY_KEY_DIRECTORY' ) ? SCALYN_MAIL_RELAY_KEY_DIRECTORY : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
		}
		if ( ! is_string( $root ) || '' === $root || str_contains( $root, '://' ) ) {
			$this->fail();
		}
		$real = realpath( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			$this->fail();
		}
		return rtrim( $real, '/' );
	}
	/**
	 * Return the nonsecret current-site key path without creating anything.
	 */
	public function path(): string {
		return $this->root() . '/scalyn-mail-relay-private/credentials-key-blog-' . $this->blog() . '.php';
	}
	/**
	 * Resolve the effective UID without requiring the optional POSIX extension on Linux.
	 *
	 * @return int Current effective process owner, never a script-file owner guess.
	 */
	private function effective_owner(): int {
		if ( function_exists( 'posix_geteuid' ) ) {
			return posix_geteuid();
		}
		if ( 'Linux' === PHP_OS_FAMILY ) {
			$status = @file_get_contents( '/proc/self/status', false, null, 0, 4096 );
			if ( is_string( $status ) && preg_match( '/^Uid:\h+[0-9]+\h+([0-9]+)\h+[0-9]+\h+[0-9]+\h*$/m', $status, $match ) ) {
				return (int) $match[1];
			}
		}
		$this->fail();
	}

	/**
	 * Reject insecure, foreign-owned, nonregular or symlink objects.
	 *
	 * @param string $path Object below the canonical trusted root.
	 * @param bool   $directory Whether a private directory is required.
	 */
	private function secure( string $path, bool $directory = false ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) ) {
			$this->fail();
		}
		$expected_type = $directory ? 0040000 : 0100000;
		$actual_type   = $stat['mode'] & 0170000;
		$public_mode   = $stat['mode'] & 0077;
		$owner         = $this->effective_owner();
		if ( $expected_type !== $actual_type || 0 !== $public_mode || $owner !== $stat['uid'] ) {
			$this->fail();
		}
		return $stat;
	}
	/**
	 * Parse a bounded PHP wrapper as data, never as executable code.
	 *
	 * @param string $path Secure key or owned temporary path.
	 */
	private function load( string $path ): string {
		$this->secure( dirname( $path ), true );
		$before = $this->secure( $path );
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			$this->fail();
		}
		try {
			$opened = fstat( $handle );
			if ( ! $opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] ) {
				$this->fail();
			}
			$body = stream_get_contents( $handle, 257 );
		} finally {
			fclose( $handle );
		}
		if ( ! is_string( $body ) || ! preg_match( "~\\A<\\?php\nhttp_response_code\(404\);\nexit;\n// scalyn-local-key: ([A-Za-z0-9+/]{43}=)\n\\z~D", $body, $match ) ) {
			$this->fail();
		}
		$key = base64_decode( $match[1], true );
		if ( ! is_string( $key ) || strlen( $key ) !== 32 || ! hash_equals( base64_encode( $key ), $match[1] ) ) {
			$this->fail();
		}
		return $key;
	}
	/**
	 * Read the existing 32-byte key without initializing or repairing storage.
	 */
	public function read(): string {
		try {
			$key   = $this->load( $this->path() );
			$state = $this->state();
			if ( null !== $state && $state !== $this->marker( $key ) ) {
				$this->fail();
			}
			return $key;
		} catch ( \Throwable $e ) {
			$this->fail();
		}
	}
	/**
	 * Distinguish an absent marker from every malformed persisted value.
	 */
	private function state() {
		return null !== $this->read_state ? ( $this->read_state )() : ( new LocalKeyReferenceRepository() )->read_option( self::STATE_OPTION );
	}
	/**
	 * Build nonsecret, site-bound key identification metadata.
	 *
	 * @param string $key Raw key, used only for its fingerprint.
	 */
	private function marker( #[\SensitiveParameter] string $key ): array {
		return array(
			'version'     => 1,
			'blog_id'     => $this->blog(),
			'fingerprint' => hash( 'sha256', $key ),
		);
	}
	/**
	 * Persist and read back the marker before permitting new local ciphertext.
	 *
	 * @param string $key Published and validated key.
	 */
	private function persist( #[\SensitiveParameter] string $key ): void {
		global $wpdb;

		$db       = $wpdb;
		$previous = null;
		try {
			if ( null === $this->write_state ) {
				if ( ! $db instanceof \wpdb || ! is_callable( array( $db, 'suppress_errors' ) ) ) {
					$this->fail();
				}
				$previous = $db->suppress_errors( true );
				if ( ! is_bool( $previous ) || true !== $db->suppress_errors ) {
					$this->fail();
				}
			}
			$marker = $this->marker( $key );
			if ( $this->state() === $marker ) {
				return;
			}
			$added = null !== $this->write_state ? ( $this->write_state )( $marker ) : add_option( self::STATE_OPTION, $marker, '', false );
			if ( ! $added && $this->state() !== $marker ) {
				$this->fail();
			}
			if ( $this->state() !== $marker ) {
				$this->fail();
			}
		} catch ( \Throwable $e ) {
			$this->fail();
		} finally {
			if ( is_bool( $previous ) ) {
				try {
					$db->suppress_errors( $previous );
				} catch ( \Throwable $e ) {
					$this->fail();
				}
			}
		}
	}
	/**
	 * Initialize only at an authorized write boundary, never replacing a key.
	 */
	public function initialize_for_write(): void {
		$lock      = null;
		$temp      = null;
		$owns_temp = false;
		try {
			$path = $this->path();
			if ( $this->is_available() && $this->state() === $this->marker( $this->read() ) ) {
				return;
			}
			if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) && ! current_user_can( 'manage_options' ) ) {
				$this->fail();
			}
			if ( ! $this->can_initialize() ) {
				$this->fail();
			}
			$dir = dirname( $path );
			if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
				$mask = umask( 0077 );
				try {
					if ( ! @mkdir( $dir, 0700 ) && ! is_dir( $dir ) ) {
								$this->fail();
					}
				} finally {
						umask( $mask );
				}
			}
			$this->secure( $dir, true );
			$lockpath = $dir . '/credentials-key-blog-' . $this->blog() . '-lock.php';
			$mask     = umask( 0077 );
			try {
				$lock = @fopen( $lockpath, 'x+b' );
			} finally {
					umask( $mask );
			}
			if ( false === $lock ) {
				$this->secure( $lockpath );
				$lock = @fopen( $lockpath, 'r+b' );
			}
			if ( ! is_resource( $lock ) ) {
				$this->fail();
			}
			$lockstat = $this->secure( $lockpath );
			$opened   = fstat( $lock );
			if ( ! $opened || $opened['ino'] !== $lockstat['ino'] || $opened['dev'] !== $lockstat['dev'] ) {
				$this->fail();
			}
			if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
				$key = $this->read();
				if ( $this->state() !== $this->marker( $key ) ) {
					$this->fail();
				}
				return;
			}
			if ( file_exists( $path ) || is_link( $path ) ) {
				$this->persist( $this->read() );
				return;
			}
			if ( ! $this->can_initialize() ) {
				$this->fail();
			}
			$key  = random_bytes( 32 );
			$body = "<?php\nhttp_response_code(404);\nexit;\n// scalyn-local-key: " . base64_encode( $key ) . "\n";
			$temp = $dir . '/credentials-key-blog-' . $this->blog() . '-' . bin2hex( random_bytes( 16 ) ) . '.php';
			$mask = umask( 0077 );
			try {
				$handle = @fopen( $temp, 'x+b' );
			} finally {
				umask( $mask );
			}
			if ( false === $handle ) {
					$this->fail();
			}
			$owns_temp = true;
			try {
					$offset = 0;
				$length     = strlen( $body );
				while ( $offset < $length ) {
					$written = @fwrite( $handle, substr( $body, $offset ) );
					if ( ! is_int( $written ) || $written < 1 ) {
						$this->fail();
					}
					$offset += $written;
				}
				if ( ! @fflush( $handle ) || ! function_exists( 'fsync' ) || ! @fsync( $handle ) ) {
					$this->fail();
				}
			} finally {
				fclose( $handle );
			}
			if ( ! hash_equals( $key, $this->load( $temp ) ) ) {
				$this->fail();
			}
			if ( ! @link( $temp, $path ) ) {
				$this->persist( $this->read() );
				return;
			}
			$this->persist( $this->read() );
		} catch ( \Throwable $e ) {
			$this->fail();
		} finally {
			if ( $owns_temp && is_string( $temp ) ) {
				@unlink( $temp );
			}
			if ( is_resource( $lock ) ) {
					flock( $lock, LOCK_UN );
					fclose( $lock );
			}
		}
	}
	/**
	 * Report actual read availability without writes.
	 */
	public function is_available(): bool {
		try {
			$this->read();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	/**
	 * Report existing usability or checked safe provisioning without writes.
	 */
	public function can_initialize(): bool {
		try {
			$this->effective_owner();
			$path  = $this->path();
			$state = $this->state();
			if ( file_exists( $path ) || is_link( $path ) ) {
				$key = $this->read();
				return null === $state || $state === $this->marker( $key );
			}
			if ( null !== $state ) {
				return false;
			}
			$dir = dirname( $path );
			if ( file_exists( $dir ) || is_link( $dir ) ) {
				$this->secure( $dir, true );
				if ( ! is_writable( $dir ) ) {
					return false;
				}
				$names = @scandir( $dir );
				if ( ! is_array( $names ) ) {
					return false;
				}
				$prefix = 'credentials-key-blog-' . $this->blog() . '-';
				foreach ( $names as $name ) {
					if ( strncmp( $name, $prefix, strlen( $prefix ) ) !== 0 ) {
						continue;
					}
					if ( $name === $prefix . 'lock.php' ) {
						$this->secure( $dir . '/' . $name );
					} else {
								return false;
					}
				}
			} elseif ( ! is_writable( $this->root() ) ) {
				return false;
			}
			$references = null !== $this->has_references ? ( $this->has_references )() : ( new LocalKeyReferenceRepository() )->has_references();
			return false === $references;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
