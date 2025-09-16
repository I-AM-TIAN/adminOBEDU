<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicationResource\Pages;
use App\Models\Publication;
use App\Support\DocxPublicationParser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile; // Livewire v3

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ---------- IMPORTAR DESDE WORD (.docx) ----------
                Forms\Components\Section::make('Importar desde Word')
                    ->description('Sube un .docx para autocompletar SOLO el Título y el Contenido.')
                    ->schema([
                        Forms\Components\FileUpload::make('docx_upload')
                            ->label('Archivo .docx')
                            // NO definimos ->disk() ni ->directory() para que Livewire mantenga archivo temporal
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->preserveFilenames()
                            ->dehydrated(false)   // no guardar este campo en la BD
                            ->multiple()          // siempre array => evitamos el foreach error interno de Filament
                            ->maxFiles(1)
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set) {
                                // $state será array|null por ->multiple()
                                /** @var TemporaryUploadedFile|mixed|null $raw */
                                $raw = (is_array($state) && count($state)) ? end($state) : null;
                                if (!$raw) {
                                    Notification::make()
                                        ->title('No se recibió el archivo')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                // Resolver ruta absoluta del archivo temporal (Livewire v3)
                                $filePath = null;
                                try {
                                    if ($raw instanceof TemporaryUploadedFile) {
                                        $filePath = $raw->getRealPath() ?: $raw->getPathname();
                                    } elseif (is_string($raw) && str_starts_with($raw, 'livewire-file:')) {
                                        // Si por alguna razón llega token, lo deserializamos
                                        $tuf = TemporaryUploadedFile::unserializeFromLivewireRequest($raw);
                                        $filePath = $tuf?->getRealPath() ?: $tuf?->getPathname();
                                    }
                                } catch (\Throwable $e) {
                                    // seguimos con filePath = null
                                }

                                if (!$filePath || !file_exists($filePath)) {
                                    Notification::make()
                                        ->title('No pude acceder al archivo temporal')
                                        ->body(is_string($raw) ? $raw : 'Archivo no reconocido')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                // --- Parsear el DOCX ---
                                try {
                                    $data = DocxPublicationParser::parse($filePath);

                                    // SOLO llenamos title y content
                                    if (!empty($data['title'])) {
                                        $set('title', $data['title']);
                                    }
                                    if (!empty($data['content'])) {
                                        // Si tu 'content' es Textarea, puedes dejar texto plano.
                                        // Si quisieras HTML: $set('content', DocxPublicationParser::textToHtml($data['content']));
                                        $set('content', $data['content']);
                                    }

                                    Notification::make()
                                        ->title('Contenido importado')
                                        ->body('Se completaron Título y Contenido desde el Word.')
                                        ->success()
                                        ->send();

                                    // ⚠️ Importante:
                                    // No reasignamos 'docx_upload' a una cadena/ruta aquí.
                                    // Si quieres persistir el .docx, hazlo en onSave (o en un Action),
                                    // usando $raw->storeAs(...) y guardando la ruta en otro campo propio.

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

                // ---------- CAMPOS REALES ----------
                Forms\Components\Section::make('Datos de la publicación')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('abstract')
                            ->label('Resumen')
                            ->rows(4)
                            ->columnSpanFull(), // déjalo opcional; si lo quieres obligatorio agrega ->required()

                        Forms\Components\Textarea::make('content')
                            ->label('Contenido')
                            ->rows(12)
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\DatePicker::make('publication_date')
                            ->label('Fecha de publicación')
                            ->required(),

                        // Relación: public function author() { return $this->belongsTo(Author::class, 'author_id'); }
                        Forms\Components\Select::make('author_id')
                            ->label('Autor')
                            ->relationship('author', 'first_name') // usa 'name' si ese es tu campo visible
                            ->searchable()
                            ->preload()
                            ->required(),

                        // Relación: public function publicationType() { return $this->belongsTo(PublicationType::class, 'publication_type_id'); }
                        Forms\Components\Select::make('publication_type_id')
                            ->label('Tipo de publicación')
                            ->relationship('publicationType', 'name') // nombre EXACTO del método de relación
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
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),

                Tables\Columns\TextColumn::make('publication_date')
                    ->label('Fecha')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('author.first_name') // ajusta a 'author.name' si aplica
                    ->label('Autor')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('publicationType.name')
                    ->label('Tipo')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
        return [];
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
