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
        'token_standard', 'source',
        'category',

        // Supply
        'edition_type', 
        'total_supply', 
        'minted_count',
        'edition_group_id',
        'edition_number',

        // Pricing
        'mint_price', 'is_free_listing',
        'has_mint_discount', 'mint_discount_percent', 'price_after_discount', "list_currency",

        // Buyer Discount
        'has_buyer_discount', 'buyer_discount_percent', 'buyer_discount_max_uses',

        // Royalty & Network
        'royalty', 'network', 'network_fee','attributes',

        // Blockchain
        'wallet_address', 'mint_address', 'transaction_sig','creator_wallet',

        // Status
        'status', 'minted_at','is_listed', 'list_price', 'listed_at',
        'sold_to', 'sold_at', 'sold_tx', 'previous_owner','sold_price','sold_currency',

         // Links, unlockable content, content flag
        'external_website', 'external_social', 'external_twitter', 'external_sosay','external_telegram', 'external_whatsapp',
        'unlockable_content', 'is_explicit',

        // Storage fee / rent
        'image_size_bytes', 'storage_fee_spump', 'storage_paid_until', 'storage_warning_sent_at',

        
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
        'sold_price' => 'float',
        'listed_at'  => 'datetime',
        'attributes' => 'array',
        'is_explicit' => 'boolean',
        'image_size_bytes'   => 'integer',
        'storage_fee_spump'  => 'float',
        'storage_paid_until' => 'datetime',
        'storage_warning_sent_at' => 'datetime',

    ];

    protected $appends = ['storage_status'];

    /**
     * Storage lifecycle, computed fresh every time (never stored) so it
     * can never drift out of sync with storage_paid_until:
     *   - null            → pre-feature NFT, never tracked (no image_size_bytes)
     *   - active          → paid and not within 30 days of expiry
     *   - expiring_soon   → paid, but expires within 30 days
     *   - grace_period    → expired, but still within the admin-set grace window
     *   - hidden          → expired past grace — excluded from marketplace/collection
     *                       browsing (MarketplaceController/CollectionController),
     *                       but still visible to the owner so they can renew it.
     */
    public function getStorageStatusAttribute(): ?string
    {
        if (!$this->storage_paid_until) {
            return null;
        }

        $now = now();
        if ($this->storage_paid_until->isFuture()) {
            return $this->storage_paid_until->diffInDays($now) <= 30
                ? 'expiring_soon'
                : 'active';
        }

        $graceDays = (int) PlatformSetting::get('storage_grace_period_days', 14);
        $hiddenAt  = $this->storage_paid_until->copy()->addDays($graceDays);

        return $now->lessThan($hiddenAt) ? 'grace_period' : 'hidden';
    }

    /**
     * Excludes storage-hidden NFTs (expired past the grace period,
     * unrenewed) — for PUBLIC browsing/discovery queries only
     * (marketplace listing, collection grids). Owner-facing queries
     * (My NFTs, wallet profile, single NFT by mint address) should NOT
     * use this scope — the owner needs to keep seeing it to renew.
     */
    public function scopeStorageVisible($query)
    {
        $graceDays    = (int) PlatformSetting::get('storage_grace_period_days', 14);
        $hiddenCutoff = now()->subDays($graceDays);

        return $query->where(function ($q) use ($hiddenCutoff) {
            $q->whereNull('storage_paid_until')
              ->orWhere('storage_paid_until', '>', $hiddenCutoff);
        });
    }

    protected $hidden = ['unlockable_content'];

    // Collection relation
    public function collection()
    {
        return $this->belongsTo(Collection::class);
    }

    // All copies that belong to the same edition (including this one)
    public function editionSiblings()
    {
        if (!$this->edition_group_id) {
            return static::where('id', $this->id);
        }
        return static::where('edition_group_id', $this->edition_group_id);
    }

    // How many copies of this edition have actually been minted so far
    public function getEditionMintedCountAttribute(): int
    {
        return $this->editionSiblings()->where('status', 'minted')->count();
    }

    // How many copies are still available to mint (null = unlimited)
    public function getEditionRemainingAttribute(): ?int
    {
        if ($this->edition_type !== 'limited' || !$this->total_supply) {
            return null;
        }
        return max($this->total_supply - $this->getEditionMintedCountAttribute(), 0);
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
