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
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile; // 👈 IMPORTANTE
use Livewire\TemporaryUploadedFile;

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
                    ->description('Sube un .docx con las etiquetas Title, Abstract y Content para autocompletar los campos.')
                    ->schema([
                        Forms\Components\FileUpload::make('docx_upload')
                            ->label('Archivo .docx')
                            ->disk('public') // (lo usaremos para el guardado opcional)
                            ->directory('imports/publications')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->downloadable()
                            ->openable()
                            ->preserveFilenames()
                            ->dehydrated(false)   // no guardar este campo en la BD
                            ->reactive()          // dispara callbacks al cambiar
                            -> afterStateUpdated(function ($state, Set $set) {
                                if (!$state) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('No se recibió el archivo')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                // $state puede ser string (token temporal o ruta relativa) o array (si múltiple)
                                $raw = is_array($state) ? ($state[0] ?? null) : $state;
                                if (!$raw) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Ruta vacía')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                $filePath = null;

                                try {
                                    // --- 1) Livewire v3 ---
                                    if (class_exists(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile::class)) {
                                        $tuf = null;

                                        // Si $raw es token temporal (empieza con "livewire-file:"), lo convertimos:
                                        if (is_string($raw) && str_starts_with($raw, 'livewire-file:')) {
                                            $tuf = \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::unserializeFromLivewireRequest($raw);
                                        }

                                        // Si por alguna razón ya es instancia:
                                        if (!$tuf && $raw instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                            $tuf = $raw;
                                        }

                                        if ($tuf) {
                                            // getRealPath puede ser null en Windows: usamos también getPathname()
                                            $filePath = $tuf->getRealPath() ?: $tuf->getPathname();
                                        }
                                    }

                                    // --- 2) Livewire v2 (proyectos más antiguos) ---
                                    if (!$filePath && class_exists(\Livewire\TemporaryUploadedFile::class)) {
                                        // en v2 el método es createFromLivewire
                                        $tuf = \Livewire\TemporaryUploadedFile::createFromLivewire($raw);
                                        if ($tuf) {
                                            $filePath = $tuf->getRealPath() ?: $tuf->getPathname();
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    // seguimos con fallbacks abajo
                                }

                                // --- 3) Fallback: quizá $raw ya es ruta persistida en el disk 'public' ---
                                if ((!$filePath || !file_exists($filePath)) && is_string($raw) && str_contains($raw, '/')) {
                                    $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($raw);
                                    if (file_exists($abs)) {
                                        $filePath = $abs;
                                    }
                                }

                                // --- 4) Último intento: si $raw parece ruta del sistema (ej. C:\Users\...\Temp\phpABC.tmp) ---
                                if ((!$filePath || !file_exists($filePath)) && is_string($raw) && file_exists($raw)) {
                                    $filePath = $raw;
                                }

                                // Si aún no lo tenemos, avisamos qué valor llegó
                                if (!$filePath || !file_exists($filePath)) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('No pude acceder al archivo')
                                        ->body("Valor recibido: " . (is_string($raw) ? $raw : '[obj]'))
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                // --- Parsear el DOCX ---
                                try {
                                    $data = \App\Support\DocxPublicationParser::parse($filePath);

                                    $set('title',    $data['title']    ?? '');
                                    $set('abstract', $data['abstract'] ?? '');
                                    $set('content',  $data['content']  ?? '');

                                    // (Opcional) moverlo ya al disk 'public' para que quede persistente y actualizar el estado
                                    if (
                                        class_exists(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile::class)
                                        && isset($tuf) && $tuf instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
                                    ) {
                                        $stored = $tuf->storeAs('imports/publications', $tuf->getClientOriginalName(), 'public');
                                        if ($stored) {
                                            $set('docx_upload', $stored);
                                        }
                                    }

                                    \Filament\Notifications\Notification::make()
                                        ->title('Contenido importado')
                                        ->body('Se completaron Título, Resumen y Contenido desde el Word.')
                                        ->success()
                                        ->send();
                                } catch (\Throwable $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Error al leer el .docx')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            })
                            ->helperText('Al subir el archivo se llenarán automáticamente Título, Resumen y Contenido.')
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
                            ->required()
                            ->columnSpanFull(),

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
                            ->relationship('publicationType', 'name') // nombre EXACTO del método
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
