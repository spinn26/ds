<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatTicket extends Model
{
    protected $table = 'chat_tickets';

    protected $guarded = ['id'];

    // Intentionally no datetime/json casts: this model is used alongside
    // raw DB::table() queries and the API JSON contract relies on the
    // Postgres-native string formats (e.g. "2026-04-20 10:00:00", raw
    // "tags" JSON string). Casting would silently change the contract.
}
