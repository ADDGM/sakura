<?php

/**
 * Classes
 */
include_once('classes/Aplayer.php');
include_once('classes/Bilibili.php');
include_once('classes/Cache.php');
include_once('classes/Images.php');
include_once('classes/QQ.php');

use Sakura\API\Images;
use Sakura\API\QQ;
use Sakura\API\Cache;

function sakura_rest_nonce_is_valid(WP_REST_Request $request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce) {
        $nonce = $request->get_param('_wpnonce');
    }
    return is_string($nonce) && wp_verify_nonce(sanitize_text_field($nonce), 'wp_rest');
}

function sakura_meting_nonce_is_valid(WP_REST_Request $request, $type, $id) {
    $meting_nonce = $request->get_param('meting_nonce');
    if ($meting_nonce !== null && $meting_nonce !== '') {
        return is_string($meting_nonce)
            && wp_verify_nonce(sanitize_text_field($meting_nonce), $type . '#:' . $id);
    }

    return sakura_rest_nonce_is_valid($request);
}

function sakura_meting_legacy_request_present(WP_REST_Request $request) {
    $meting_nonce = $request->get_param('meting_nonce');
    $wp_nonce = $request->get_param('_wpnonce');
    $header_nonce = $request->get_header('X-WP-Nonce');

    return ($meting_nonce !== null && $meting_nonce !== '')
        || ($wp_nonce !== null && $wp_nonce !== '')
        || ($header_nonce !== null && $header_nonce !== '');
}

function sakura_meting_token_ttl($type) {
    switch ($type) {
        case 'playlist':
            return 604800;
        case 'lyric':
        case 'pic':
            return 86400;
        case 'url':
            return 3600;
        default:
            return 300;
    }
}

function sakura_meting_public_cache_seconds($type) {
    switch ($type) {
        case 'playlist':
            return 300;
        case 'lyric':
            return 1800;
        case 'pic':
            return 3600;
        case 'url':
            return 15;
        default:
            return 0;
    }
}

function sakura_meting_config_fingerprint() {
    $server = sanitize_key((string) akina_option('aplayer_server', 'netease'));
    $playlist_id = sanitize_text_field((string) akina_option('aplayer_playlistid', ''));
    $cookies = (string) akina_option('aplayer_cookie', '');

    return hash_hmac('sha256', $server . "\0" . $playlist_id . "\0" . $cookies, wp_salt('auth'));
}

function sakura_meting_base64url_encode($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function sakura_meting_base64url_decode($value) {
    $value = strtr((string) $value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

function sakura_meting_create_token($type, $id, $ttl = null, $server = null) {
    $type = sanitize_key((string) $type);
    $id = sanitize_text_field((string) $id);
    $server = $server === null
        ? sanitize_key((string) akina_option('aplayer_server', 'netease'))
        : sanitize_key((string) $server);
    $ttl = $ttl === null ? sakura_meting_token_ttl($type) : (int) $ttl;
    $ttl = max(-86400, min(604800, $ttl));
    $payload = array(
        'v' => 1,
        'server' => $server,
        'type' => $type,
        'id' => $id,
        'exp' => time() + $ttl,
        'cfg' => sakura_meting_config_fingerprint(),
    );
    $encoded_payload = sakura_meting_base64url_encode(wp_json_encode($payload));
    $signature = hash_hmac('sha256', $encoded_payload, wp_salt('auth'), true);

    return $encoded_payload . '.' . sakura_meting_base64url_encode($signature);
}

function sakura_meting_token_is_valid($token, $type, $id, $server) {
    $parts = explode('.', (string) $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return false;
    }
    $payload_json = sakura_meting_base64url_decode($parts[0]);
    $signature = sakura_meting_base64url_decode($parts[1]);
    if (!is_string($payload_json) || !is_string($signature)) {
        return false;
    }
    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return false;
    }
    $expected_signature = hash_hmac('sha256', $parts[0], wp_salt('auth'), true);
    if (!hash_equals($expected_signature, $signature)) {
        return false;
    }

    return isset($payload['v'], $payload['server'], $payload['type'], $payload['id'], $payload['exp'], $payload['cfg'])
        && (int) $payload['v'] === 1
        && (string) $payload['server'] === sanitize_key((string) $server)
        && (string) $payload['type'] === sanitize_key((string) $type)
        && (string) $payload['id'] === sanitize_text_field((string) $id)
        && (int) $payload['exp'] >= time()
        && hash_equals(sakura_meting_config_fingerprint(), (string) $payload['cfg']);
}

function sakura_meting_no_cache_headers() {
    return array(
        'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
        'Vary' => 'Cookie',
    );
}

function sakura_meting_public_cache_headers($type) {
    $seconds = sakura_meting_public_cache_seconds($type);
    return array(
        'Cache-Control' => 'public, max-age=' . $seconds . ', s-maxage=' . $seconds . ', stale-while-revalidate=60',
    );
}

/**
 * Router
 */
add_action('rest_api_init', function () {
    register_rest_route('sakura/v1', '/image/upload', array(
        'methods' => 'POST',
        'callback' => 'upload_image',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/cache_search/json', array(
        'methods' => 'GET',
        'callback' => 'cache_search_json',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/image/cover', array(
        'methods' => 'GET',
        'callback' => 'cover_gallery',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/image/feature', array(
        'methods' => 'GET',
        'callback' => 'feature_gallery',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/database/update', array(
        'methods' => 'GET',
        'callback' => 'update_database',
        'permission_callback' => function (WP_REST_Request $request) {
            return current_user_can('manage_options') && sakura_rest_nonce_is_valid($request);
        },
    ));
    register_rest_route('sakura/v1', '/qqinfo/json', array(
        'methods' => 'GET',
        'callback' => 'get_qq_info',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/qqinfo/avatar', array(
        'methods' => 'GET',
        'callback' => 'get_qq_avatar',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/bangumi/bilibili', array(
        'methods' => 'POST',
        'callback' => 'bgm_bilibili',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sakura/v1', '/meting/aplayer', array(
        'methods' => 'GET',
        'callback' => 'meting_aplayer',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Image uploader response
 */
function upload_image(WP_REST_Request $request) {
    // see: https://developer.wordpress.org/rest-api/requests/

    // handle file params $file === $_FILES
    /**
     * curl \
     *   -F "filecomment=This is an img file" \
     *   -F "cmt_img_file=@screenshot.jpg" \
     *   https://dev.2heng.xin/wp-json/sakura/v1/image/upload
     */
    $files = $request->get_file_params();
    $file = $files['cmt_img_file'] ?? null;
    if (!is_array($file) || empty($file['tmp_name']) || !is_readable($file['tmp_name'])) {
        return new WP_REST_Response(array(
            'status' => 400,
            'success' => false,
            'message' => 'Missing image upload.',
        ), 400);
    }
    if (!sakura_rest_nonce_is_valid($request)) {
        $output = array('status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.',
            'link' => "https://view.moezx.cc/images/2019/11/14/step04.md.png",
            'proxy' => akina_option('cmt_image_proxy') . "https://view.moezx.cc/images/2019/11/14/step04.md.png",
        );
        $result = new WP_REST_Response($output, 403);
        $result->set_headers(array('Content-Type' => 'application/json'));
        return $result;
    }
    $images = new \Sakura\API\Images();
    switch (akina_option("img_upload_api")) {
        case 'imgur':
            $image = file_get_contents($file['tmp_name']);
            $API_Request = $images->Imgur_API($image);
            break;
        case 'smms':
            $API_Request = $images->SMMS_API($files);
            break;
        case 'chevereto':
            $image = file_get_contents($file['tmp_name']);
            $API_Request = $images->Chevereto_API($image);
            break;
    }

    if (!isset($API_Request) || !is_array($API_Request)) {
        $API_Request = array(
            'status' => 503,
            'success' => false,
            'message' => 'Image upload service is not configured.',
        );
    }

    $result = new WP_REST_Response($API_Request, $API_Request['status']);
    $result->set_headers(array('Content-Type' => 'application/json'));
    return $result;
}


/*
 * 随机封面图 rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/image/cover
 */
function cover_gallery() {
    $imgurl = Images::cover_gallery();
    $data = array('cover image');
    $response = new WP_REST_Response($data);
    $response->set_status(302);
    $response->header('Location', $imgurl);
    return $response;
}

/*
 * 随机文章特色图 rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/image/feature
 */
function feature_gallery() {
    $imgurl = Images::feature_gallery();
    $data = array('feature image');
    $response = new WP_REST_Response($data);
    $response->set_status(302);
    $response->header('Location', $imgurl);
    return $response;
}

/*
 * update database rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/database/update
 */
function update_database() {
    if (akina_option('cover_cdn_options') == "type_1") {
        $output = Cache::update_database();
        $result = new WP_REST_Response($output, 200);
        return $result;
    } else {
        return new WP_REST_Response("Invalid access", 200);
    }
}

/*
 * 定制实时搜索 rest api
 * @rest api接口路径：https://sakura.2heng.xin/wp-json/sakura/v1/cache_search/json
 * @可在cache_search_json()函数末尾通过设置 HTTP header 控制 json 缓存时间
 */
function cache_search_json(WP_REST_Request $request) {
    if (!sakura_rest_nonce_is_valid($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $result = new WP_REST_Response($output, 403);
    } else {
        $output = Cache::search_json();
        $result = new WP_REST_Response($output, 200);
    }
    $result->set_headers(
        array(
            'Content-Type' => 'application/json',
            'Cache-Control' => 'max-age=3600', // json 缓存控制
        )
    );
    return $result;
}

/**
 * QQ info
 * https://sakura.2heng.xin/wp-json/sakura/v1/qqinfo/json
 */
function get_qq_info(WP_REST_Request $request) {
    if (!sakura_rest_nonce_is_valid($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
    } elseif ($request->get_param('qq')) {
        $qq = sanitize_text_field((string) $request->get_param('qq'));
        $output = QQ::get_qq_info($qq);
    } else {
        $output = array(
            'status' => 400,
            'success' => false,
            'message' => 'Bad Request'
        );
    }

    $result = new WP_REST_Response($output, $output['status']);
    $result->set_headers(array('Content-Type' => 'application/json'));
    return $result;
}

/**
 * QQ头像链接解密
 * https://sakura.2heng.xin/wp-json/sakura/v1/qqinfo/avatar
 */
function get_qq_avatar(WP_REST_Request $request) {
    $encrypted = sanitize_text_field((string) $request->get_param('qq'));
    if ($encrypted === '') {
        return new WP_REST_Response(array('status' => 400, 'success' => false, 'message' => 'Bad Request'), 400);
    }
    $imgurl = QQ::get_qq_avatar($encrypted);
    if (akina_option('qq_avatar_link') == 'type_2') {
        $imgdata = file_get_contents($imgurl);
        $response = new WP_REST_Response();
        $response->set_headers(array(
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'max-age=86400'
        ));
        echo $imgdata;
    } else {
        $response = new WP_REST_Response();
        $response->set_status(301);
        $response->header('Location', $imgurl);
    }
    return $response;
}

function bgm_bilibili(WP_REST_Request $request) {
    if (!sakura_rest_nonce_is_valid($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $response = new WP_REST_Response($output, 403);
    } else {
        $page = max(1, absint($request->get_param('page')) ?: 2);
        $bgm = new \Sakura\API\Bilibili();
        $html = preg_replace("/\s+|\n+|\r/", ' ', $bgm->get_bgm_items($page));
        $response = new WP_REST_Response($html, 200);
    }
    return $response;
}

function meting_aplayer(WP_REST_Request $request) {
    $type = sanitize_key((string) $request->get_param('type'));
    $id = sanitize_text_field((string) $request->get_param('id'));
    $server = sanitize_key((string) $request->get_param('server'));
    $music_token = sanitize_text_field((string) $request->get_param('music_token'));
    $legacy_request_present = sakura_meting_legacy_request_present($request);
    $public_request = $music_token !== ''
        && !$legacy_request_present
        && sakura_meting_token_is_valid($music_token, $type, $id, $server);
    $legacy_request = $type === 'playlist'
        ? sakura_rest_nonce_is_valid($request)
        : sakura_meting_nonce_is_valid($request, $type, $id);
    if ($type === '' || $id === '' || !in_array($type, array('playlist', 'url', 'pic', 'lyric'), true) || (!$public_request && !$legacy_request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $response = new WP_REST_Response($output, 403);
        $response->set_headers(sakura_meting_no_cache_headers());
    } else {
        try {
            $Meting_API = new \Sakura\API\Aplayer();
            $data = $Meting_API->get_data($type, $id);
        } catch (Throwable $exception) {
            $response = new WP_REST_Response(array(
                'status' => 502,
                'success' => false,
                'message' => 'Music service request failed.',
            ), 502);
            $response->set_headers(sakura_meting_no_cache_headers());
            return $response;
        }
        if ($type === 'playlist') {
            if (!is_array($data)) {
                $response = new WP_REST_Response(array(
                    'status' => 502,
                    'success' => false,
                    'message' => 'Music playlist response is invalid.',
                ), 502);
                $response->set_headers(sakura_meting_no_cache_headers());
                return $response;
            }
            $response = new WP_REST_Response($data, 200);
            $response->set_headers($public_request
                ? sakura_meting_public_cache_headers($type)
                : sakura_meting_no_cache_headers());
        } elseif ($type === 'lyric') {
            $response = new WP_REST_Response(null, 200);
            $response->set_headers(array_merge($public_request
                ? sakura_meting_public_cache_headers($type)
                : sakura_meting_no_cache_headers(), array(
                'content-type' => 'text/plain; charset=UTF-8',
            )));
            echo is_string($data) ? $data : '';
        } else {
            if (!is_string($data) || $data === '') {
                $response = new WP_REST_Response(array(
                    'status' => 502,
                    'success' => false,
                    'message' => 'Music resource URL is invalid.',
                ), 502);
                $response->set_headers(sakura_meting_no_cache_headers());
                return $response;
            }
            $response = new WP_REST_Response();
            $response->set_status(301);
            $response->set_headers($public_request
                ? sakura_meting_public_cache_headers($type)
                : sakura_meting_no_cache_headers());
            $response->header('Location', $data);
        }
    }
    return $response;
}
