<?php

declare(strict_types=1);

namespace MuchoCore\Appearance;

use RuntimeException;

/** Decode once, crop, then encode fresh JPEG bytes without source metadata. */
final class ProfileImage
{
    public const MAX_INPUT = 4 * 1024 * 1024;
    public const MAX_OUTPUT = 512 * 1024;
    public const MAX_PIXELS = 12_000_000;

    public static function dimensions(string $kind): array
    {
        return match ($kind) {
            'avatar' => [256,256],
            'banner' => [960,320],
            default => throw new RuntimeException('Choose avatar or banner.'),
        };
    }

    public static function render(string $kind,string $encoded,int $x=500,int $y=500,int $zoom=100,int $rotation=0): string
    {
        [$outW,$outH]=self::dimensions($kind);
        if ($x<0 || $x>1000 || $y<0 || $y>1000 || $zoom<100 || $zoom>300 || !in_array($rotation,[0,90,180,270],true)) {
            throw new RuntimeException('Invalid crop.');
        }
        if ($encoded==='' || strlen($encoded)>4*((self::MAX_INPUT+2)/3)+4) {
            throw new RuntimeException('Choose a JPG or PNG up to 4 MB.');
        }
        $source=base64_decode($encoded,true);
        if ($source===false || strlen($source)>self::MAX_INPUT || base64_encode($source)!==$encoded) {
            throw new RuntimeException('Invalid image data.');
        }
        $size=@getimagesizefromstring($source);
        if (!$size || !in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)) {
            throw new RuntimeException('Only JPG and PNG images are supported.');
        }
        [$w,$h]=$size;
        if ($w<16 || $h<16 || $w>8192 || $h>8192 || $w*$h>self::MAX_PIXELS) {
            throw new RuntimeException('Image must be at least 16 pixels and at most 12 megapixels.');
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new RuntimeException('Enable the PHP GD extension with JPEG and PNG support on the server.');
        }
        $image=@imagecreatefromstring($source);
        if ($image===false) throw new RuntimeException('The image is damaged or unsupported.');
        $target=null;
        try {
            if ($rotation!==0) {
                // The client uses clockwise rotation; GD angles are counterclockwise.
                $turned=imagerotate($image,-$rotation,imagecolorallocate($image,27,41,65));
                if ($turned===false) throw new RuntimeException('Could not rotate the image.');
                imagedestroy($image); $image=$turned;
                $w=imagesx($image); $h=imagesy($image);
            }
            $scale=max($outW/$w,$outH/$h)*($zoom/100);
            $cropW=min($w,(int)round($outW/$scale));
            $cropH=min($h,(int)round($outH/$scale));
            $left=(int)round(($w-$cropW)*($x/1000));
            $top=(int)round(($h-$cropH)*($y/1000));
            $target=imagecreatetruecolor($outW,$outH);
            if ($target===false) throw new RuntimeException('Could not create the profile image.');
            imagefill($target,0,0,imagecolorallocate($target,27,41,65));
            if (!imagecopyresampled($target,$image,0,0,$left,$top,$outW,$outH,$cropW,$cropH)) {
                throw new RuntimeException('Could not crop the image.');
            }
            ob_start();
            try {
                if (!imagejpeg($target,null,88)) throw new RuntimeException('Could not encode the image.');
                $jpeg=(string)ob_get_contents();
            } finally { ob_end_clean(); }
            if ($jpeg==='' || strlen($jpeg)>self::MAX_OUTPUT) throw new RuntimeException('Could not compress the image.');
            return $jpeg;
        } finally {
            imagedestroy($image);
            if ($target instanceof \GdImage) imagedestroy($target);
        }
    }
}
