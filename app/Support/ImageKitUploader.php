<?php

namespace App\Support;

use ImageKit\ImageKit;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImageKitUploader
{
    protected ImageKit $ik;

    public function __construct()
    {
        $this->ik = new ImageKit(
            env('IMAGEKIT_PUBLIC_KEY'),
            env('IMAGEKIT_PRIVATE_KEY'),
            env('IMAGEKIT_URL_ENDPOINT'),
        );
    }

    /**
     * Sube un archivo temporal de Livewire a ImageKit y devuelve info relevante.
     *
     * @return array{
     *   file_id:?string, url:?string, width:?int, height:?int, size:?int, mime:?string, name:?string, raw:array
     * }
     */
    public function uploadTemporary(TemporaryUploadedFile $file, array $options = []): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $name = $file->getClientOriginalName() ?? basename($path);

        // El SDK espera "file" (binary/base64/url) y "fileName".
        $payload = array_filter([
            'file' => fopen($path, 'r'),
            'fileName' => $name,
            'useUniqueFileName' => true,
            'folder' => $options['folder'] ?? '/publications',
        ], fn($v) => $v !== null);

        // ↳ Método correcto en el SDK
        $res = $this->ik->uploadFile($payload);

        // El SDK devuelve objeto con ->error y ->result (o array en algunas versiones).
        $error  = is_array($res) ? ($res['error']  ?? null) : ($res->error  ?? null);
        $result = is_array($res) ? ($res['result'] ?? null) : ($res->result ?? null);

        if ($error) {
            $msg = is_array($error) ? ($error['message'] ?? 'ImageKit upload failed') : ($error->message ?? 'ImageKit upload failed');
            throw new \RuntimeException($msg);
        }

        $s = (array) $result;

        return [
            'file_id' => $s['fileId']   ?? null,
            'url'     => $s['url']      ?? null,
            'width'   => $s['width']    ?? null,
            'height'  => $s['height']   ?? null,
            'size'    => $s['size']     ?? null,
            'mime'    => $s['mime']     ?? ($s['fileType'] ?? null),
            'name'    => $s['name']     ?? null,
            'raw'     => $s,
        ];
    }

    public function delete(string $fileId): void
    {
        $res = $this->ik->deleteFile($fileId);
        $error = is_array($res) ? ($res['error'] ?? null) : ($res->error ?? null);
        if ($error) {
            $msg = is_array($error) ? ($error['message'] ?? 'ImageKit delete failed') : ($error->message ?? 'ImageKit delete failed');
            throw new \RuntimeException($msg);
        }
    }
}
