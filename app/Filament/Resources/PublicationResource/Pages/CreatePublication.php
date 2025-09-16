<?php

namespace App\Filament\Resources\PublicationResource\Pages;

use App\Filament\Resources\PublicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublication extends CreateRecord
{
    protected static string $resource = PublicationResource::class;

    /** @var array<int, array<string, mixed>> */
    protected array $imagesDraftBuffer = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Saca las imágenes del payload principal y guárdalas en buffer temporal
        $this->imagesDraftBuffer = $data['imagesDraft'] ?? [];
        unset($data['imagesDraft']);

        return $data;
    }

    protected function afterCreate(): void
    {
        // Crea los registros en la tabla images, vinculados a la publicación recién creada
        $order = 0;
        foreach ($this->imagesDraftBuffer as $img) {
            if (empty($img['url'])) {
                continue; // sólo guardamos si ya se subió a ImageKit
            }
            $this->record->images()->create([
                'file_id'  => $img['file_id']  ?? null,
                'url'      => $img['url'],
                'provider' => $img['provider'] ?? 'imagekit',
                'width'    => $img['width']    ?? null,
                'height'   => $img['height']   ?? null,
                'size'     => $img['size']     ?? null,
                'mime'     => $img['mime']     ?? null,
                'alt'      => $img['alt']      ?? null,
                'caption'  => $img['caption']  ?? null,
                'sort_order' => $img['sort_order'] ?? $order,
                'metadata' => $img['metadata'] ?? null,
            ]);
            $order++;
        }

        // Limpia el buffer
        $this->imagesDraftBuffer = [];
    }
}
