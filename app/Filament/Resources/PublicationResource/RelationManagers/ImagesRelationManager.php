<?php

namespace App\Filament\Resources\PublicationResource\RelationManagers;

use App\Models\Image;
use App\Support\ImageKitUploader;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager; // v3: clase base
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImagesRelationManager extends RelationManager
{
    /** Nombre de la relación tal como está en App\Models\Publication */
    protected static string $relationship = 'images';

    protected static ?string $recordTitleAttribute = 'alt';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('upload')
                    ->label('Subir imagen')
                    ->image()
                    ->imageEditor()
                    ->multiple()
                    ->maxFiles(1)
                    ->dehydrated(false)    // no se guarda este campo, solo lo usamos para subir a ImageKit
                    ->preserveFilenames()
                    ->reactive()
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        $raw = is_array($state) && count($state) ? end($state) : $state;
                        if (! $raw instanceof TemporaryUploadedFile) {
                            return;
                        }

                        try {
                            $uploader = new ImageKitUploader();
                            $r = $uploader->uploadTemporary($raw, [
                                'folder' => '/publications/' . ($this->ownerRecord?->id ?? 'temp'),
                            ]);

                            // Campos persistentes en la tabla images
                            $set('url',      $r['url']);
                            $set('file_id',  $r['file_id']);
                            $set('width',    $r['width']);
                            $set('height',   $r['height']);
                            $set('size',     $r['size']);
                            $set('mime',     $r['mime']);
                            $set('provider', 'imagekit');
                            if (! $get('alt')) {
                                $set('alt', pathinfo($r['name'] ?? 'imagen', PATHINFO_FILENAME));
                            }
                            $set('metadata', $r['raw']);

                            Notification::make()
                                ->title('Imagen subida a ImageKit')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Error al subir a ImageKit')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Forms\Components\TextInput::make('alt')->label('Alt'),
                Forms\Components\Textarea::make('caption')->label('Caption'),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),

                // Solo lectura / metadatos técnicos
                Forms\Components\TextInput::make('url')->readOnly()->required(),
                Forms\Components\TextInput::make('file_id')->readOnly(),
                Forms\Components\TextInput::make('width')->numeric()->readOnly(),
                Forms\Components\TextInput::make('height')->numeric()->readOnly(),
                Forms\Components\TextInput::make('size')->numeric()->readOnly(),
                Forms\Components\TextInput::make('mime')->readOnly(),
                Forms\Components\Hidden::make('provider')->default('imagekit'),
                Forms\Components\KeyValue::make('metadata')->label('Metadata')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('url')->label('Imagen'),
                Tables\Columns\TextColumn::make('alt')->label('Alt')->limit(30),
                Tables\Columns\TextColumn::make('sort_order')->label('Orden')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(function (Image $record) {
                        // Borra también del proveedor (ImageKit) si tenemos file_id
                        if ($record->file_id) {
                            try {
                                (new ImageKitUploader())->delete($record->file_id);
                            } catch (\Throwable $e) {
                                // log opcional
                            }
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
