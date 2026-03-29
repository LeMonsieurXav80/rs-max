<?php

namespace App\Services\Pinterest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Typography\FontFactory;

class PinterestImageService
{
    private const WIDTH = 1000;
    private const HEIGHT = 1500;

    private ImageManager $manager;

    private string $fontBold;
    private string $fontExtraBold;
    private string $fontRegular;
    private string $fontSerif;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
        $fontsPath = storage_path('app/fonts');
        $this->fontBold = $fontsPath . '/Montserrat-Bold.ttf';
        $this->fontExtraBold = $fontsPath . '/Montserrat-ExtraBold.ttf';
        $this->fontRegular = $fontsPath . '/Montserrat-Regular.ttf';
        $this->fontSerif = $fontsPath . '/PlayfairDisplay-Bold.ttf';
    }

    /**
     * Generate a Pinterest pin image from a source image and title.
     *
     * @param  string       $template  overlay|split|bold_text|numbered
     * @param  string       $title     The generated pin title
     * @param  string|null  $imageUrl  Source image URL (can be null for bold_text)
     * @param  array        $colors    ['background' => '#hex', 'text' => '#hex', 'overlay' => '#hex']
     * @param  string|null  $number    Number prefix for numbered template
     * @return string|null  Path relative to storage/app/public
     */
    public function generate(
        string $template,
        string $title,
        ?string $imageUrl = null,
        array $colors = [],
        ?string $number = null,
    ): ?string {
        $bgColor = $colors['background'] ?? '#1a1a2e';
        $textColor = $colors['text'] ?? '#ffffff';

        try {
            $image = match ($template) {
                'overlay' => $this->generateOverlay($title, $imageUrl, $bgColor, $textColor),
                'split' => $this->generateSplit($title, $imageUrl, $bgColor, $textColor),
                'bold_text' => $this->generateBoldText($title, $bgColor, $textColor),
                'numbered' => $this->generateNumbered($title, $number ?? '1', $bgColor, $textColor),
                default => $this->generateOverlay($title, $imageUrl, $bgColor, $textColor),
            };

            if (! $image) {
                return null;
            }

            $filename = 'pinterest-pins/' . uniqid('pin_') . '.jpg';
            Storage::disk('public')->put($filename, $image->toJpeg(85)->toString());

            return $filename;
        } catch (\Exception $e) {
            Log::error('Pinterest image generation failed', [
                'template' => $template,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Template: Full image with dark gradient overlay + title at bottom
     */
    private function generateOverlay(string $title, ?string $imageUrl, string $bgColor, string $textColor): ?\Intervention\Image\Image
    {
        $canvas = $this->manager->create(self::WIDTH, self::HEIGHT);
        $canvas = $canvas->fill($bgColor);

        // Place source image if available
        if ($imageUrl && ($sourceImage = $this->downloadImage($imageUrl))) {
            $sourceImage = $sourceImage->coverDown(self::WIDTH, self::HEIGHT);
            $canvas = $canvas->place($sourceImage);
        } elseif ($defaultImage = $this->getDefaultImage()) {
            $defaultImage = $defaultImage->coverDown(self::WIDTH, self::HEIGHT);
            $canvas = $canvas->place($defaultImage);
        }

        // Draw gradient overlay (bottom 60%)
        $gradientStart = (int) (self::HEIGHT * 0.4);
        for ($y = $gradientStart; $y < self::HEIGHT; $y++) {
            $opacity = (($y - $gradientStart) / (self::HEIGHT - $gradientStart));
            $alpha = min(0.85, $opacity * 0.85);
            $colorVal = (int) ($alpha * 255);
            $canvas = $canvas->drawRectangle(0, $y, function (RectangleFactory $rectangle) use ($colorVal) {
                $rectangle->size(self::WIDTH, 1);
                $rectangle->background("rgba(0, 0, 0, {$colorVal})");
            });
        }

        // Draw title text at bottom
        $this->drawWrappedText($canvas, $title, 50, self::HEIGHT - 200, self::WIDTH - 100, $textColor, $this->fontExtraBold, 52);

        return $canvas;
    }

    /**
     * Template: Top half image, bottom half colored block with text
     */
    private function generateSplit(string $title, ?string $imageUrl, string $bgColor, string $textColor): ?\Intervention\Image\Image
    {
        $canvas = $this->manager->create(self::WIDTH, self::HEIGHT);
        $canvas = $canvas->fill($bgColor);

        $imageHeight = (int) (self::HEIGHT * 0.55);

        // Place image in top portion
        if ($imageUrl && ($sourceImage = $this->downloadImage($imageUrl))) {
            $sourceImage = $sourceImage->coverDown(self::WIDTH, $imageHeight);
            $canvas = $canvas->place($sourceImage, 'top-left');
        } elseif ($defaultImage = $this->getDefaultImage()) {
            $defaultImage = $defaultImage->coverDown(self::WIDTH, $imageHeight);
            $canvas = $canvas->place($defaultImage, 'top-left');
        }

        // Draw title in bottom portion
        $textY = $imageHeight + 80;
        $this->drawWrappedText($canvas, $title, 60, $textY, self::WIDTH - 120, $textColor, $this->fontExtraBold, 48);

        return $canvas;
    }

    /**
     * Template: Solid color background with large centered text
     */
    private function generateBoldText(string $title, string $bgColor, string $textColor): ?\Intervention\Image\Image
    {
        $canvas = $this->manager->create(self::WIDTH, self::HEIGHT);
        $canvas = $canvas->fill($bgColor);

        // Decorative line at top
        $canvas = $canvas->drawRectangle(80, 120, function (RectangleFactory $rectangle) use ($textColor) {
            $rectangle->size(120, 6);
            $rectangle->background($textColor);
        });

        // Large centered title
        $this->drawWrappedText($canvas, $title, 80, 200, self::WIDTH - 160, $textColor, $this->fontSerif, 62);

        // Decorative line at bottom
        $canvas = $canvas->drawRectangle(self::WIDTH - 200, self::HEIGHT - 120, function (RectangleFactory $rectangle) use ($textColor) {
            $rectangle->size(120, 6);
            $rectangle->background($textColor);
        });

        return $canvas;
    }

    /**
     * Template: Large number + title text
     */
    private function generateNumbered(string $title, string $number, string $bgColor, string $textColor): ?\Intervention\Image\Image
    {
        $canvas = $this->manager->create(self::WIDTH, self::HEIGHT);
        $canvas = $canvas->fill($bgColor);

        // Giant number
        $canvas = $canvas->text($number, 80, 400, function (FontFactory $font) use ($textColor) {
            $font->filename($this->fontExtraBold);
            $font->size(280);
            $font->color($textColor);
            $font->valign('bottom');
        });

        // Thin separator line
        $canvas = $canvas->drawRectangle(80, 440, function (RectangleFactory $rectangle) use ($textColor) {
            $rectangle->size(self::WIDTH - 160, 3);
            $rectangle->background($textColor);
        });

        // Title below number
        $this->drawWrappedText($canvas, $title, 80, 500, self::WIDTH - 160, $textColor, $this->fontBold, 46);

        return $canvas;
    }

    /**
     * Word-wrap text and draw it on the canvas.
     */
    private function drawWrappedText(
        \Intervention\Image\Image &$canvas,
        string $text,
        int $x,
        int $y,
        int $maxWidth,
        string $color,
        string $fontFile,
        int $fontSize,
    ): void {
        $lines = $this->wrapText($text, $fontFile, $fontSize, $maxWidth);
        $lineHeight = (int) ($fontSize * 1.3);

        foreach ($lines as $i => $line) {
            $canvas = $canvas->text($line, $x, $y + ($i * $lineHeight), function (FontFactory $font) use ($fontFile, $fontSize, $color) {
                $font->filename($fontFile);
                $font->size($fontSize);
                $font->color($color);
                $font->valign('top');
            });
        }
    }

    /**
     * Word wrap using GD's imagettfbbox to measure text width.
     */
    private function wrapText(string $text, string $fontFile, int $fontSize, int $maxWidth): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            $bbox = imagettfbbox($fontSize, 0, $fontFile, $testLine);

            if ($bbox && ($bbox[2] - $bbox[0]) > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }

    private function downloadImage(string $url): ?\Intervention\Image\Image
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $this->manager->read($response->body());
        } catch (\Exception $e) {
            Log::warning('Pinterest: failed to download image', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function getDefaultImage(): ?\Intervention\Image\Image
    {
        $defaultsPath = storage_path('app/pinterest-defaults');
        if (! is_dir($defaultsPath)) {
            return null;
        }

        $files = glob($defaultsPath . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
        if (empty($files)) {
            return null;
        }

        $file = $files[array_rand($files)];

        try {
            return $this->manager->read(file_get_contents($file));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Detect if an image at URL is portrait orientation.
     */
    public function isPortrait(string $url): bool
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return false;
            }

            $image = $this->manager->read($response->body());

            return $image->height() > $image->width();
        } catch (\Exception $e) {
            return false;
        }
    }
}
