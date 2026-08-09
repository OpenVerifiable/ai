<?php
/**
 * Integration tests for the C2pa_Monitor experiment as a whole.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\C2pa_Monitor;
use WordPress\AI\Experiments\C2pa_Monitor\Record;
use WordPress\AI\Experiments\C2pa_Monitor\Sidecar_Writer;

require_once __DIR__ . '/Fixtures.php';

/**
 * Drives capture_for_attachment() against fixture-built attachments and
 * asserts the postmeta record + sidecar file shape.
 *
 * @since x.x.x
 */
class C2pa_MonitorTest extends WP_UnitTestCase {
	/**
	 * Working directory for fixture files (outside uploads).
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	/**
	 * The feature instance under test.
	 *
	 * @var C2pa_Monitor
	 */
	private C2pa_Monitor $feature;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		// Synthetic fixtures are not renderable images; suppress WordPress's image
		// subsize generation so GD never tries to decode them (GD's WebP codec
		// fatals on invalid compressed payloads).
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$this->tmp_dir = sys_get_temp_dir() . '/wpai-c2pa-monitor-' . uniqid( '', true );
		mkdir( $this->tmp_dir, 0700, true );
		$this->feature = new C2pa_Monitor();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( glob( $this->tmp_dir . '/*' ) ?: array() as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->tmp_dir );
		}

		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$dir = trailingslashit( (string) $uploads['basedir'] ) . Sidecar_Writer::SUBDIR;
			if ( is_dir( $dir ) ) {
				foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
					@unlink( $f );
				}
				foreach ( glob( $dir . '/.*' ) ?: array() as $f ) {
					if ( '.' === basename( $f ) || '..' === basename( $f ) ) {
						continue;
					}
					@unlink( $f );
				}
				@rmdir( $dir );
			}
		}
		parent::tearDown();
	}

	/**
	 * Capture for an image carrying a synthetic JUMBF payload records
	 * present=true, the correct hash + length, and writes a sidecar.
	 */
	public function test_capture_records_present_for_jpeg_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		$path    = $this->tmp_dir . '/with.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertSame( C2pa_Monitor::SCHEMA_VERSION, $record['schema_version'] );
		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'jpeg', $record['c2pa']['format'] );
		$this->assertSame( 'APP11/JUMBF', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $payload ), $record['c2pa']['manifest_length'] );
		$this->assertNull( $record['c2pa']['decoded'] );
		$this->assertSame( array(), $record['errors'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );

		$this->assertArrayHasKey( '@context', $record, 'Stored record must embed @context for JSON-LD linkability.' );
		$this->assertIsArray( $record['@context'] );
		$this->assertContains( 'https://schema.org/', $record['@context'] );
		$this->assertContains( C2pa_Monitor::CONTEXT_URL, $record['@context'] );
	}

	/**
	 * Capture for an image with no C2PA segments records present=false and
	 * does not write a sidecar.
	 */
	public function test_capture_records_absent_for_jpeg_without_c2pa(): void {
		$path = $this->tmp_dir . '/without.jpg';
		Fixtures::write_jpeg_without_c2pa( $path );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
		$this->assertSame( 'jpeg', $record['c2pa']['format'] );
		$this->assertArrayNotHasKey( 'sidecar_path_relative', $record['c2pa'] );
	}

	/**
	 * Capture for unsupported MIME types is a no-op (no postmeta written).
	 */
	public function test_capture_skips_unsupported_mime(): void {
		$attachment_id = $this->factory->attachment->create_object(
			'fake.txt',
			0,
			array(
				'post_mime_type' => 'text/plain',
				'post_status'    => 'inherit',
			)
		);

		$this->feature->capture_for_attachment( (int) $attachment_id );
		$this->assertNull( Record::load( (int) $attachment_id ) );
	}

	/**
	 * Capture must not throw or block when handed a non-existent attachment.
	 */
	public function test_capture_is_fail_open(): void {
		$bogus_id = 999999;

		$exception = null;
		try {
			$this->feature->capture_for_attachment( $bogus_id );
		} catch ( \Throwable $e ) {
			$exception = $e;
		}
		$this->assertNull( $exception, 'capture_for_attachment must never throw.' );
	}

	/**
	 * Truncated JPEG produces a record with present=false and does not throw.
	 */
	public function test_capture_handles_truncated_jpeg(): void {
		$path = $this->tmp_dir . '/trunc.jpg';
		Fixtures::write_jpeg_truncated( $path );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from truncated fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
	}

	/**
	 * Captures complete in well under 500 ms on a synthetic image.
	 *
	 * Logged via duration_ms in the postmeta record.
	 */
	public function test_capture_records_duration_ms(): void {
		$payload = Fixtures::synthetic_manifest_payload( 1024 );
		$path    = $this->tmp_dir . '/perf.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment for perf assertion.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertGreaterThanOrEqual( 0, $record['duration_ms'] );
		$this->assertLessThan( 500, $record['duration_ms'], 'capture should complete in under 500ms for a small fixture.' );
	}

	/**
	 * Feature metadata is well-formed.
	 */
	public function test_feature_metadata(): void {
		$this->assertSame( 'c2pa-monitor', C2pa_Monitor::get_id() );
		$this->assertNotEmpty( $this->feature->get_label() );
		$this->assertNotEmpty( $this->feature->get_description() );
		$this->assertSame( 'none', $this->feature->get_capability() );
	}

	/**
	 * End-to-end PNG: capture for a synthetic PNG with caBX must record
	 * present=true with PNG/caBX container and a sidecar that round-trips
	 * the bytes.
	 */
	public function test_capture_records_present_for_png_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 384 );
		$path    = $this->tmp_dir . '/with.png';
		Fixtures::write_png_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from PNG fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'png', $record['c2pa']['format'] );
		$this->assertSame( 'PNG/caBX', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $payload ), $record['c2pa']['manifest_length'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );
	}

	/**
	 * End-to-end WebP: capture for a synthetic WebP with a `C2PA` chunk must
	 * record present=true with WebP/C2PA container and a sidecar that
	 * round-trips the bytes.
	 */
	public function test_capture_records_present_for_webp_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		$path    = $this->tmp_dir . '/with.webp';
		Fixtures::write_webp_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from WebP fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'webp', $record['c2pa']['format'] );
		$this->assertSame( 'WebP/C2PA', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $payload ), $record['c2pa']['manifest_length'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );
	}

	/**
	 * register() wires capture_for_attachment to the add_attachment hook so
	 * a real upload (not a direct method call) produces the postmeta record.
	 *
	 * Catches typos / arity bugs that would silently break the production
	 * flow while leaving the direct-call tests passing.
	 */
	public function test_register_wires_add_attachment_hook(): void {
		$this->feature->register();

		try {
			$payload = Fixtures::synthetic_manifest_payload( 256 );
			$path    = $this->tmp_dir . '/hook.jpg';
			Fixtures::write_jpeg_with_c2pa( $path, $payload );

			$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$this->markTestSkipped( 'Could not create attachment to test hook firing.' );
			}

			$record = Record::load( (int) $attachment_id );
			$this->assertIsArray( $record, 'Expected add_attachment to fire and produce a record.' );
			$this->assertTrue( $record['c2pa']['present'] );
			$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		} finally {
			remove_action( 'add_attachment', array( $this->feature, 'capture_for_attachment' ), 20 );
		}
	}

	/**
	 * If the original image file is missing on disk by the time capture
	 * runs, the record must report present=false with errors[0].stage =
	 * 'resolve_path'. capture_for_attachment must not throw.
	 */
	public function test_capture_logs_resolve_path_error_when_file_missing(): void {
		$payload = Fixtures::synthetic_manifest_payload( 128 );
		$path    = $this->tmp_dir . '/will-be-deleted.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment for missing-file scenario.' );
		}
		$attachment_id = (int) $attachment_id;

		$resolved = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $resolved ) || '' === $resolved ) {
			$resolved = get_attached_file( $attachment_id );
		}
		if ( ! is_string( $resolved ) || ! is_readable( $resolved ) ) {
			$this->markTestSkipped( 'Could not resolve attachment file for deletion.' );
		}

		$this->assertTrue( @unlink( $resolved ) );

		$this->feature->capture_for_attachment( $attachment_id );

		$record = Record::load( $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
		$this->assertNotEmpty( $record['errors'] );
		$this->assertSame( 'resolve_path', $record['errors'][0]['stage'] );
	}

	/**
	 * add_media_column() appends the C2PA column when the feature is enabled,
	 * and leaves columns unchanged when disabled.
	 */
	public function test_add_media_column_when_enabled_and_disabled(): void {
		$base         = array( 'title' => 'File' );
		$global_opt   = 'wpai_features_enabled';
		$feature_opt  = 'wpai_feature_c2pa-monitor_enabled';

		// Enabled: set both options then construct a fresh instance so the
		// enabled_cache is primed with the correct value.
		update_option( $global_opt, true );
		update_option( $feature_opt, true );
		$with = ( new C2pa_Monitor() )->add_media_column( $base );
		$this->assertArrayHasKey( 'wpai_c2pa', $with );
		$this->assertSame( 'Content Credentials', $with['wpai_c2pa'] );

		// Disabled: turn off the per-feature option.
		update_option( $feature_opt, false );
		$without = ( new C2pa_Monitor() )->add_media_column( $base );
		$this->assertArrayNotHasKey( 'wpai_c2pa', $without );

		delete_option( $global_opt );
		delete_option( $feature_opt );
	}

	/**
	 * render_media_column() outputs the correct markup for each record state.
	 */
	public function test_render_media_column_states(): void {
		$global_opt  = 'wpai_features_enabled';
		$feature_opt = 'wpai_feature_c2pa-monitor_enabled';
		update_option( $global_opt, true );
		update_option( $feature_opt, true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->factory->post->create( array( 'post_type' => 'attachment' ) );

		// No record yet: should show dash.
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( '—', $out );

		// present=true.
		$record_present = array(
			'@context'       => array( 'https://schema.org/' ),
			'schema_version' => 1,
			'captured_at'    => '2026-01-01T00:00:00Z',
			'duration_ms'    => 0,
			'source'         => array(),
			'traditional'    => array(),
			'c2pa'           => array( 'present' => true, 'format' => 'jpeg' ),
			'errors'         => array(),
		);
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record_present ) );
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'Credentials', $out );
		$this->assertStringContainsString( '&#10003;', $out );

		// present=false.
		$record_absent         = $record_present;
		$record_absent['c2pa'] = array( 'present' => false, 'format' => 'jpeg' );
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record_absent ) );
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'No credentials', $out );

		delete_option( $global_opt );
		delete_option( $feature_opt );
	}

	/**
	 * render_media_column() is a no-op for unrelated column names.
	 */
	public function test_render_media_column_ignores_other_columns(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->factory->post->create( array( 'post_type' => 'attachment' ) );

		ob_start();
		$feature->render_media_column( 'title', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertSame( '', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * render_media_column() shows the dash when the stored meta is malformed JSON.
	 */
	public function test_render_media_column_handles_malformed_meta(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->factory->post->create( array( 'post_type' => 'attachment' ) );
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, 'not-valid-json' );

		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( '—', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * print_column_styles() outputs a style block when enabled and nothing when disabled.
	 */
	public function test_print_column_styles_enabled_and_disabled(): void {
		// Enabled.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		ob_start();
		( new C2pa_Monitor() )->print_column_styles();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'data-wpai-tooltip', $out );
		$this->assertStringContainsString( '<style>', $out );

		// Disabled: no output.
		update_option( 'wpai_feature_c2pa-monitor_enabled', false );
		ob_start();
		( new C2pa_Monitor() )->print_column_styles();
		$out = ob_get_clean();
		$this->assertSame( '', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}
}
