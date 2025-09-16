<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicationResource\Pages;
// use App\Filament\Resources\PublicationResource\RelationManagers\ImagesRelationManager;
use App\Models\Publication;
use App\Support\DocxPublicationParser;
use App\Support\ImageKitUploader;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ---------- 1) IMPORTAR DESDE WORD (.docx) ----------
                Forms\Components\Section::make('Importar desde Word')
                    ->description('Sube un .docx para autocompletar SOLO el Título y el Contenido.')
                    ->schema([
                        Forms\Components\FileUpload::make('docx_upload')
                            ->label('Archivo .docx')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->preserveFilenames()
                            ->dehydrated(false)
                            ->multiple()
                            ->maxFiles(1)
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set) {
                                $raw = (is_array($state) && count($state)) ? end($state) : null;
                                if (!$raw) {
                                    Notification::make()->title('No se recibió el archivo')->danger()->send();
                                    return;
                                }

                                $filePath = null;
                                try {
                                    if ($raw instanceof TemporaryUploadedFile) {
                                        $filePath = $raw->getRealPath() ?: $raw->getPathname();
                                    } elseif (is_string($raw) && str_starts_with($raw, 'livewire-file:')) {
                                        $tuf = TemporaryUploadedFile::unserializeFromLivewireRequest($raw);
                                        $filePath = $tuf?->getRealPath() ?: $tuf?->getPathname();
                                    }
                                } catch (\Throwable $e) {
                                }

                                if (!$filePath || !file_exists($filePath)) {
                                    Notification::make()
                                        ->title('No pude acceder al archivo temporal')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                try {
                                    $data = DocxPublicationParser::parse($filePath);
                                    if (!empty($data['title']))   $set('title', $data['title']);
                                    if (!empty($data['content'])) $set('content', $data['content']);

                                    Notification::make()
                                        ->title('Contenido importado')
                                        ->body('Se completaron Título y Contenido desde el Word.')
                                        ->success()
                                        ->send();
                                } catch (\Throwable $e) {
                                    Notification::make()
                                        ->title('Error al leer el .docx')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            })
                            ->helperText('Al subir el archivo se llenarán automáticamente Título y Contenido.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                // ---------- 2) IMÁGENES (DEBAJO DEL WORD) ----------
                Forms\Components\Section::make('Imágenes de la publicación')
                    ->description('Sube aquí las imágenes; se guardarán en ImageKit y se vincularán al crear.')
                    ->schema([
                        Forms\Components\Repeater::make('imagesDraft')
                            ->label('Imágenes')
                            ->addActionLabel('Añadir imagen')
                            ->default([])
                            ->reorderable(true)
                            ->orderable('sort_order')
                            ->schema([
                                // ÚNICO CAMPO VISIBLE
                                Forms\Components\FileUpload::make('upload')
                                    ->label('Archivo')
                                    ->image()
                                    ->imageEditor(false)   // UI mínima
                                    ->dehydrated(false)    // no se guarda; solo dispara la subida
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        $raw = is_array($state) && count($state) ? end($state) : $state;
                                        if (!$raw instanceof TemporaryUploadedFile) return;

                                        try {
                                            $uploader = new ImageKitUploader();
                                            $r = $uploader->uploadTemporary($raw, [
                                                'folder' => '/publications/draft',
                                            ]);

                                            // Guardamos datos en campos OCULTOS
                                            $set('url',      $r['url']);
                                            $set('file_id',  $r['file_id']);
                                            $set('width',    $r['width']);
                                            $set('height',   $r['height']);
                                            $set('size',     $r['size']);
                                            $set('mime',     $r['mime']);
                                            $set('provider', 'imagekit');
                                            // alt autogenerado, sin mostrarlo
                                            $set('alt', pathinfo($r['name'] ?? 'imagen', PATHINFO_FILENAME));
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

                                // ---- TODO LO DEMÁS, OCULTO (se deshidrata para guardarlo en el payload) ----
                                Forms\Components\Hidden::make('url'),
                                Forms\Components\Hidden::make('file_id'),
                                Forms\Components\Hidden::make('provider')->default('imagekit'),
                                Forms\Components\Hidden::make('width'),
                                Forms\Components\Hidden::make('height'),
                                Forms\Components\Hidden::make('size'),
                                Forms\Components\Hidden::make('mime'),
                                Forms\Components\Hidden::make('alt'),
                                Forms\Components\Hidden::make('caption'),   // por si a futuro lo usas
                                Forms\Components\Hidden::make('sort_order')->default(0),
                                Forms\Components\Hidden::make('metadata'),
                            ])
                            ->columns(1)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                // ---------- 3) DATOS ----------
                Forms\Components\Section::make('Datos de la publicación')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('abstract')
                            ->label('Resumen')
                            ->rows(4)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('content')
                            ->label('Contenido')
                            ->rows(12)
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('publication_date')
                            ->label('Fecha de publicación')
                            ->required(),

                        Forms\Components\Select::make('author_id')
                            ->label('Autor')
                            ->relationship('author', 'first_name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('publication_type_id')
                            ->label('Tipo de publicación')
                            ->relationship('publicationType', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Título')->searchable(),
                Tables\Columns\TextColumn::make('publication_date')->label('Fecha')->date()->sortable(),
                Tables\Columns\TextColumn::make('author.first_name')->label('Autor')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('publicationType.name')->label('Tipo')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // ImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPublications::route('/'),
            'create' => Pages\CreatePublication::route('/create'),
            'edit'   => Pages\EditPublication::route('/{record}/edit'),
        ];
    }
}
