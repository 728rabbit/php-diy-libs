<?php
// $img = new ImageProcessor('input.jpg');
// $img->resize(200, 200);
// $img->crop(100, 100);
// $img->rotate(90);
// $img->addWatermark('Sample Watermark', 'text', 30, [255, 255, 255], 50, 10, 10);
// $img->save('output.jpg'); // Save to file
// echo $img->getBase64(); // Get base64 image

namespace App\Libs\image;

class PhotoGenerator {
    private $_file = null;
    private $_image = null;
    private $_width = 0;
    private $_height = 0;
    private $_type = null;
    private $_newWidth = 1;
    private $_newHeight = 1;
    private $_quality = 100;

    public function __construct($file = null, $oriWidth = 200, $oriHeight = 200) {
        $this->_file = $file;
        $this->_newWidth = max(1, $oriWidth);
        $this->_newHeight = max(1, $oriHeight);
        
        if (!empty($this->_file) && file_exists($this->_file) && filesize($this->_file) > 0) {
            list($this->_width, $this->_height, $this->_type) = getimagesize($file);
            switch ($this->_type) {
                case IMAGETYPE_JPEG:
                    $this->_image = @imagecreatefromjpeg($file);
                    break;
                case IMAGETYPE_PNG:
                    $this->_image = @imagecreatefrompng($file);
                    break;
                case IMAGETYPE_GIF:
                    $this->_image = @imagecreatefromgif($file);
                    break;
                case IMAGETYPE_WBMP:
                    $this->_image = @imagecreatefromwbmp($file);
                    break;
                case IMAGETYPE_WEBP:
                    $this->_image = @imagecreatefromwebp($file);
                    break;
                default:
                    throw new Exception("Unsupported image type");
            }
        }
        
        if(!empty($this->_image) && $this->_type == IMAGETYPE_PNG) {
            imagealphablending($this->_image, false);
            imagesavealpha($this->_image, true);
        }
    }
   
    public function resize($newWidth = 0, $newHeight = 0) {
        $newWidth = max(1, $newWidth);
        $newHeight = max(1, $newHeight);
        $this->_newWidth = $newWidth;
        $this->_newHeight = $newHeight;
        
        if (!empty($this->_image)) {
            // Keep ratio
            $width = ($this->_width < $newWidth) ? $this->_width : $newWidth;
            $height = ($this->_height < $newHeight) ? $this->_height : $newHeight;
            $ratio = min($width / $this->_width, $height / $this->_height);
            $width = (int)($this->_width * $ratio);
            $height = (int)($this->_height * $ratio);
            
            // Start create
            $newImage = imagecreatetruecolor($width, $height);
            
            // Handle PNG transparency
            if ($this->_type == IMAGETYPE_PNG) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefilledrectangle($newImage, 0, 0, $width, $height, $transparent);
            } else {
                // Other formats fill with white background
                $bgColor = imagecolorallocate($newImage, 255, 255, 255);
                imagefilledrectangle($newImage, 0, 0, $width, $height, $bgColor);
            }
            
            // Copy a rectangular portion of one image to another image
            imagecopyresampled($newImage, $this->_image, 0, 0, 0, 0, $width, $height, $this->_width, $this->_height);
            
            // Assign
            $this->_image = $newImage;
            $this->_width = $width;
            $this->_height = $height;
        }
        
        return $this;
    }

    public function crop($cropWidth = 0, $cropHeight = 0, $x = null, $y = null) {
        // Prevent out-of-range
        $cropWidth = max(1, $cropWidth);
        $cropHeight = max(1, $cropHeight);
        $cropWidth = ($this->_width < $cropWidth) ? $this->_width : $cropWidth;
        $cropHeight = ($this->_height < $cropHeight) ? $this->_height : $cropHeight;       
        $this->_newWidth = $cropWidth;
        $this->_newHeight = $cropHeight;

        if (!empty($this->_image)) {
            if ($x === null) {
                $x = ($this->_width - $cropWidth) / 2;
            }
            if ($y === null) {
                $y = ($this->_height - $cropHeight) / 2;
            }

            // Start create
            $newImage = imagecreatetruecolor($cropWidth, $cropHeight);

            // Handle PNG transparency
            if ($this->_type == IMAGETYPE_PNG) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefilledrectangle($newImage, 0, 0, $cropWidth, $cropHeight, $transparent);
            } else {
                // Other formats fill with white background
                $bgColor = imagecolorallocate($newImage, 255, 255, 255);
                imagefilledrectangle($newImage, 0, 0, $cropWidth, $cropHeight, $bgColor);
            }

            // Calculate the actual copy range of the source image
            $srcX = max(0, $x);
            $srcY = max(0, $y);
            $srcWidth = min($this->_width - $srcX, $cropWidth);
            $srcHeight = min($this->_height - $srcY, $cropHeight);

            // Place the starting point of the source image on the target image
            $dstX = ($srcX < 0) ? abs($srcX) : 0;
            $dstY = ($srcY < 0) ? abs($srcY) : 0;

            // Copy a rectangular portion of one image to another image
            imagecopyresampled(
                $newImage,
                $this->_image,
                $dstX, $dstY,
                $srcX, $srcY,
                $srcWidth, $srcHeight,
                $srcWidth, $srcHeight
            );

            // Assign
            $this->_image = $newImage;
            $this->_width = $cropWidth;
            $this->_height = $cropHeight;
        }

        return $this;
    }

    public function rotate($angle, $bgColor = 0) {
        if (!empty($this->_image)) {
            if ($this->_type == IMAGETYPE_PNG) {
                $rotated = imagerotate($this->_image, $angle, $bgColor);
                imagealphablending($rotated, false);
                imagesavealpha($rotated, true);
                imagedestroy($this->_image);
                $this->_image = $rotated;
            }
            else {
                $this->_image = imagerotate($this->_image, $angle, $bgColor);
            }
        }
        
        return $this;
    }
    
    public function addWatermark($watermark, $type = 'text', $fontSize = 20, $fontColor = [0, 0, 0], $opacity = 50, $x = 0, $y = 0) {
        $watermarkImage = null;

        // Text watermark
        if ($type === 'text') {
            list($r, $g, $b) = $fontColor;
            $textColor = imagecolorallocatealpha($this->_image, $r, $g, $b, 127 - (127 * ($opacity / 100)));

            $fontPath = __DIR__ . '/NotoSansCJKBold.otf';
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $watermark);
            $textWidth = $bbox[2] - $bbox[0];
            $textHeight = $bbox[1] - $bbox[7];

            // By default, the watermark is placed in the lower right corner
            if(empty($x)) {
                $x = ($this->_width - $textWidth - 20);
            }
            if(empty($y)) {
                $y = ($this->_height - $textHeight);
            }

            imagettftext($this->_image, $fontSize, 0, $x, $y, $textColor, $fontPath, $watermark);
        }
        // Image watermark
        elseif ($type === 'image') {
            if (file_exists($watermark)) {
                $watermarkImage = imagecreatefrompng($watermark);
                $wmWidth = imagesx($watermarkImage);
                $wmHeight = imagesy($watermarkImage);

                // By default, the watermark is placed in the lower right corner
                if(empty($x)) {
                    $x = ($this->_width - $wmWidth - 10);
                }
                if(empty($y)) {
                    $y = ($this->_height - $wmHeight - 10);
                }
                
                // Set transparency
                imagealphablending($watermarkImage, true);
                imagesavealpha($watermarkImage, true);
                $transparency = 127 - (127 * ($opacity / 100));

                // Add watermark to the original image
                imagecopymerge($this->_image, $watermarkImage, $x, $y, 0, 0, $wmWidth, $wmHeight, $transparency);
                imagedestroy($watermarkImage);
            } else {
                throw new Exception("Watermark image not found: $watermark");
            }
        } else {
            throw new Exception("Invalid watermark type. Use 'text' or 'image'.");
        }
        
        return $this;
    }
    
    public function setQuality($quality = 100) {
        if(!empty($quality) && (int)$quality > 0 && (int)$quality <= 100) {
            $this->_quality = (int)$quality;
        }
        return $this;
    }
    
    public function save($outputFile) {
        if (empty($this->_image)) {
            $this->createBlankImage($this->_newWidth, $this->_newHeight);
        }
            
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        switch ($this->_type) {
            case IMAGETYPE_JPEG:
                imagejpeg($this->_image, $outputFile, $this->_quality);
                break;
            case IMAGETYPE_PNG:
                imagepng($this->_image, $outputFile, (int)(9 * $this->_quality / 100));
                break;
            case IMAGETYPE_GIF:
                imagegif($this->_image, $outputFile);
                break;
            case IMAGETYPE_WBMP:
                imagewbmp($this->_image, $outputFile);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($this->_image, $outputFile, $this->_quality);
                break;
        }
        
        $this->_quality = 100;
        
        return $outputFile;
    }

    public function getBase64() {
        if (empty($this->_image)) {
            $this->createBlankImage($this->_newWidth, $this->_newHeight);
        }
        
        ob_start();
        switch ($this->_type) {
            case IMAGETYPE_JPEG:
                imagejpeg($this->_image);
                $format = 'jpeg';
                break;
            case IMAGETYPE_PNG:
                imagepng($this->_image);
                $format = 'png';
                break;
            case IMAGETYPE_GIF:
                imagegif($this->_image);
                $format = 'gif';
                break;
            default:
                return null;
        }
        $data = ob_get_contents();
        ob_end_clean();
        return 'data:image/' . $format . ';base64,' . base64_encode($data);
    }
    
    private function createBlankImage($width, $height) {
        // Create a blank image
        $this->_image = imagecreatetruecolor($width, $height);
        // Set the background color to white
        $bgColor = imagecolorallocate($this->_image, 200, 200, 200);
        imagefill($this->_image, 0, 0, $bgColor);

        // Add "No Image" text
        $textColor = imagecolorallocate($this->_image, 255, 255, 255);
        $fontSize = max(10, ceil($width * 0.08));
        $fontPath = __DIR__ . '/NotoSansCJKBold.otf';
        $message = ($width.'x'.$height);
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $message);
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        // Center
        $x = ($width - $textWidth) / 2;
        $y = ($height + $textHeight) / 2;

        imagettftext($this->_image, $fontSize, 0, $x, $y, $textColor, $fontPath, $message);
        $this->_width = $width;
        $this->_height = $height;
        $this->_type = IMAGETYPE_PNG;
    }

    public function __destruct() {
        if ($this->_image) {
            imagedestroy($this->_image);
        }
    }
}