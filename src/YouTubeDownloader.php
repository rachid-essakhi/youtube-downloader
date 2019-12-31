<?php

namespace YouTube;

// YouTube is capitalized twice because that's how youtube itself does it:
// https://developers.google.com/youtube/v3/code_samples/php
class YouTubeDownloader
{
    private $client;

    function __construct()
    {
        $this->client = new Browser();
    }

    // accepts either raw HTML or url
    // <script src="//s.ytimg.com/yts/jsbin/player-fr_FR-vflHVjlC5/base.js" name="player/base"></script>
    public function getPlayerUrl($video_html)
    {
        $player_url = null;

        // check what player version that video is using
        if (preg_match('@<script\s*src="([^"]+player[^"]+js)@', $video_html, $matches)) {
            $player_url = $matches[1];

            // relative protocol?
            if (strpos($player_url, '//') === 0) {
                $player_url = 'http://' . substr($player_url, 2);
            } elseif (strpos($player_url, '/') === 0) {
                // relative path?
                $player_url = 'http://www.youtube.com' . $player_url;
            }
        }

        return $player_url;
    }

    public function getPlayerCode($player_url)
    {
        $contents = $this->client->getCached($player_url);
        return $contents;
    }

    // extract youtube video_id from any piece of text
    public function extractVideoId($str)
    {
        if (preg_match('/[a-z0-9_-]{11}/i', $str, $matches)) {
            return $matches[0];
        }

        return false;
    }

    // selector by format: mp4 360,
    private function selectFirst($links, $selector)
    {
        $result = array();
        $formats = preg_split('/\s*,\s*/', $selector);

        // has to be in this order
        foreach ($formats as $f) {

            foreach ($links as $l) {

                if (stripos($l['format'], $f) !== false || $f == 'any') {
                    $result[] = $l;
                }
            }
        }

        return $result;
    }

    public function getVideoInfo($url)
    {
        // $this->client->get("https://www.youtube.com/get_video_info?el=embedded&eurl=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3D" . urlencode($video_id) . "&video_id={$video_id}");
    }

    public function getPageHtml($url)
    {
        $video_id = $this->extractVideoId($url);
        return $this->client->get("https://www.youtube.com/watch?v={$video_id}");
    }

    public function getPlayerResponse($page_html)
    {
        if (preg_match('/player_response":"(.*?)","/', $page_html, $matches)) {
            $match = stripslashes($matches[1]);

            $ret = json_decode($match, true);
            return $ret;
        }

        return null;
    }

    // redirector.googlevideo.com
    //$url = preg_replace('@(\/\/)[^\.]+(\.googlevideo\.com)@', '$1redirector$2', $url);
    public function parsePlayerResponse($player_response, $js_code)
    {
        $parser = new Parser();

        try {
            $formats = $player_response['streamingData']['formats'];
            $adaptiveFormats = $player_response['streamingData']['adaptiveFormats'];

            $formats_combined = array_merge($formats, $adaptiveFormats);

            // final response
            $return = array();

            foreach ($formats_combined as $item) {
                $cipher = $item['cipher'];
                $itag = $item['itag'];

                parse_str($cipher, $result);

                $url = $result['url'];
                $sp = $result['sp']; // typically 'sig'
                $signature = $result['s'];

                $decoded_signature = (new SignatureDecoder())->decode($signature, $js_code);

                // redirector.googlevideo.com
                //$url = preg_replace('@(\/\/)[^\.]+(\.googlevideo\.com)@', '$1redirector$2', $url);
                $return[] = array(
                    'url' => $url . '&' . $sp . '=' . $decoded_signature,
                    'itag' => $itag,
                    'format' => $parser->parseItagInfo($itag)
                );
            }

            return $return;

        } catch (\Exception $exception) {
            // do nothing
        }

        return null;
    }

    public function getDownloadLinks($video_id, $selector = false)
    {
        $page_html = $this->getPageHtml($video_id);

        // get JSON encoded parameters that appear on video pages
        $json = $this->getPlayerResponse($page_html);

        // get player.js location that holds signature function
        $url = $this->getPlayerUrl($page_html);
        $js = $this->getPlayerCode($url);

        $result = $this->parsePlayerResponse($json, $js);

        // do we want all links or just select few?
        if ($selector) {
            return $this->selectFirst($result, $selector);
        }

        return $result;
    }
}
