<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'wallet_address',
        'symbol',
    ];

    public function nfts()
    {
        return $this->hasMany(Nft::class);
    }
}
