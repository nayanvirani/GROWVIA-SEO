<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class KeywordSeed extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'keyword_seeds';

    protected $guarded = ['id', 'workspace_id'];
}
