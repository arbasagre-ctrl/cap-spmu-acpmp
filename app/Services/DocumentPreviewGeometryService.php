<?php

namespace App\Services;

use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentPreviewGeometryService
{
    private const READ_LIMIT = 1048576; // 1 MiB is enough for normal first-page dictionaries.

    /**
     * Inspect the physical first page without relying on a document type/name.
     *
     * @return array{orientation:'portrait'|'landscape'|'square'|'unknown',ratio:?float}
     */
    public function inspect(?StoredFile $file): array
    {
        if (! $file || ! $this->isPdf($file)) {
            return $this->unknown();
        }

        try {
            $stream = Storage::disk($file->disk)->readStream($file->storage_path);
            if (! is_resource($stream)) {
                return $this->unknown();
            }

            $bytes = stream_get_contents($stream, self::READ_LIMIT);
            fclose($stream);

            if (! is_string($bytes) || $bytes === '') {
                return $this->unknown();
            }

            return $this->inspectPdfBytes($bytes);
        } catch (Throwable) {
            // Preview availability must never depend on geometry detection.
            return $this->unknown();
        }
    }

    /**
     * @return array{orientation:'portrait'|'landscape'|'square'|'unknown',ratio:?float}
     */
    public function inspectPdfBytes(string $bytes): array
    {
        $objects = $this->pdfObjects($bytes);
        $page = $this->firstPage($objects);
        $pageBody = $page['body'] ?? '';

        $box = $this->pageBox($pageBody);
        $rotation = $this->rotation($pageBody);
        $parentId = $this->parentReference($pageBody);
        $visited = [];

        // MediaBox/CropBox and Rotate may legally be inherited from /Pages.
        while ($parentId !== null && isset($objects[$parentId]) && ! isset($visited[$parentId])) {
            $visited[$parentId] = true;
            $parent = $objects[$parentId];
            $box ??= $this->pageBox($parent);
            $rotation ??= $this->rotation($parent);
            $parentId = $this->parentReference($parent);
        }

        // Safe fallback for straightforward PDFs whose page tree is outside
        // the first MiB or whose page dictionary could not be isolated.
        $box ??= $this->pageBox($bytes);

        if ($box === null) {
            return $this->unknown();
        }

        [$left, $bottom, $right, $top] = $box;
        $width = abs($right - $left);
        $height = abs($top - $bottom);

        if ($width <= 0 || $height <= 0) {
            return $this->unknown();
        }

        $rotation = $rotation ?? 0;
        if (in_array($rotation, [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $ratio = $width / $height;
        $orientation = match (true) {
            $ratio > 1.08 => 'landscape',
            $ratio < 0.92 => 'portrait',
            default => 'square',
        };

        return [
            'orientation' => $orientation,
            'ratio' => round($ratio, 4),
        ];
    }

    private function isPdf(StoredFile $file): bool
    {
        $mimeType = strtolower((string) $file->mime_type);
        $name = strtolower((string) $file->original_name);

        return $mimeType === 'application/pdf' || str_ends_with($name, '.pdf');
    }

    /** @return array<int,string> */
    private function pdfObjects(string $bytes): array
    {
        if (preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)\bendobj\b/s', $bytes, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $objects = [];
        foreach ($matches as $match) {
            $objects[(int) $match[1]] = (string) $match[2];
        }

        return $objects;
    }

    /** @param array<int,string> $objects
     *  @return array{id:int,body:string}|null
     */
    private function firstPage(array $objects): ?array
    {
        foreach ($objects as $id => $object) {
            if (preg_match('/\/Type\s*\/Page\b/', $object) === 1) {
                return ['id' => $id, 'body' => $object];
            }
        }

        return null;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function pageBox(string $source): ?array
    {
        foreach (['CropBox', 'MediaBox'] as $boxName) {
            if (preg_match(
                '/\/'.$boxName.'\s*\[\s*([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s*\]/',
                $source,
                $match
            ) === 1) {
                return [
                    (float) $match[1],
                    (float) $match[2],
                    (float) $match[3],
                    (float) $match[4],
                ];
            }
        }

        return null;
    }

    private function rotation(string $source): ?int
    {
        if (preg_match('/\/Rotate\s+(-?\d+)/', $source, $match) !== 1) {
            return null;
        }

        return ((int) $match[1] % 360 + 360) % 360;
    }

    private function parentReference(string $source): ?int
    {
        return preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $source, $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * @return array{orientation:'unknown',ratio:null}
     */
    private function unknown(): array
    {
        return ['orientation' => 'unknown', 'ratio' => null];
    }
}
