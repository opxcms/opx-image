<?php

namespace Modules\Opx\Image;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

class OpxImage
{

    /**
     * Get path to resized image with specified parameters. If it's not existing function creates it.
     *
     * @param  $filename
     * @param int $size
     * @param int $quality
     * @param array $ratio
     *
     * @return  null|string
     */
    public static function get($filename, $size = 200, $quality = 65, array $ratio = ['w' => 1, 'h' => 1]): ?string
    {
        if ($filename === null) {
            return null;
        }

        $diskPath = Storage::disk('public')->getDriver()->getAdapter()->getPathPrefix();

        if (!file_exists($diskPath . $filename)) {
            return null;
        }

        $info = pathinfo($filename);

        $localPath = $info['dirname'] . DIRECTORY_SEPARATOR;

        $modifyDate = filemtime($diskPath . $filename);

        $newName = "cache/{$info['filename']}_{$size}_{$ratio['w']}_{$ratio['h']}_{$quality}_{$modifyDate}.{$info['extension']}";

        if (!file_exists($diskPath . $localPath . $newName)) {
            $dir = pathinfo($diskPath . $localPath . $newName, PATHINFO_DIRNAME);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }

            // Delete old cached files if they are existing.
            self::deleteOld($diskPath . $localPath, $info['filename'], $modifyDate);

            // Resize image and store it with new name
            self::resize($diskPath . $filename, $size, $ratio, $quality, $diskPath . $localPath . $newName);
        }

        return $localPath . $newName;
    }

    /**
     * Remove old resized files.
     *
     * @param string $path
     * @param string $name
     * @param string $modifyDate
     *
     * @return  void
     */
    protected static function deleteOld($path, $name, $modifyDate): void
    {
        $files = Finder::create()->in($path)->name("/^{$name}_/");
        foreach ($files as $file) {
            if (strpos($file->getBasename(), "_{$modifyDate}.") === false) {
                unlink($file);
            }
        }
    }

    /**
     * Resize and convert image.
     *
     * @param string $src
     * @param integer $size
     * @param array $ratio
     * @param integer $quality
     * @param string $dest
     *
     * @return  void
     */
    protected static function resize($src, $size, $ratio, $quality, $dest): void
    {
        $sizes = getimagesize($src);

        [$src_w, $src_h, $mime_type] = $sizes;

        if ($ratio['w'] / $ratio['h'] > 1) {
            $max_w = $size;
            $max_h = round($size / ($ratio['w'] / $ratio['h']));
        } else {
            $max_w = round($size / ($ratio['w'] / $ratio['h']));
            $max_h = $size;
        }

        // Calculate new image sizes
        if ($src_w <= $max_w && $src_h <= $max_h) {
            // No need to resize
            $new_w = $src_w;
            $new_h = $src_h;
        } elseif ($src_w / $max_w > $src_h / $max_h) {
            // Base at width
            $new_w = $max_w;
            $new_h = $max_w * $src_h / $src_w;
        } else {
            // Base at height
            $new_w = $max_h * $src_w / $src_h;
            $new_h = $max_h;
        }

        // Load image
        switch ($mime_type) {
            case IMAGETYPE_BMP:
                $image = imagecreatefrombmp($src);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($src);
                break;
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($src);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($src);
                break;
            default:
                throw new \UnexpectedValueException("Unknown file format [{$sizes['mime']}, {$mime_type}] of '{$src}'.");
        }

        // Create new image with calculated size
        $new_image = imagecreatetruecolor($new_w, $new_h);

        // Resize image
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);

        // And save. Done!
        imagejpeg($new_image, $dest, $quality);

        // Now free memory.
        imagedestroy($image);
        imagedestroy($new_image);
    }
}
