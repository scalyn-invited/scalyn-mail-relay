<?php
// Explicit fourth-argument marker writer uses the mocked test add_option backend, not native wpdb.

namespace {
	use PHPUnit\Framework\TestCase;
	use Scalyn\MailRelay\Core\LocalCredentialKeyStore;
	use Scalyn\MailRelay\Core\Capabilities;

	final class LocalCredentialKeyStoreTest extends TestCase {
		private string $root;
		protected function setUp(): void {
			$this->root = sys_get_temp_dir() . '/scalyn-key-test-' . bin2hex( random_bytes( 8 ) );
			mkdir( $this->root, 0700 );
			$GLOBALS['_test_wp_options']       = array();
			$GLOBALS['_test_current_user_can'] = array( Capabilities::MANAGE_SETTINGS => true );
			$GLOBALS['_test_blog_id']          = 1;
		}
		protected function tearDown(): void {
			$this->remove( $this->root );
			unset( $GLOBALS['_test_wp_option_write_failures'] );
			$GLOBALS['_test_blog_id'] = 1;
		}
		private function remove( string $path ): void {
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				foreach ( scandir( $path ) as $name ) {
					if ( $name !== '.' && $name !== '..' ) {
						$this->remove( $path . '/' . $name );
					}
				} rmdir( $path );
			} else {
				unlink( $path ); }
		}
		private function store( ?Closure $references = null ): LocalCredentialKeyStore {
			return new LocalCredentialKeyStore( $this->root, $references ?? static fn(): bool => false, static function () {
                $absent = new \stdClass();
                $value = \Scalyn\MailRelay\Core\get_option(LocalCredentialKeyStore::STATE_OPTION, $absent);
                return $value === $absent ? null : (null === $value ? false : $value);
            } , static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false )); }
        public function test_cached_absent_marker_cannot_authorize_a_new_key_even_with_reference_injection(): void {
            $previous = $GLOBALS['wpdb'] ?? null;
            $db = new LocalReferenceWpdb();
            $db->stored[LocalCredentialKeyStore::STATE_OPTION] = serialize(['version'=>1,'blog_id'=>1,'fingerprint'=>str_repeat('0',64)]);
            $GLOBALS['wpdb'] = $db;
            try {
                $store = new LocalCredentialKeyStore($this->root, static fn()=>false);
                $this->assertFalse($store->can_initialize(), 'A cached missing marker must not hide the persisted marker.');
                try {$store->initialize_for_write(); $this->fail('Unknown state initialized');}
                catch (RuntimeException $error) {$this->assertNull($error->getPrevious());}
                $this->assertSame(['.','..'],scandir($this->root));
                $this->assertSame([], $GLOBALS['_test_wp_options']);
            } finally {$GLOBALS['wpdb'] = $previous;}
        }
		/** @dataProvider unsafeNativeWriters */
		public function test_default_writer_requires_native_database_policy_even_with_injected_reader( $database ): void {
			$previous = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb'] = $database;
			$store = new LocalCredentialKeyStore( $this->root, static fn() => false, static fn() => null );
			try {
				for ( $attempt = 0; $attempt < 2; ++$attempt ) {
					try {
						$store->initialize_for_write();
						$this->fail( 'A reader injection must not bypass the native writer policy.' );
					} catch ( RuntimeException $error ) {
						$this->assertSame( 'Secure credential storage is unavailable. Restore the site key or check private storage permissions.', $error->getMessage() );
						$this->assertNull( $error->getPrevious() );
					}
					$this->assertSame( array(), $GLOBALS['_test_wp_options'] );
					if ( 0 === $attempt ) {
						$body = file_get_contents( $store->path() );
						$inode = fileinode( $store->path() );
					} else {
						$this->assertSame( $body, file_get_contents( $store->path() ) );
						$this->assertSame( $inode, fileinode( $store->path() ) );
					}
				}
				$cipher = new \Scalyn\MailRelay\Core\CredentialCipher( null, $store );
				$settings = new \Scalyn\MailRelay\Core\SettingsRepository( $cipher );
				try {
					$settings->save( array( 'provider' => array( 'active' => 'smtp' ), 'smtp' => array( 'host' => 'synthetic.example.invalid', 'from_email' => 'sender@example.invalid', 'username' => 'synthetic-user', 'password' => 'synthetic-password' ) ) );
					$this->fail( 'Unsafe marker persistence must block credential persistence.' );
				} catch ( RuntimeException $error ) {
					$this->assertNull( $error->getPrevious() );
				}
				$this->assertSame( array(), $GLOBALS['_test_wp_options'] );
				$this->assertSame( $body, file_get_contents( $store->path() ) );
				$this->assertSame( $inode, fileinode( $store->path() ) );
			} finally {
				$GLOBALS['wpdb'] = $previous;
			}
		}
		public static function unsafeNativeWriters(): array {
			return array( 'missing' => array( null ), 'non-native' => array( new stdClass() ) );
		}
		public function test_missing_read_and_status_never_create_storage(): void {
			$store = $this->store();
			$this->assertFalse( $store->is_available() );
			$this->assertTrue( $store->can_initialize() );
			$this->assertSame( $this->root . '/scalyn-mail-relay-private/credentials-key-blog-1.php', $store->path() );
			$this->assertSame( array( '.', '..' ), scandir( $this->root ) );
			$this->expectException( RuntimeException::class );
			$store->read();
		}
		public function test_authorized_initialization_publishes_persistent_key_and_nonsecret_marker(): void {
			$store = $this->store();
			$store->initialize_for_write();
			$key = $store->read();
			$this->assertSame( 32, strlen( $key ) );
			$this->assertTrue( hash_equals( $key, $this->store()->read() ) );
			$this->assertSame( 0600, fileperms( $store->path() ) & 0777 );
			$this->assertSame( 0700, fileperms( dirname( $store->path() ) ) & 0777 );
			$this->assertSame(
				array(
					'version'     => 1,
					'blog_id'     => 1,
					'fingerprint' => hash( 'sha256', $key ),
				),
				get_option( LocalCredentialKeyStore::STATE_OPTION )
			);
			$GLOBALS['_test_current_user_can'] = array();
			$store->initialize_for_write();
			$this->assertTrue( $store->is_available() );
		}
		/** @dataProvider unsafeStates */
		public function test_uncertain_initial_state_blocks_creation_without_side_effects( string $state ): void {
			if ( $state === 'marker' ) {
				$GLOBALS['_test_wp_options'][ LocalCredentialKeyStore::STATE_OPTION ] = array( 'malformed' => true ); }
			$store = $this->store(
				static function () use ( $state ): bool {
					if ( $state === 'error' ) {
						throw new RuntimeException( 'private' );
					} return $state === 'references';
				}
			);
			$this->assertFalse( $store->can_initialize() );
			try {
				$store->initialize_for_write();
				$this->fail( 'Unsafe initialization allowed' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'Secure credential storage is unavailable. Restore the site key or check private storage permissions.', $e->getMessage() ); }
			$this->assertSame( array( '.', '..' ), scandir( $this->root ) );
		}
		public static function unsafeStates(): array {
			return array( array( 'marker' ), array( 'references' ), array( 'error' ) ); }
		/** @dataProvider residueStates */
		public function test_unsafe_creation_residue_is_never_repaired( string $kind ): void {
			if ( $kind === 'null-marker' ) {
				$GLOBALS['_test_wp_options'][ LocalCredentialKeyStore::STATE_OPTION ] = null; } else {
					$dir = $this->root . '/scalyn-mail-relay-private';
				mkdir( $dir, 0700 );
					$name = $kind === 'lock' ? 'credentials-key-blog-1-lock.php' : 'credentials-key-blog-1-' . str_repeat( 'a', 32 ) . '.php';
					file_put_contents( $dir . '/' . $name, '<?php' );
				chmod( $dir . '/' . $name, $kind === 'lock' ? 0644 : 0600 );
				}
				$store = $this->store();
				$this->assertFalse( $store->can_initialize() );
				$this->expectException( RuntimeException::class );
				$store->initialize_for_write();
		}
		public static function residueStates(): array {
			return array( array( 'partial' ), array( 'lock' ) ); }
		public function test_null_marker_is_not_an_absent_marker(): void {
			$GLOBALS['_test_wp_options'][ LocalCredentialKeyStore::STATE_OPTION ] = false;
			$store = $this->store();
			$this->assertFalse( $store->can_initialize() );
			$this->expectException( RuntimeException::class );
			$store->initialize_for_write();
		}
		public function test_mode_without_owner_access_is_not_a_usable_key(): void {
			$store = $this->store();
			$store->initialize_for_write();
			chmod( $store->path(), 0000 );
			$this->assertFalse( $store->is_available() );
			$this->assertFalse( $store->can_initialize() );
		}
		public function test_fingerprint_mismatch_makes_replaced_key_unavailable(): void {
			$store = $this->store();
			$store->initialize_for_write();
			$GLOBALS['_test_wp_options'][ LocalCredentialKeyStore::STATE_OPTION ]['fingerprint'] = str_repeat( '0', 64 );
			$this->assertFalse( $store->is_available() );
			$this->assertFalse( $store->can_initialize() );
			$this->expectException( RuntimeException::class );
			$store->initialize_for_write();
		}
		public function test_direct_php_execution_has_no_output(): void {
			$store = $this->store();
			$store->initialize_for_write();
			$output = array();
			$status = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $store->path() ), $output, $status );
			$this->assertSame( 0, $status );
			$this->assertSame( array(), $output );
		}
		public function test_denied_creation_has_no_filesystem_effects(): void {
			$GLOBALS['_test_current_user_can'] = array();
			$store                             = $this->store();
			try {
				$store->initialize_for_write();
				$this->fail( 'Unauthorized creation' );
			} catch ( RuntimeException $e ) {
				$this->assertNull( $e->getPrevious() ); }
			$this->assertSame( array( '.', '..' ), scandir( $this->root ) );
		}
		public function test_marker_loss_keeps_readonly_consumption_and_authorized_repair(): void {
			$store = $this->store();
			$store->initialize_for_write();
			$key = $store->read();
			unset( $GLOBALS['_test_wp_options'][ LocalCredentialKeyStore::STATE_OPTION ] );
			$GLOBALS['_test_current_user_can'] = array();
			$this->assertTrue( hash_equals( $key, $store->read() ) );
			$this->assertTrue( $store->can_initialize() );
			$this->assertSame( array(), $GLOBALS['_test_wp_options'] );
			try {
				$store->initialize_for_write();
				$this->fail( 'Denied marker persistence' );
			} catch ( RuntimeException $e ) {
				$this->assertNull( $e->getPrevious() ); }
			$GLOBALS['_test_current_user_can'] = array( 'manage_options' => true );
			$store->initialize_for_write();
			$this->assertTrue( hash_equals( $key, $store->read() ) );
		}
		public function test_key_loss_with_marker_never_regenerates(): void {
			$store = $this->store();
			$store->initialize_for_write();
			unlink( $store->path() );
			$this->assertFalse( $store->is_available() );
			$this->assertFalse( $store->can_initialize() );
			$this->expectException( RuntimeException::class );
			$store->initialize_for_write();
		}
		public function test_marker_write_failure_retains_published_key_for_retry(): void {
			$GLOBALS['_test_wp_option_write_failures'][ LocalCredentialKeyStore::STATE_OPTION ] = true;
			$store = $this->store();
			try {
				$store->initialize_for_write();
				$this->fail( 'Unchecked marker failure' );
			} catch ( RuntimeException $e ) {
				$this->assertNull( $e->getPrevious() ); }
			$key   = $store->read();
			$inode = fileinode( $store->path() );
			unset( $GLOBALS['_test_wp_option_write_failures'] );
			$store->initialize_for_write();
			$this->assertTrue( hash_equals( $key, $store->read() ) );
			$this->assertSame( $inode, fileinode( $store->path() ) );
		}
		public function test_reused_store_resolves_blog_on_every_operation(): void {
			$store = $this->store();
			$store->initialize_for_write();
			$key                         = $store->read();
			$GLOBALS['_test_blog_id']    = 2;
			$GLOBALS['_test_wp_options'] = array();
			$this->assertFalse( $store->is_available() );
			$this->assertStringEndsWith( 'credentials-key-blog-2.php', $store->path() );
			$store->initialize_for_write();
			$this->assertFalse( hash_equals( $key, $store->read() ) );
			$GLOBALS['_test_blog_id']    = 1;
			$GLOBALS['_test_wp_options'] = array();
			$this->assertTrue( hash_equals( $key, $store->read() ) );
		}
		/** @dataProvider corruptions */
		public function test_corrupt_or_insecure_objects_are_not_repaired( string $kind ): void {
			$store = $this->store();
			$store->initialize_for_write();
			$path = $store->path();
			if ( $kind === 'file-mode' ) {
				chmod( $path, 0644 ); } elseif ( $kind === 'directory-mode' ) {
				chmod( dirname( $path ), 0755 ); } elseif ( $kind === 'symlink' ) {
					rename( $path, $path . '.target' );
					symlink( $path . '.target', $path ); } elseif ( $kind === 'directory' ) {
					unlink( $path );
					mkdir( $path, 0700 ); } elseif ( $kind === 'trailing' ) {
							file_put_contents( $path, "\n", FILE_APPEND ); } elseif ( $kind === 'oversized' ) {
						file_put_contents( $path, str_repeat( 'x', 1024 ) ); } elseif ( $kind === 'partial' ) {
								file_put_contents( $path, '<?php' ); } elseif ( $kind === 'wrapper' ) {
								$body = file_get_contents( $path );
								file_put_contents( $path, str_replace( 'exit;', 'return;', $body ) ); } elseif ( $kind === 'base64' ) {
									$body = file_get_contents( $path );
									$body = preg_replace( '/([A-Za-z0-9+\/])=\n$/', 'B=' . "\n", $body );
									file_put_contents( $path, $body ); }
								$inode = fileinode( $path );
								$this->assertFalse( $store->is_available() );
								$this->assertFalse( $store->can_initialize() );
								try {
									$store->initialize_for_write();
									$this->fail( 'Insecure key repaired' );
								} catch ( RuntimeException $e ) {
									$this->assertNull( $e->getPrevious() ); }
								$this->assertSame( $inode, fileinode( $path ) );
		}
		public static function corruptions(): array {
			return array_map( static fn( $v )=>array( $v ), array( 'file-mode', 'directory-mode', 'symlink', 'directory', 'trailing', 'oversized', 'partial', 'wrapper', 'base64' ) ); }
		public function test_canonical_root_may_be_an_administrator_symlink(): void {
			$alias = $this->root . '-alias';
			symlink( $this->root, $alias );
			try {
				$store = new LocalCredentialKeyStore( $alias, static fn(): bool=>false, static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null) , static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
				$store->initialize_for_write();
				$this->assertTrue( $store->is_available() );
				$this->assertStringStartsWith( $this->root . '/', $store->path() );
			} finally {
				unlink( $alias ); }
		}
		public function test_missing_root_and_nonpositive_blog_fail_without_writes(): void {
			$store = new LocalCredentialKeyStore( $this->root . '/absent', static fn(): bool=>false, static fn()=>get_option(LocalCredentialKeyStore::STATE_OPTION,null) , static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));
			$this->assertFalse( $store->can_initialize() );
			$GLOBALS['_test_blog_id'] = 0;
			$this->assertFalse( $this->store()->can_initialize() );
			$this->assertSame( array( '.', '..' ), scandir( $this->root ) );
		}
		public function test_lock_contention_without_winner_fails_safely(): void {
			$dir = $this->root . '/scalyn-mail-relay-private';
			mkdir( $dir, 0700 );
			$lock = fopen( $dir . '/credentials-key-blog-1-lock.php', 'x+b' );
			chmod( $dir . '/credentials-key-blog-1-lock.php', 0600 );
			flock( $lock, LOCK_EX );
			try {
				try {
					$this->store()->initialize_for_write();
					$this->fail( 'Contended writer generated a key' );
				} catch ( RuntimeException $e ) {
					$this->assertFalse( $this->store()->is_available() ); }
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock ); }
		}
		public function test_temp_creation_collision_never_deletes_an_unowned_file(): void {
			$GLOBALS['_local_key_test_temp_collision'] = true;
			try {
				try {
					$this->store()->initialize_for_write();
					$this->fail( 'Collision accepted' );
				} catch ( RuntimeException $e ) {
					$this->assertNull( $e->getPrevious() ); }
				$this->assertTrue( file_exists( $GLOBALS['_local_key_test_collision_path'] ) );
			} finally {
				unset( $GLOBALS['_local_key_test_temp_collision'], $GLOBALS['_local_key_test_collision_path'] ); }
		}
		public function test_partial_writer_failure_cleans_only_own_temp_and_never_publishes(): void {
			$GLOBALS['_local_key_test_write_failure'] = true;
			$previous                                 = umask();
			try {
				try {
					$this->store()->initialize_for_write();
					$this->fail( 'Partial key published' );
				} catch ( RuntimeException $e ) {
					$this->assertNull( $e->getPrevious() ); }
				$this->assertFalse( $this->store()->is_available() );
				$this->assertSame( $previous, umask() );
				$this->assertSame( array( '.', '..', 'credentials-key-blog-1-lock.php' ), scandir( $this->root . '/scalyn-mail-relay-private' ) );
				$this->assertSame( array(), $GLOBALS['_test_wp_options'] );
			} finally {
				unset( $GLOBALS['_local_key_test_write_failure'] ); }
		}
		public function test_present_null_marker_is_malformed_not_missing(): void {
			$GLOBALS['_local_key_test_null_marker'] = true;
			try {
				$this->assertFalse( $this->store()->can_initialize() ); } finally {
							unset( $GLOBALS['_local_key_test_null_marker'] ); }
		}
	}
}

namespace Scalyn\MailRelay\Core {
	function get_option( $name, $default = false ) {
		if ( $name === LocalCredentialKeyStore::STATE_OPTION && ! empty( $GLOBALS['_local_key_test_null_marker'] ) ) {
			return null; }
		return \get_option( $name, $default );
	}
	function fopen( $filename, $mode ) {
		if ( ! empty( $GLOBALS['_local_key_test_temp_collision'] ) && $mode === 'x+b' && preg_match( '/-[a-f0-9]{32}\.php$/', $filename ) ) {
			\file_put_contents( $filename, '<?php // synthetic collision' );
			\chmod( $filename, 0600 );
			$GLOBALS['_local_key_test_collision_path'] = $filename;
			return false;
		}
		return \fopen( $filename, $mode );
	}
	function fwrite( $stream, $data ) {
		if ( ! empty( $GLOBALS['_local_key_test_write_failure'] ) ) {
			return 0; }
		return \fwrite( $stream, $data );
	}
}
