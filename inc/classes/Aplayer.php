<?php

namespace Sakura\API;

class Aplayer
{
    public $server;
    public $playlist_id;
    private $cookies;
    public $api_url;

    public function __construct() {
        $this->server = akina_option('aplayer_server');
        $this->playlist_id = akina_option('aplayer_playlistid');
        $this->cookies = akina_option('aplayer_cookie');
        $this->api_url = rest_url('sakura/v1/meting/aplayer');
        require('Meting.php');
    }

    public function get_data($type, $id) {
        $server = $this->server;
        $cookies = $this->cookies;
        $playlist_id = $this->playlist_id;
        $cache_key = $this->cache_key($type, $id);
        if (function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        $api = new \Sakura\API\Meting($server);
        if (!empty($cookies) && $server === "netease") $api->cookie($cookies);
        switch ($type) {
            case 'song':
                $data = $api->format(true)->song($id);
                $data = $this->json_url($data);
                $data = $this->song_url($data);
                break;
            // case 'album':
            //     $data = $api->format(true)->album($id);
            //     $data=json_decode($data, true)["url"];
            //     break;
            case 'playlist':
                $data = $api->format(true)->playlist($playlist_id);
                $data = $this->format_playlist($data);
                break;
            case 'lyric':
                $data = $api->format(true)->lyric($id);
                $data = $this->format_lyric($data);
                break;
            case 'pic':
                $data = $api->format(true)->pic($id);
                $data = $this->json_url($data);
                break;
            // case 'search':
            //     $data = $api->format(true)->search($id);
            //     $data=json_decode($data, true);
            //     break;
            default:
                $data = $api->format(true)->url($id);
                $data = $this->json_url($data);
                $data = $this->song_url($data);
                break;
        }
        $this->cache_data($cache_key, $type, $data);
        return $data;
    }

    private function cache_key($type, $id) {
        return 'sakura_music_' . hash('sha256', implode("\0", array(
            (string) $this->server,
            (string) $this->playlist_id,
            (string) $this->cookies,
            (string) $type,
            (string) $id,
        )));
    }

    private function cache_data($cache_key, $type, $data) {
        if (!function_exists('set_transient') || !function_exists('sakura_meting_public_cache_seconds')) {
            return;
        }
        $cacheable = $type === 'playlist'
            ? is_array($data)
            : is_string($data) && $data !== '';
        if (!$cacheable) {
            return;
        }
        $ttl = sakura_meting_public_cache_seconds($type);
        if ($ttl > 0) {
            set_transient($cache_key, $data, $ttl);
        }
    }

    private function format_playlist($data) {
        $server = $this->server;
        $api_url = $this->api_url;
        $data = json_decode($data);
        if (!is_array($data)) {
            return array();
        }
        $playlist = array();
        foreach ((array)$data as $value) {
            if (!is_object($value) || empty($value->url_id)) {
                continue;
            }
            $name = isset($value->name) ? (string) $value->name : '';
            $artists = implode(" / ", (array) ($value->artist ?? array()));
            $pic_id = isset($value->pic_id) ? (string) $value->pic_id : '';
            $lyric_id = isset($value->lyric_id) ? (string) $value->lyric_id : '';
            $url_id = (string) $value->url_id;
            $mp3_url = $this->resource_url($api_url, $server, 'url', $url_id);
            $cover = $this->resource_url($api_url, $server, 'pic', $pic_id);
            $lyric = $this->resource_url($api_url, $server, 'lyric', $lyric_id);
            $playlist[] = array(
                "name" => $name,
                "artist" => $artists,
                "url" => $mp3_url,
                "cover" => $cover,
                "lrc" => $lyric
            );
        }
        return $playlist;
    }

    private function resource_url($api_url, $server, $type, $id) {
        $query = array(
            'server' => $server,
            'type' => $type,
            'id' => $id,
        );
        if (function_exists('sakura_meting_create_token')) {
            $query['music_token'] = sakura_meting_create_token($type, $id, null, $server);
        } else {
            $query['meting_nonce'] = wp_create_nonce($type . '#:' . $id);
        }

        return $api_url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function json_url($data) {
        $decoded = json_decode($data, true);
        return is_array($decoded) && isset($decoded['url']) && is_string($decoded['url'])
            ? $decoded['url']
            : '';
    }

    private function song_url($url){
        $server = $this->server;
        if ($server == 'xiami') {
            $url = str_replace('http://', 'https://', $url);
        }elseif ($server == 'baidu') {
            $url = str_replace('http://zhangmenshiting.qianqian.com', 'https://gss3.baidu.com/y0s1hSulBw92lNKgpU_Z2jR7b2w6buu', $url);
        }
        return $url;
    }

    private function format_lyric($data) {
        $server = $this->server;
        $data = json_decode($data, true);
        $data = is_array($data) ? $data : array();
        $data = $this->lrctran($data['lyric'] ?? '', $data['tlyric'] ?? '');
        if (empty($data)) {
            $data = "[00:00.000]此歌曲暂无歌词，请您欣赏";
        }
        if ($server === 'tencent') {
            $data = html_entity_decode($data, ENT_QUOTES | ENT_HTML5);
        }
        return $data;
    }

    private function lrctran($lyric, $tlyric) {
        $lyric = $this->lrctrim($lyric);
        $tlyric = $this->lrctrim($tlyric);
        $len1 = count($lyric);
        $len2 = count($tlyric);
        $result = "";
        for ($i = 0, $j = 0; $i < $len1 && $j < $len2; $i++) {
            while ($lyric[$i][0] > $tlyric[$j][0] && $j + 1 < $len2) {
                $j++;
            }
            if ($lyric[$i][0] == $tlyric[$j][0]) {
                $tlyric[$j][2] = str_replace('/', '', $tlyric[$j][2]);
                if (!empty($tlyric[$j][2])) {
                    $lyric[$i][2] .= " ({$tlyric[$j][2]})";
                }
                $j++;
            }
        }
        for ($i = 0; $i < $len1; $i++) {
            $t = $lyric[$i][0];
            $result .= sprintf("[%02d:%02d.%03d]%s\n", $t / 60000, $t % 60000 / 1000, $t % 1000, $lyric[$i][2]);
        }
        return $result;
    }

    private function lrctrim($lyrics) {
        $lyrics = explode("\n", $lyrics);
        $data = array();
        foreach ($lyrics as $key => $lyric) {
            preg_match('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', $lyric, $lrcTimes);
            $lrcText = preg_replace('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', '', $lyric);
            if (empty($lrcTimes)) {
                continue;
            }
            $lrcTimes = intval($lrcTimes[1]) * 60000 + intval(floatval($lrcTimes[2]) * 1000);
            $lrcText = preg_replace('/\s\s+/', ' ', $lrcText);
            $lrcText = trim($lrcText);
            $data[] = array($lrcTimes, $key, $lrcText);
        }
        sort($data);
        return $data;
    }
}
