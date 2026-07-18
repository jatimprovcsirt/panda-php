<?php

namespace Panda\Integrations\Laravel\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Panda\BlindIndex\BlindIndexer;
use Panda\Crypto\KeyProvider;

class UniqueEncrypted implements Rule
{
    private string $table;
    private string $column;
    private ?string $ignoreId;
    private string $idColumn;
    private string $blindIndexColumn;
    private string $kid;
    private string $blindIndexKid;
    private string $normalization;

    public function __construct(
        string $table,
        string $column,
        ?string $ignoreId = null,
        string $idColumn = 'id',
        ?string $blindIndexColumn = null,
        ?string $kid = null,
        ?string $blindIndexKid = null,
        string $normalization = 'lowercase'
    ) {
        $this->table = $table;
        $this->column = $column;
        $this->ignoreId = $ignoreId;
        $this->idColumn = $idColumn;
        $this->blindIndexColumn = $blindIndexColumn ?: "{$column}_bidx";
        $this->kid = $kid ?: (getenv('PANDA_DEFAULT_KID') ?: 'default-key');
        $this->blindIndexKid = $blindIndexKid ?: "{$this->kid}-idx";
        $this->normalization = $normalization;
    }

    public function passes($attribute, $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Generate the blind index value for the input
        /** @var KeyProvider $keyProvider */
        $keyProvider = app(KeyProvider::class);
        $blindIndexKey = $keyProvider->getKey($this->blindIndexKid);
        $blindIndexValue = BlindIndexer::generate($value, $blindIndexKey, $this->normalization);

        $query = DB::table($this->table)
            ->where($this->blindIndexColumn, $blindIndexValue);

        if ($this->ignoreId !== null) {
            $query->where($this->idColumn, '!=', $this->ignoreId);
        }

        return !$query->exists();
    }

    public function message(): string
    {
        return trans('validation.unique', ['attribute' => $this->column]);
    }
}
