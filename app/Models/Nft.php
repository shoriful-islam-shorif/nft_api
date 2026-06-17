<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Nft extends Model
{
    use HasFactory;

    protected $fillable = [
        // Basic
        'name', 
        'description', 
        'symbol',
        'image_url', 
        'image_hash',
        'metadata_uri', 
        'metadata_hash',

        // Collection
        'collection_id', 
        'category',

        // Supply
        'edition_type', 
        'total_supply', 
        'minted_count',

        // Pricing
        'mint_price', 'is_free_listing',
        'has_mint_discount', 'mint_discount_percent', 'price_after_discount',

        // Buyer Discount
        'has_buyer_discount', 'buyer_discount_percent', 'buyer_discount_max_uses',

        // Royalty & Network
        'royalty', 'network', 'network_fee',

        // Blockchain
        'wallet_address', 'mint_address', 'transaction_sig',

        // Status
        'status', 'minted_at','is_listed', 'list_price', 'listed_at',

        
    ];

    protected $casts = [
        'is_free_listing'    => 'boolean',
        'has_mint_discount'  => 'boolean',
        'has_buyer_discount' => 'boolean',
        'minted_at'          => 'datetime',
        'mint_price'         => 'float',
        'price_after_discount' => 'float',
        'network_fee'        => 'float',
        'is_listed'  => 'boolean',
        'list_price' => 'float',
        'listed_at'  => 'datetime'
    ];

    // Collection relation
    public function collection()
    {
        return $this->belongsTo(Collection::class);
    }

    // Price after discount calculate 
    public function getCalculatedPriceAttribute(): float
    {
        if ($this->is_free_listing) return 0;
        if (!$this->has_mint_discount) return $this->mint_price;
        return $this->mint_price - ($this->mint_price * $this->mint_discount_percent / 100);
    }

    // Total mint cost (price + network fee)
    public function getTotalCostAttribute(): float
    {
        return $this->getCalculatedPriceAttribute() + $this->network_fee;
    }
}
