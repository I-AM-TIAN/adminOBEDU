<?php

namespace App\Filament\Resources\PublicationTypeResource\Pages;

use App\Filament\Resources\PublicationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPublicationType extends EditRecord
{
    protected static string $resource = PublicationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
