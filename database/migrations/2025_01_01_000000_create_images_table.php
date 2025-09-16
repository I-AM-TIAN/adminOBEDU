<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')
                ->constrained('publications')
                ->cascadeOnDelete();

            // Info devuelta por ImageKit
            $table->string('file_id')->nullable()->index(); // ImageKit fileId
            $table->string('url');                           // URL pública
            $table->string('provider')->default('imagekit');

            // Metadatos útiles
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 100)->nullable();

            // Contenido
            $table->string('alt')->nullable();
            $table->text('caption')->nullable();

            // Orden de visualización
            $table->integer('sort_order')->default(0);

            // Payload adicional que quieras guardar del response
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
