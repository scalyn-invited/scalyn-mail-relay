<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Container;

final class ContainerTest extends TestCase {

	public function test_set_and_get_returns_resolved_service(): void {
		$container = new Container();
		$obj       = new \stdClass();
		$container->set( 'my.service', static fn() => $obj );

		$this->assertSame( $obj, $container->get( 'my.service' ) );
	}

	public function test_factory_is_called_once_and_result_is_cached(): void {
		$container = new Container();
		$calls     = 0;

		$container->set( 'counter', static function () use ( &$calls ): object {
			++$calls;
			return new \stdClass();
		} );

		$container->get( 'counter' );
		$container->get( 'counter' );

		$this->assertSame( 1, $calls );
	}

	public function test_same_instance_returned_on_subsequent_calls(): void {
		$container = new Container();
		$container->set( 'svc', static fn() => new \stdClass() );

		$this->assertSame( $container->get( 'svc' ), $container->get( 'svc' ) );
	}

	public function test_has_returns_true_for_registered_service(): void {
		$container = new Container();
		$container->set( 'svc', static fn() => new \stdClass() );

		$this->assertTrue( $container->has( 'svc' ) );
	}

	public function test_has_returns_false_for_unregistered_service(): void {
		$container = new Container();

		$this->assertFalse( $container->has( 'missing' ) );
	}

	public function test_get_throws_runtime_exception_for_unregistered_service(): void {
		$container = new Container();

		$this->expectException( \RuntimeException::class );
		$container->get( 'nonexistent.service' );
	}

	public function test_exception_message_contains_exact_service_id_verbatim(): void {
		$container  = new Container();
		$service_id = 'My\Namespaced\Service';

		try {
			$container->get( $service_id );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			// Service name must appear verbatim — not HTML-encoded or altered.
			$this->assertStringContainsString( $service_id, $e->getMessage() );
			$this->assertStringNotContainsString( '&lt;', $e->getMessage() );
			$this->assertStringNotContainsString( '&gt;', $e->getMessage() );
		}
	}

	public function test_object_set_directly_without_factory_is_returned(): void {
		$container = new Container();
		$obj       = new \stdClass();
		$container->set( 'direct', $obj );

		$this->assertSame( $obj, $container->get( 'direct' ) );
	}
}
