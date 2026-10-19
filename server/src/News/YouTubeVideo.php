<?php
declare(strict_types=1);

namespace MuchoCore\News;


final class YouTubeVideo
{
    /** Only actual video IDs are accepted, never arbitrary thumbnail URLs. */
    public static function canonicalUrl(string $input): string
    {
        $input=trim($input);
        if ($input==='') return '';
        if (strlen($input)>512 || !NewsService::isSafeLink($input)) throw new NewsValidationException('Use an HTTPS YouTube video link.');
        $parts=parse_url($input);$host=strtolower($parts['host'] ?? '');$path=$parts['path'] ?? '';
        if (isset($parts['port']) && $parts['port']!==443) throw new NewsValidationException('Invalid YouTube video link.');
        $id='';
        if ($host==='youtu.be') $id=trim($path,'/');
        elseif (in_array($host,['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com'],true)) {
            if ($path==='/watch') {
                parse_str($parts['query'] ?? '',$query);$id=$query['v'] ?? '';
            } elseif (preg_match('~^/(?:shorts|embed|live)/([^/]+)/?$~D',$path,$match)) $id=$match[1];
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)) throw new NewsValidationException('Use a YouTube watch, Shorts, live or youtu.be video link.');
        return 'https://www.youtube.com/watch?v='.$id;
    }

    public static function thumbnail(string $url, ?callable $fetch=null): NewsImage
    {
        $canonical=self::canonicalUrl($url);
        if ($canonical==='') throw new NewsValidationException('A YouTube video link is required.');
        $id=substr($canonical,-11);
        // A fixed HTTPS origin, an allowlisted 11-byte ID, no redirects, bounded
        // response and short timeout prevent this feature becoming an URL proxy.
        $fetch ??= static function(string $source): string {
            $context=stream_context_create(['http'=>[
                'timeout'=>4,'follow_location'=>0,'ignore_errors'=>true,
                'header'=>"User-Agent: MuchoCore-News/1.0\r\nAccept: image/jpeg\r\n",
            ]]);
            $handle=@fopen($source,'rb',false,$context);
            if ($handle===false) throw new NewsValidationException('Thumbnail download failed.');
            try {
                $metadata=stream_get_meta_data($handle);
                $headers=$metadata['wrapper_data'] ?? [];
                if (!preg_match('~^HTTP/\S+ 200(?: |$)~',(string)($headers[0] ?? ''))) throw new NewsValidationException('Thumbnail is unavailable.');
                $bytes=stream_get_contents($handle,NewsImage::MAX_INPUT_BYTES+1);
                if ($bytes===false) throw new NewsValidationException('Thumbnail download failed.');
                return $bytes;
            } finally { fclose($handle); }
        };
        foreach (['maxresdefault','hqdefault'] as $quality) {
            try { return NewsImage::fromBytes($fetch('https://i.ytimg.com/vi/'.$id.'/'.$quality.'.jpg')); }
            catch (\Throwable) { /* Try the standard thumbnail before asking for a manual one. */ }
        }
        throw new NewsValidationException('Could not load the YouTube preview. Upload a video preview image and save again.');
    }
}
