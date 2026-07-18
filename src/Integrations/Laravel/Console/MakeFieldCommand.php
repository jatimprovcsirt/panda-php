<?php

namespace Panda\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeFieldCommand extends Command
{
    protected $signature = 'panda:make-field {model : The Eloquent model class name} {field : The field name to encrypt} {--no-blind-index : Skip generating a blind index column}';

    protected $description = 'Generate a migration for an encrypted field and output model configuration instructions';

    public function handle(): int
    {
        $model = $this->argument('model');
        $field = Str::snake($this->argument('field'));
        $noBlindIndex = $this->option('no-blind-index');

        // Resolve class name
        $className = $model;
        if (!str_contains($className, '\\')) {
            $className = 'App\\Models\\' . $className;
        }

        $table = null;
        if (class_exists($className)) {
            try {
                $instance = new $className();
                $table = $instance->getTable();
            } catch (\Exception $e) {
                // Ignore and fallback
            }
        }

        if (!$table) {
            $table = Str::snake(Str::plural(class_basename($model)));
        }

        $this->info("Creating migration to add encrypted field '{$field}' to '{$table}' table...");

        $timestamp = date('Y_m_d_His');
        $migrationName = "add_encrypted_{$field}_to_{$table}_table";
        $fileName = "{$timestamp}_{$migrationName}.php";
        
        $migrationsPath = database_path('migrations');
        $filePath = $migrationsPath . '/' . $fileName;

        $stub = $this->getMigrationStub($table, $field, !$noBlindIndex);

        if (!File::isDirectory($migrationsPath)) {
            File::makeDirectory($migrationsPath, 0755, true);
        }
        
        File::put($filePath, $stub);

        $this->info("✓ Migration created: database/migrations/{$fileName}");

        $this->comment("\nNext Steps:");
        $this->comment("1. Run php artisan migrate to apply the changes.");
        $this->comment("2. Update your model [{$className}] to use the EncryptedCast and HasEncryptedFields trait:");
        
        $castLine = "'{$field}' => \\Panda\\Integrations\\Laravel\\EncryptedCast::class . ':default-key'";
        $traitImport = "use Panda\\Integrations\\Laravel\\HasEncryptedFields;";
        $encryptedFields = "    protected \$encryptedFields = [\n        '{$field}' => ['kid' => 'default-key', 'blind_index' => true],\n    ];";

        $instructions = <<<TEXT

class {$model} extends Model
{
    {$traitImport}
    use HasEncryptedFields;

    protected \$casts = [
        {$castLine},
    ];

    {$encryptedFields}
}
TEXT;
        $this->line($instructions);

        return 0;
    }

    private function getMigrationStub(string $table, string $field, bool $withBlindIndex): string
    {
        $upSchema = "\$table->text('{$field}')->nullable();";
        $downSchema = "'{$field}'";

        if ($withBlindIndex) {
            $upSchema .= "\n            \$table->string('{$field}_bidx', 64)->nullable()->index();";
            $downSchema .= ", '{$field}_bidx'";
        }

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint $table) {
            {$upSchema}
        });
    }

    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint $table) {
            \$table->dropColumn([{$downSchema}]);
        });
    }
};
PHP;
    }
}
