<?php
declare(strict_types=1);

namespace MuchoCore\News;


/** A decoded/re-encoded image; caller-supplied names and metadata never survive. */
final readonly class NewsImage
{
    public const MAX_INPUT_BYTES = 5242880;
    public const MAX_OUTPUT_BYTES = 1572864;

    private function __construct(
        private string $bytes,
        public int $width,
        public int $height,
        public string $filename,
    ) {}

    public function bytes(): string { return $this->bytes; }

    public static function fromUpload(array $file): ?self
    {
        $error=$file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error===UPLOAD_ERR_NO_FILE) return null;
        if ($error!==UPLOAD_ERR_OK) throw new NewsValidationException('Image upload failed. Choose a JPG or PNG up to 5 MiB.');
        $path=$file['tmp_name'] ?? '';
        if (!is_string($path) || !is_uploaded_file($path)) throw new NewsValidationException('Invalid image upload.');
        $size=filesize($path);
        if ($size===false || $size>self::MAX_INPUT_BYTES) throw new NewsValidationException('Each image must be at most 5 MiB.');
        $bytes=file_get_contents($path);
        if ($bytes===false) throw new NewsValidationException('Unable to read uploaded image.');
        return self::fromBytes($bytes);
    }

    public static function fromBytes(string $bytes): self
    {
        if ($bytes==='' || strlen($bytes)>self::MAX_INPUT_BYTES) throw new NewsValidationException('Each image must be at most 5 MiB.');
        if (!function_exists('imagecreatefromstring')) throw new NewsValidationException('News images require PHP GD with JPEG/PNG support.');
        $info=@getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)
            || $info[0]<1 || $info[1]<1 || $info[0]>8192 || $info[1]>8192 || $info[0]*$info[1]>8000000) {
            throw new NewsValidationException('Use a valid JPG or PNG image with at most 8 million pixels.');
        }
        $source=@imagecreatefromstring($bytes);
        if ($source===false) throw new NewsValidationException('This image could not be decoded.');
        $scale=min(1,1600/$info[0],1600/$info[1]);
        $width=max(1,(int)round($info[0]*$scale));$height=max(1,(int)round($info[1]*$scale));
        $target=imagecreatetruecolor($width,$height);
        try {
            // JPEG is supported by every observed native News client. Flatten
            // transparent uploads onto white and strip metadata by re-encoding.
            imagefill($target,0,0,imagecolorallocate($target,255,255,255));
            imagecopyresampled($target,$source,0,0,0,0,$width,$height,$info[0],$info[1]);
            ob_start();
            try { $ok=imagejpeg($target,null,87);$encoded=(string)ob_get_contents(); }
            finally { ob_end_clean(); }
            if (!$ok || strlen($encoded)>self::MAX_OUTPUT_BYTES) throw new NewsValidationException('The image is too complex. Try a smaller image.');
            return new self($encoded,$width,$height,'mcnews_'.hash('sha256',$encoded).'.jpg');
        } finally { imagedestroy($source);imagedestroy($target); }
    }
}
