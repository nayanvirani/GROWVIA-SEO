<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Competitor extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'competitors';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = ['active' => 'boolean'];
}
