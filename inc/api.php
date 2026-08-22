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
    if ($type === '' || $id === '' || !sakura_rest_nonce_is_valid($request)) {
        $output = array(
            'status' => 403,
            'success' => false,
            'message' => 'Unauthorized client.'
        );
        $response = new WP_REST_Response($output, 403);
    } else {
        $Meting_API = new \Sakura\API\Aplayer();
        $data = $Meting_API->get_data($type, $id);
        if ($type === 'playlist') {
            $response = new WP_REST_Response($data, 200);
            $response->set_headers(array('cache-control' => 'max-age=3600'));
        } elseif ($type === 'lyric') {
            $response = new WP_REST_Response();
            $response->set_headers(array('cache-control' => 'max-age=3600'));
            echo $data;
        } else {
            $response = new WP_REST_Response();
            $response->set_status(301);
            $response->header('Location', $data);
        }
    }
    return $response;
}
