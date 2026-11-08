<?php

namespace App\Policies;

use App\Models\IndustryStockItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Industry stock manager items.
 *
 * Abilities: view, create, update, delete.
 *
 * The routes also sit behind the `stock-manager` middleware. On top of that,
 * only the owner may act on an item; anyone else gets 404 (the old routes
 * looked the item up within the user's own items).
 */
class IndustryStockItemPolicy
{
    public function view(User $user, IndustryStockItem $item): Response
    {
        return $this->owns($user, $item);
    }

    public function create(User $user): bool
    {
        return $user->hasStockManager();
    }

    public function update(User $user, IndustryStockItem $item): Response
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, IndustryStockItem $item): Response
    {
        return $this->owns($user, $item);
    }

    private function owns(User $user, IndustryStockItem $item): Response
    {
        return $user->id === $item->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
