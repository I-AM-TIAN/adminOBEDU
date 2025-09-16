<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImageResource\Pages;
use App\Models\Image;
use App\Support\ImageKitUploader;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImageResource extends Resource
{
    protected static ?string $model = Image::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Archivo')
                    ->schema([
                        Forms\Components\Select::make('publication_id')
                            ->relationship('publication', 'title')
                            ->label('Publicación')
                            ->searchable()
                            ->required(),

                        Forms\Components\FileUpload::make('upload')
                            ->label('Subir imagen')
                            ->image()
                            ->imageEditor()
                            ->multiple()      // mantenemos array; subiremos la primera
                            ->maxFiles(1)
                            ->dehydrated(false)
                            ->preserveFilenames()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set) {
                                $raw = is_array($state) && count($state) ? end($state) : $state;
                                if (!$raw instanceof TemporaryUploadedFile) return;

                                try {
                                    $uploader = new ImageKitUploader();
                                    $r = $uploader->uploadTemporary($raw);

                                    // Rellenamos campos persistentes
                                    $set('url',     $r['url']);
                                    $set('file_id', $r['file_id']);
                                    $set('width',   $r['width']);
                                    $set('height',  $r['height']);
                                    $set('size',    $r['size']);
                                    $set('mime',    $r['mime']);
                                    $set('provider', 'imagekit');
                                    if (!$set('alt')) {
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
                    ])->columns(2),

                Forms\Components\Section::make('Datos')
                    ->schema([
                        Forms\Components\TextInput::make('url')->label('URL')->readOnly()->required(),
                        Forms\Components\TextInput::make('file_id')->label('File ID')->readOnly(),
                        Forms\Components\TextInput::make('alt')->label('Alt'),
                        Forms\Components\Textarea::make('caption')->label('Caption'),
                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        Forms\Components\TextInput::make('width')->numeric()->readOnly(),
                        Forms\Components\TextInput::make('height')->numeric()->readOnly(),
                        Forms\Components\TextInput::make('size')->numeric()->readOnly(),
                        Forms\Components\TextInput::make('mime')->readOnly(),
                        Forms\Components\Hidden::make('provider')->default('imagekit'),
                        Forms\Components\KeyValue::make('metadata')->label('Metadata')->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('url')->label('Imagen')->circular(),
                Tables\Columns\TextColumn::make('publication.title')->label('Publicación')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('alt')->limit(30),
                Tables\Columns\TextColumn::make('sort_order')->label('Orden')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(function (Image $record) {
                        if ($record->file_id) {
                            try {
                                (new ImageKitUploader())->delete($record->file_id);
                            } catch (\Throwable $e) {
                                // opcional: log
                            }
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListImages::route('/'),
            'create' => Pages\CreateImage::route('/create'),
            'edit'   => Pages\EditImage::route('/{record}/edit'),
        ];
    }
}
