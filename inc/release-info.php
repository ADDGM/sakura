<?php
/**
 * GitHub release and build information for the Sakura settings page.
 */

if ( ! function_exists( 'sakura_release_repository' ) ) :

function sakura_release_repository() {
	return 'ADDGM/sakura';
}

function sakura_release_api_url( $resource ) {
	return 'https://api.github.com/repos/' . sakura_release_repository() . '/' . ltrim( (string) $resource, '/' );
}

function sakura_release_cache_key() {
	return 'sakura_release_info_v2';
}

function sakura_release_normalize_channel( $channel ) {
	$channel = sanitize_key( (string) $channel );
	$legacy_map = array(
		'tag'     => 'stable',
		'tag2'    => 'testing',
		'develop' => 'testing',
	);
	$channel = isset( $legacy_map[ $channel ] ) ? $legacy_map[ $channel ] : $channel;
	return in_array( $channel, array( 'stable', 'testing' ), true ) ? $channel : 'stable';
}

function sakura_release_version_channel( $version ) {
	$version = strtolower( trim( (string) $version ) );
	if ( preg_match( '/-dev(?:[.+-]|$)/', $version ) ) {
		return 'development';
	}
	if ( false !== strpos( $version, '-' ) ) {
		return 'testing';
	}
	return '' !== $version ? 'stable' : 'unknown';
}

function sakura_release_build_info() {
	$version = defined( 'SAKURA_VERSION' ) ? trim( (string) SAKURA_VERSION ) : '';
	$info = array(
		'version' => $version,
		'channel' => sakura_release_version_channel( $version ),
		'branch'  => '',
		'commit'  => '',
		'tag'     => '',
	);
	$file = trailingslashit( get_template_directory() ) . 'build-info.txt';
	if ( ! is_readable( $file ) ) {
		return $info;
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $lines ) {
		return $info;
	}

	$values = array();
	foreach ( $lines as $line ) {
		$parts = explode( '=', $line, 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		$key = sanitize_key( trim( $parts[0] ) );
		if ( in_array( $key, array( 'branch', 'channel', 'ref', 'ref_name', 'sha', 'source_sha', 'tag' ), true ) ) {
			$values[ $key ] = sanitize_text_field( trim( $parts[1] ) );
		}
	}

	$branch = $values['branch'] ?? ( $values['ref_name'] ?? '' );
	$ref = $values['ref'] ?? '';
	if ( 0 === strpos( $branch, 'refs/heads/' ) ) {
		$branch = substr( $branch, strlen( 'refs/heads/' ) );
	}
	if ( '' === $branch && 0 === strpos( $ref, 'refs/heads/' ) ) {
		$branch = substr( $ref, strlen( 'refs/heads/' ) );
	}
	if ( ! sakura_release_is_valid_ref( $branch ) ) {
		$branch = '';
	}

	$commit = $values['source_sha'] ?? ( $values['sha'] ?? '' );
	if ( ! preg_match( '/^[0-9a-f]{7,40}$/i', $commit ) ) {
		$commit = '';
	}

	$channel = sanitize_key( $values['channel'] ?? '' );
	if ( in_array( $channel, array( 'stable', 'testing', 'development' ), true ) ) {
		$info['channel'] = $channel;
	}
	$info['branch'] = $branch;
	$info['commit'] = strtolower( $commit );
	$info['tag'] = sanitize_text_field( $values['tag'] ?? '' );

	return $info;
}

function sakura_release_is_valid_ref( $ref ) {
	$ref = trim( (string) $ref );
	return '' !== $ref
		&& strlen( $ref ) <= 200
		&& ! preg_match( '/[\x00-\x20~^:?*\\[\\]\\\\]/', $ref )
		&& false === strpos( $ref, '..' )
		&& false === strpos( $ref, '@{' )
		&& '/' !== substr( $ref, 0, 1 )
		&& '/' !== substr( $ref, -1 )
		&& '.lock' !== substr( $ref, -5 );
}

function sakura_release_encode_ref( $ref ) {
	return implode( '/', array_map( 'rawurlencode', explode( '/', (string) $ref ) ) );
}

function sakura_release_ref_url( $ref, $resource = 'tree' ) {
	if ( ! sakura_release_is_valid_ref( $ref ) ) {
		return '';
	}
	$encoded = sakura_release_encode_ref( $ref );
	if ( 'archive' === $resource ) {
		return 'https://github.com/' . sakura_release_repository() . '/archive/refs/heads/' . $encoded . '.zip';
	}
	return 'https://github.com/' . sakura_release_repository() . '/tree/' . $encoded;
}

function sakura_release_api_request( $url ) {
	if ( ! function_exists( 'wp_remote_get' ) ) {
		return new WP_Error( 'sakura_release_unavailable', __( 'WordPress HTTP API is unavailable.', 'sakura' ) );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 4,
			'redirection' => 2,
			'headers'     => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Sakura/' . ( defined( 'SAKURA_VERSION' ) ? SAKURA_VERSION : 'unknown' ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );
	if ( $status_code < 200 || $status_code >= 300 || '' === $body ) {
		return new WP_Error( 'sakura_release_http_error', sprintf( __( 'GitHub returned HTTP %d.', 'sakura' ), $status_code ) );
	}

	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'sakura_release_invalid_json', __( 'GitHub returned invalid data.', 'sakura' ) );
	}

	return $data;
}

function sakura_release_normalize_release( $release ) {
	if ( ! is_array( $release ) ) {
		return array();
	}

	$assets = array();
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
			continue;
		}
		$assets[] = array(
			'name' => sanitize_file_name( $asset['name'] ?? '' ),
			'url'  => esc_url_raw( $asset['browser_download_url'] ),
		);
	}

	return array(
		'tag_name'     => sanitize_text_field( $release['tag_name'] ?? '' ),
		'name'         => sanitize_text_field( $release['name'] ?? '' ),
		'html_url'     => esc_url_raw( $release['html_url'] ?? '' ),
		'zipball_url'  => esc_url_raw( $release['zipball_url'] ?? '' ),
		'published_at' => sanitize_text_field( $release['published_at'] ?? '' ),
		'prerelease'   => ! empty( $release['prerelease'] ),
		'draft'        => ! empty( $release['draft'] ),
		'assets'       => $assets,
	);
}

function sakura_release_latest_prerelease( $releases, $stable_release = array() ) {
	if ( ! is_array( $releases ) ) {
		return array();
	}

	$stable_version = sakura_release_version_from_tag( $stable_release['tag_name'] ?? '' );
	$latest = array();
	$latest_version = '';
	foreach ( $releases as $release ) {
		if ( ! is_array( $release ) || empty( $release['prerelease'] ) || ! empty( $release['draft'] ) ) {
			continue;
		}

		$prerelease = sakura_release_normalize_release( $release );
		$version = sakura_release_version_from_tag( $prerelease['tag_name'] ?? '' );
		if ( '' === $version || ( '' !== $stable_version && ! version_compare( $version, $stable_version, '>' ) ) ) {
			continue;
		}
		if ( '' === $latest_version || version_compare( $version, $latest_version, '>' ) ) {
			$latest = $prerelease;
			$latest_version = $version;
		}
	}
	return $latest;
}

function sakura_release_info( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( sakura_release_cache_key() );
		if ( is_array( $cached ) && isset( $cached['checked_at'] ) ) {
			return $cached;
		}
	}

	$release  = sakura_release_api_request( sakura_release_api_url( 'releases/latest' ) );
	$releases = sakura_release_api_request( sakura_release_api_url( 'releases?per_page=20' ) );
	$errors   = array();

	if ( is_wp_error( $release ) ) {
		$errors['stable'] = $release->get_error_message();
		$release          = array();
	}
	if ( is_wp_error( $releases ) ) {
		$errors['testing'] = $releases->get_error_message();
		$releases          = array();
	}

	$stable_release = sakura_release_normalize_release( $release );
	$data = array(
		'release'     => $stable_release,
		'prerelease'  => sakura_release_latest_prerelease( $releases, $stable_release ),
		'errors'      => $errors,
		'checked_at'  => time(),
	);
	$ttl  = empty( $errors ) ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
	set_transient( sakura_release_cache_key(), $data, $ttl );

	return $data;
}

function sakura_release_format_time( $value ) {
	$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
	if ( ! $timestamp ) {
		return __( 'Unknown time', 'sakura' );
	}
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $timestamp ) : date_i18n( 'Y-m-d H:i', $timestamp );
}

function sakura_release_version_from_tag( $tag ) {
	return preg_replace( '/^[vV]/', '', trim( (string) $tag ) );
}

function sakura_release_stable_status( $release ) {
	$version = sakura_release_version_from_tag( $release['tag_name'] ?? '' );
	$current = defined( 'SAKURA_VERSION' ) ? SAKURA_VERSION : '';
	$current_channel = sakura_release_version_channel( $current );

	if ( '' === $version || '' === $current ) {
		return array( 'class' => 'is-muted', 'label' => __( 'Status unavailable', 'sakura' ) );
	}
	if ( 'stable' !== $current_channel && sakura_release_version_core( $version ) === sakura_release_version_core( $current ) ) {
		return array( 'class' => 'is-info', 'label' => __( 'Stable release available', 'sakura' ) );
	}
	if ( version_compare( $version, $current, '>' ) ) {
		return array( 'class' => 'is-warning', 'label' => __( 'Update available', 'sakura' ) );
	}
	if ( version_compare( $version, $current, '<' ) ) {
		return array( 'class' => 'is-info', 'label' => __( 'Current build is newer', 'sakura' ) );
	}
	return array( 'class' => 'is-success', 'label' => __( 'Up to date', 'sakura' ) );
}

function sakura_release_testing_status( $release ) {
	$version = sakura_release_version_from_tag( $release['tag_name'] ?? '' );
	$current = defined( 'SAKURA_VERSION' ) ? SAKURA_VERSION : '';
	if ( '' === $version ) {
		return array( 'class' => 'is-muted', 'label' => __( 'No prerelease available', 'sakura' ) );
	}
	if ( '' === $current ) {
		return array( 'class' => 'is-info', 'label' => __( 'Prerelease available', 'sakura' ) );
	}
	if ( 0 === version_compare( $version, $current ) ) {
		return array( 'class' => 'is-success', 'label' => __( 'Up to date', 'sakura' ) );
	}
	if ( version_compare( $version, $current, '>' ) ) {
		return array( 'class' => 'is-warning', 'label' => __( 'Testing update available', 'sakura' ) );
	}
	return array( 'class' => 'is-info', 'label' => __( 'Prerelease available', 'sakura' ) );
}

function sakura_release_version_core( $version ) {
	$version = sakura_release_version_from_tag( $version );
	return preg_replace( '/[-+].*$/', '', $version );
}

function sakura_release_download_link( $release ) {
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		if ( ! empty( $asset['url'] ) && preg_match( '/\.zip$/i', (string) ( $asset['name'] ?? '' ) ) ) {
			return array( $asset['url'], __( 'Download theme package', 'sakura' ) );
		}
	}

	if ( ! empty( $release['zipball_url'] ) ) {
		return array( $release['zipball_url'], __( 'Download source ZIP', 'sakura' ) );
	}
	return array( $release['html_url'] ?? '', __( 'Open download page', 'sakura' ) );
}

function sakura_release_build_channel_label( $channel ) {
	$labels = array(
		'stable'      => __( 'Stable build', 'sakura' ),
		'testing'     => __( 'Testing build', 'sakura' ),
		'development' => __( 'Development build', 'sakura' ),
		'unknown'     => __( 'Unknown build', 'sakura' ),
	);
	return $labels[ $channel ] ?? $labels['unknown'];
}

function sakura_release_render_about() {
	$build = sakura_release_build_info();
	$version = '' !== $build['version'] ? 'v' . $build['version'] : __( 'Unknown', 'sakura' );
	$branch = $build['branch'];
	$commit = $build['commit'];
	$branch_url = sakura_release_ref_url( $branch );
	$branch_download = sakura_release_ref_url( $branch, 'archive' );
	$commit_url = '' !== $commit ? 'https://github.com/' . sakura_release_repository() . '/commit/' . rawurlencode( $commit ) : '';
	$develop_badge = 'https://img.shields.io/github/last-commit/' . sakura_release_repository() . '/develop.svg?style=flat-square&label=develop';

	ob_start();
	?>
	<div class="sakura-release-about">
		<div class="sakura-release-about-primary">
			<strong><?php echo esc_html( 'Sakura ' . $version ); ?></strong>
			<span class="sakura-release-build-channel"><?php echo esc_html( sakura_release_build_channel_label( $build['channel'] ) ); ?></span>
		</div>
		<div class="sakura-release-build-meta">
			<span><?php echo esc_html( sprintf( __( 'Current branch: %s', 'sakura' ), '' !== $branch ? $branch : __( 'Unknown', 'sakura' ) ) ); ?></span>
			<?php if ( '' !== $commit ) : ?><span><?php echo esc_html( sprintf( __( 'Current commit: %s', 'sakura' ), substr( $commit, 0, 7 ) ) ); ?></span><?php endif; ?>
		</div>
		<div class="sakura-release-about-actions">
			<a href="https://github.com/ADDGM/sakura#readme" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Theme document', 'sakura' ); ?></a>
			<a href="https://github.com/ADDGM/sakura" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Source code', 'sakura' ); ?></a>
			<?php if ( '' !== $commit_url ) : ?><a href="<?php echo esc_url( $commit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View current commit', 'sakura' ); ?></a><?php endif; ?>
			<?php if ( '' !== $branch_download ) : ?><a href="<?php echo esc_url( $branch_download ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download current branch ZIP', 'sakura' ); ?></a><?php endif; ?>
			<?php if ( '' !== $branch_url ) : ?><a href="<?php echo esc_url( $branch_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Browse current branch', 'sakura' ); ?></a><?php endif; ?>
		</div>
		<a class="sakura-release-develop-badge" href="https://github.com/ADDGM/sakura/commits/develop" target="_blank" rel="noopener noreferrer">
			<img src="<?php echo esc_url( $develop_badge ); ?>" alt="<?php esc_attr_e( 'Latest development commit badge', 'sakura' ); ?>" />
		</a>
		<?php if ( '' === $branch ) : ?><p class="sakura-release-about-note"><?php esc_html_e( 'Branch metadata is unavailable for this installation.', 'sakura' ); ?></p><?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

function sakura_release_refresh_url() {
	$url = add_query_arg(
		array(
			'page'                   => 'options-framework',
			'sakura_release_refresh' => '1',
		),
		admin_url( 'themes.php' )
	);
	return wp_nonce_url( $url, 'sakura_release_refresh' ) . '#section-release_info';
}

function sakura_release_maybe_refresh() {
	global $pagenow;
	if ( ! is_admin() || 'themes.php' !== $pagenow || 'options-framework' !== sanitize_key( $_GET['page'] ?? '' ) || empty( $_GET['sakura_release_refresh'] ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
	if ( ! wp_verify_nonce( $nonce, 'sakura_release_refresh' ) ) {
		wp_die( esc_html__( 'The refresh link has expired. Please try again.', 'sakura' ) );
	}

	delete_transient( sakura_release_cache_key() );
	$redirect = admin_url( 'themes.php?page=options-framework' );
	wp_safe_redirect( $redirect . '#section-release_info' );
	exit;
}
add_action( 'admin_init', 'sakura_release_maybe_refresh' );

function sakura_release_render_field( $option_name, $field_id, $selected ) {
	$selected         = sakura_release_normalize_channel( $selected );
	$info             = sakura_release_info();
	$release          = $info['release'] ?? array();
	$prerelease       = $info['prerelease'] ?? array();
	$errors           = $info['errors'] ?? array();
	$stable_status    = ! empty( $errors['stable'] ) ? array( 'class' => 'is-muted', 'label' => __( 'Unable to check', 'sakura' ) ) : sakura_release_stable_status( $release );
	$testing_status   = ! empty( $errors['testing'] ) ? array( 'class' => 'is-muted', 'label' => __( 'Unable to check', 'sakura' ) ) : sakura_release_testing_status( $prerelease );
	$stable_download  = sakura_release_download_link( $release );
	$testing_download = sakura_release_download_link( $prerelease );
	$checked_at       = ! empty( $info['checked_at'] ) ? sakura_release_format_time( $info['checked_at'] ) : __( 'Unknown time', 'sakura' );
	$name             = $option_name . '[' . $field_id . ']';
	$stable_badge     = 'https://img.shields.io/github/v/release/' . sakura_release_repository() . '?display_name=tag&style=flat-square&label=stable';
	$testing_version  = $prerelease['tag_name'] ?? '';
	$testing_badge    = 'https://img.shields.io/badge/prerelease-' . rawurlencode( '' !== $testing_version ? $testing_version : 'unavailable' ) . '-d97706.svg?style=flat-square';
	$testing_summary  = '' !== $testing_version ? sakura_release_version_from_tag( $testing_version ) : ( ! empty( $errors['testing'] ) ? __( 'Data unavailable', 'sakura' ) : __( 'No prerelease available', 'sakura' ) );

	ob_start();
	?>
	<div class="sakura-release-field" data-channel="<?php echo esc_attr( $selected ); ?>">
		<fieldset class="sakura-release-channel">
			<legend class="screen-reader-text"><?php esc_html_e( 'Version channel', 'sakura' ); ?></legend>
			<?php foreach ( array( 'stable' => __( 'Stable release', 'sakura' ), 'testing' => __( 'Testing release', 'sakura' ) ) as $channel => $label ) : ?>
				<label class="sakura-release-channel-option<?php echo $selected === $channel ? ' is-selected' : ''; ?>">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $channel ); ?>"<?php checked( $selected, $channel ); ?> />
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<div class="sakura-release-overview">
			<span
				class="sakura-release-channel-summary"
				data-stable-label="<?php echo esc_attr( sprintf( __( 'Watching: %s', 'sakura' ), __( 'Stable release', 'sakura' ) ) ); ?>"
				data-testing-label="<?php echo esc_attr( sprintf( __( 'Watching: %s', 'sakura' ), __( 'Testing release', 'sakura' ) ) ); ?>"
			><?php echo esc_html( sprintf( __( 'Watching: %s', 'sakura' ), 'stable' === $selected ? __( 'Stable release', 'sakura' ) : __( 'Testing release', 'sakura' ) ) ); ?></span>
			<a class="button button-secondary" href="<?php echo esc_url( sakura_release_refresh_url() ); ?>"><?php esc_html_e( 'Check now', 'sakura' ); ?></a>
		</div>

		<div class="sakura-release-cards">
			<article class="sakura-release-card sakura-release-card-stable<?php echo 'stable' === $selected ? ' is-selected' : ''; ?>">
				<div class="sakura-release-card-heading">
					<div>
						<h5><?php esc_html_e( 'Stable release', 'sakura' ); ?></h5>
						<span class="sakura-release-status <?php echo esc_attr( $stable_status['class'] ); ?>"><?php echo esc_html( $stable_status['label'] ); ?></span>
					</div>
					<img src="<?php echo esc_url( $stable_badge ); ?>" alt="<?php esc_attr_e( 'Latest stable release badge', 'sakura' ); ?>" />
				</div>
				<p><?php echo esc_html( sakura_release_version_from_tag( $release['tag_name'] ?? '' ) ?: __( 'Data unavailable', 'sakura' ) ); ?><?php if ( ! empty( $release['published_at'] ) ) : ?> <span><?php echo esc_html( sakura_release_format_time( $release['published_at'] ) ); ?></span><?php endif; ?></p>
				<div class="sakura-release-actions">
					<?php if ( ! empty( $release['html_url'] ) ) : ?><a href="<?php echo esc_url( $release['html_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Release notes', 'sakura' ); ?></a><?php endif; ?>
					<?php if ( ! empty( $stable_download[0] ) ) : ?><a href="<?php echo esc_url( $stable_download[0] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $stable_download[1] ); ?></a><?php endif; ?>
				</div>
			</article>

			<article class="sakura-release-card sakura-release-card-testing<?php echo 'testing' === $selected ? ' is-selected' : ''; ?>">
				<div class="sakura-release-card-heading">
					<div>
						<h5><?php esc_html_e( 'Testing release', 'sakura' ); ?></h5>
						<span class="sakura-release-status <?php echo esc_attr( $testing_status['class'] ); ?>"><?php echo esc_html( $testing_status['label'] ); ?></span>
					</div>
					<img src="<?php echo esc_url( $testing_badge ); ?>" alt="<?php esc_attr_e( 'Latest testing release badge', 'sakura' ); ?>" />
				</div>
				<p><?php echo esc_html( $testing_summary ); ?><?php if ( ! empty( $prerelease['published_at'] ) ) : ?> <span><?php echo esc_html( sakura_release_format_time( $prerelease['published_at'] ) ); ?></span><?php endif; ?></p>
				<div class="sakura-release-actions">
					<?php if ( ! empty( $prerelease['html_url'] ) ) : ?><a href="<?php echo esc_url( $prerelease['html_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Release notes', 'sakura' ); ?></a><?php endif; ?>
					<?php if ( ! empty( $testing_download[0] ) ) : ?><a href="<?php echo esc_url( $testing_download[0] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $testing_download[1] ); ?></a><?php endif; ?>
				</div>
			</article>
		</div>

		<p class="sakura-release-help"><?php echo esc_html( sprintf( __( 'Last checked: %s. Release data is cached for six hours. Testing releases come from GitHub prereleases.', 'sakura' ), $checked_at ) ); ?></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

endif;
