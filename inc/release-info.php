<?php
/**
 * GitHub release and development branch information for the Sakura settings page.
 */

if ( ! function_exists( 'sakura_release_repository' ) ) :

function sakura_release_repository() {
	return 'ADDGM/sakura';
}

function sakura_release_api_url( $resource ) {
	return 'https://api.github.com/repos/' . sakura_release_repository() . '/' . ltrim( (string) $resource, '/' );
}

function sakura_release_cache_key() {
	return 'sakura_release_info_v1';
}

function sakura_release_normalize_channel( $channel ) {
	$channel = sanitize_key( (string) $channel );
	$legacy_map = array(
		'tag'  => 'stable',
		'tag2' => 'develop',
	);
	$channel = isset( $legacy_map[ $channel ] ) ? $legacy_map[ $channel ] : $channel;
	return in_array( $channel, array( 'stable', 'develop' ), true ) ? $channel : 'stable';
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
		'tag_name'    => sanitize_text_field( $release['tag_name'] ?? '' ),
		'name'        => sanitize_text_field( $release['name'] ?? '' ),
		'html_url'    => esc_url_raw( $release['html_url'] ?? '' ),
		'zipball_url' => esc_url_raw( $release['zipball_url'] ?? '' ),
		'published_at' => sanitize_text_field( $release['published_at'] ?? '' ),
		'assets'      => $assets,
	);
}

function sakura_release_normalize_commit( $commit ) {
	if ( ! is_array( $commit ) ) {
		return array();
	}

	$commit_details = is_array( $commit['commit'] ?? null ) ? $commit['commit'] : array();
	$author         = is_array( $commit_details['author'] ?? null ) ? $commit_details['author'] : array();

	return array(
		'sha'       => sanitize_text_field( $commit['sha'] ?? '' ),
		'message'   => sanitize_textarea_field( $commit_details['message'] ?? '' ),
		'html_url'  => esc_url_raw( $commit['html_url'] ?? '' ),
		'committed' => sanitize_text_field( $author['date'] ?? '' ),
	);
}

function sakura_release_info( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( sakura_release_cache_key() );
		if ( is_array( $cached ) && isset( $cached['checked_at'] ) ) {
			return $cached;
		}
	}

	$release = sakura_release_api_request( sakura_release_api_url( 'releases/latest' ) );
	$commit  = sakura_release_api_request( sakura_release_api_url( 'commits/develop' ) );
	$errors  = array();

	if ( is_wp_error( $release ) ) {
		$errors['stable'] = $release->get_error_message();
		$release          = array();
	}
	if ( is_wp_error( $commit ) ) {
		$errors['develop'] = $commit->get_error_message();
		$commit            = array();
	}

	$data = array(
		'release'    => sakura_release_normalize_release( $release ),
		'develop'    => sakura_release_normalize_commit( $commit ),
		'errors'     => $errors,
		'checked_at' => time(),
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

	if ( '' === $version || '' === $current ) {
		return array( 'class' => 'is-muted', 'label' => __( 'Status unavailable', 'sakura' ) );
	}
	if ( version_compare( $version, $current, '>' ) ) {
		return array( 'class' => 'is-warning', 'label' => __( 'Update available', 'sakura' ) );
	}
	return array( 'class' => 'is-success', 'label' => __( 'Up to date', 'sakura' ) );
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
	$selected = sakura_release_normalize_channel( $selected );
	$info     = sakura_release_info();
	$release  = $info['release'] ?? array();
	$develop  = $info['develop'] ?? array();
	$errors   = $info['errors'] ?? array();
	$status   = ! empty( $errors['stable'] ) ? array( 'class' => 'is-muted', 'label' => __( 'Unable to check', 'sakura' ) ) : sakura_release_stable_status( $release );
	$download = sakura_release_download_link( $release );
	$checked_at = ! empty( $info['checked_at'] ) ? sakura_release_format_time( $info['checked_at'] ) : __( 'Unknown time', 'sakura' );
	$name     = $option_name . '[' . $field_id . ']';
	$stable_badge = 'https://img.shields.io/github/tag/' . sakura_release_repository() . '.svg?style=flat-square&label=latest%20tag';
	$develop_badge = 'https://img.shields.io/github/last-commit/' . sakura_release_repository() . '/develop.svg?style=flat-square&label=develop';
	$develop_url = ! empty( $develop['html_url'] ) ? $develop['html_url'] : 'https://github.com/' . sakura_release_repository() . '/commits/develop';
	$develop_download = 'https://github.com/' . sakura_release_repository() . '/archive/refs/heads/develop.zip';
	$current_version = defined( 'SAKURA_VERSION' ) && '' !== SAKURA_VERSION ? 'v' . SAKURA_VERSION : __( 'Unknown', 'sakura' );
	?>
	<div class="sakura-release-field" data-channel="<?php echo esc_attr( $selected ); ?>">
		<fieldset class="sakura-release-channel">
			<legend class="screen-reader-text"><?php esc_html_e( 'Version channel', 'sakura' ); ?></legend>
			<?php foreach ( array( 'stable' => __( 'Stable release', 'sakura' ), 'develop' => __( 'Development branch', 'sakura' ) ) as $channel => $label ) : ?>
				<label class="sakura-release-channel-option<?php echo $selected === $channel ? ' is-selected' : ''; ?>">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $channel ); ?>"<?php checked( $selected, $channel ); ?> />
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<div class="sakura-release-overview">
			<div>
				<span class="sakura-release-label"><?php esc_html_e( 'Current version', 'sakura' ); ?></span>
				<strong><?php echo esc_html( $current_version ); ?></strong>
			</div>
			<span
				class="sakura-release-channel-summary"
				data-stable-label="<?php echo esc_attr( sprintf( __( 'Watching: %s', 'sakura' ), __( 'Stable release', 'sakura' ) ) ); ?>"
				data-develop-label="<?php echo esc_attr( sprintf( __( 'Watching: %s', 'sakura' ), __( 'Development branch', 'sakura' ) ) ); ?>"
			><?php echo esc_html( sprintf( __( 'Watching: %s', 'sakura' ), 'stable' === $selected ? __( 'Stable release', 'sakura' ) : __( 'Development branch', 'sakura' ) ) ); ?></span>
			<a class="button button-secondary" href="<?php echo esc_url( sakura_release_refresh_url() ); ?>"><?php esc_html_e( 'Check now', 'sakura' ); ?></a>
		</div>

		<div class="sakura-release-cards">
			<article class="sakura-release-card sakura-release-card-stable<?php echo 'stable' === $selected ? ' is-selected' : ''; ?>">
				<div class="sakura-release-card-heading">
					<div>
						<h5><?php esc_html_e( 'Stable release', 'sakura' ); ?></h5>
						<span class="sakura-release-status <?php echo esc_attr( $status['class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
					</div>
					<img src="<?php echo esc_url( $stable_badge ); ?>" alt="<?php esc_attr_e( 'Latest stable release badge', 'sakura' ); ?>" />
				</div>
				<p><?php echo esc_html( sakura_release_version_from_tag( $release['tag_name'] ?? '' ) ?: __( 'Data unavailable', 'sakura' ) ); ?><?php if ( ! empty( $release['published_at'] ) ) : ?> <span><?php echo esc_html( sakura_release_format_time( $release['published_at'] ) ); ?></span><?php endif; ?></p>
				<div class="sakura-release-actions">
					<?php if ( ! empty( $release['html_url'] ) ) : ?><a href="<?php echo esc_url( $release['html_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Release notes', 'sakura' ); ?></a><?php endif; ?>
					<?php if ( ! empty( $download[0] ) ) : ?><a href="<?php echo esc_url( $download[0] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $download[1] ); ?></a><?php endif; ?>
				</div>
			</article>

			<article class="sakura-release-card sakura-release-card-develop<?php echo 'develop' === $selected ? ' is-selected' : ''; ?>">
				<div class="sakura-release-card-heading">
					<div>
						<h5><?php esc_html_e( 'Development branch', 'sakura' ); ?></h5>
						<span class="sakura-release-status <?php echo ! empty( $errors['develop'] ) ? 'is-muted' : 'is-info'; ?>"><?php echo esc_html( ! empty( $errors['develop'] ) ? __( 'Unable to check', 'sakura' ) : __( 'Latest commit loaded', 'sakura' ) ); ?></span>
					</div>
					<img src="<?php echo esc_url( $develop_badge ); ?>" alt="<?php esc_attr_e( 'Latest development commit badge', 'sakura' ); ?>" />
				</div>
				<p><?php echo esc_html( ! empty( $develop['sha'] ) ? substr( $develop['sha'], 0, 7 ) : __( 'Data unavailable', 'sakura' ) ); ?><?php if ( ! empty( $develop['committed'] ) ) : ?> <span><?php echo esc_html( sakura_release_format_time( $develop['committed'] ) ); ?></span><?php endif; ?></p>
				<?php if ( ! empty( $develop['message'] ) ) : ?><p class="sakura-release-commit-message"><?php echo esc_html( strtok( $develop['message'], "\n" ) ); ?></p><?php endif; ?>
				<div class="sakura-release-actions">
					<a href="<?php echo esc_url( $develop_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View commit', 'sakura' ); ?></a>
					<a href="<?php echo esc_url( $develop_download ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download develop ZIP', 'sakura' ); ?></a>
				</div>
			</article>
		</div>

		<p class="sakura-release-help"><?php echo esc_html( sprintf( __( 'Last checked: %s. Release data is cached for six hours. The development download is a source ZIP and is not an automatic WordPress update.', 'sakura' ), $checked_at ) ); ?></p>
	</div>
	<?php
}

endif;
