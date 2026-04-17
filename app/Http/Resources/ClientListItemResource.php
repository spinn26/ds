<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Accepts an array with joined person data (see ClientController::index).
 */
class ClientListItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $src = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $src['id'] ?? null,
            'personName' => $src['personName'] ?? null,
            'birthDate' => $src['birthDate'] ?? null,
            'city' => $src['city'] ?? null,
            'phone' => $src['phone'] ?? null,
            'email' => $src['email'] ?? null,
        ];
    }
}
