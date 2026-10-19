<?php

declare(strict_types=1);

namespace MuchoCore\News;

use RuntimeException;

/**
 * Geometry Dash 2.209 native News serializer.
 *
 * Reverse-engineered from a real 2.209 news.dat cache:
 *  url-safe-base64(gzip("4,<count>|1:<id>;2:<date64>;3:<title64>;4:<element>~..."))
 * Element types: 0=body text, 1=image, 2=hyperlink.
 *
 * Image: 1,<filename>,<width>,<height>,<video-play-overlay>,<base64url-link>.
 * Both clickable YouTube previews and ordinary images use this native element.
 */
final class NativeNewsFormat
{
    private const FORMAT_VERSION = 4;
    private const MAX_POSTS = 30;

    /**
     * @param array<int,array<string,mixed>> $posts Already filtered to published.
     */
    public static function textPayload(array $posts): string
    {
        $rows = [];
        foreach (array_slice($posts, 0, self::MAX_POSTS) as $post) {
            $id = (int)($post['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Native news post IDs must be positive.');
            }

            $title = (string)($post['title'] ?? '');
            $body = (string)($post['body'] ?? '');
            $media=$post['media'] ?? [];
            if ($title === '' || ($body === '' && $media===[])) {
                throw new RuntimeException('Native news title and content cannot be empty.');
            }
            $date = strtotime((string)($post['published_at'] ?? ''));
            if ($date === false || $date <= 0) {
                throw new RuntimeException('Native news publication date is invalid.');
            }

            $elements=[];
            if ($body!=='') $elements[]='0,'.self::base64url($body);
            foreach ($media as $image) {
                $file=(string)($image['filename'] ?? '');
                $width=$image['width'] ?? 0;$height=$image['height'] ?? 0;
                if (!preg_match('/^mcnews_[a-f0-9]{64}\.jpg$/D',$file)
                    || !is_int($width) || !is_int($height) || $width<1 || $height<1 || $width>1600 || $height>1600
                    || !in_array($image['kind'] ?? '',['image','youtube'],true)) throw new RuntimeException('Invalid news image metadata.');
                $video=$image['kind']==='youtube';
                $target=$video?YouTubeVideo::canonicalUrl((string)($image['target_url'] ?? '')):(string)($post['url'] ?? '');
                if (($video && $target==='') || ($target!=='' && !NewsService::isSafeLink($target))) throw new RuntimeException('Invalid news image link.');
                $elements[]='1,'.$file.','.$width.','.$height.','.($video?'1':'0').','.self::base64url($target);
            }
            $row = '1:' . $id
                . ';2:' . self::base64url(gmdate('F j, Y', $date))
                . ';3:' . self::base64url($title)
                . ';4:' . implode('~',$elements);

            $link = (string)($post['url'] ?? '');
            if ($link !== '') {
                if (!NewsService::isSafeLink($link)) {
                    throw new RuntimeException('Invalid native news link.');
                }
                $row .= '~2,' . self::base64url('Read more') . ',' . self::base64url($link);
            }
            $rows[] = $row;
        }

        return self::FORMAT_VERSION . ',' . count($rows)
            . ($rows !== [] ? '|' . implode('|', $rows) : '');
    }

    /**
     * @param array<int,array<string,mixed>> $posts
     */
    public static function encode(array $posts): string
    {
        $compressed = gzencode(self::textPayload($posts), 9, ZLIB_ENCODING_GZIP);
        if ($compressed === false) {
            throw new RuntimeException('Unable to gzip native GD news.');
        }
        return self::base64url($compressed);
    }

    private static function base64url(string $value): string
    {
        // Native format uses URL-safe characters AND keeps '=' padding.
        return strtr(base64_encode($value), '+/', '-_');
    }
}
