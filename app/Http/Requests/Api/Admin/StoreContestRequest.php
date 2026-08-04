<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreContestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role middleware guards the route
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'integer', 'exists:type_contest,id'],
            'status' => ['nullable', 'integer', 'exists:status_contest,id'],
            'typeEvent' => ['nullable', 'string', 'max:64'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'resultsPublicationDate' => ['nullable', 'date'],
            'numberOfWinners' => ['nullable', 'integer', 'min:0'],
            'criterion' => ['nullable', 'integer', 'exists:criterion,id'],
            'product' => ['nullable', 'integer'],
            'program' => ['nullable', 'integer'],
            'numericValue' => ['nullable', 'numeric'],
            'nameNumericValue' => ['nullable', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:1024'],
            'visibility' => ['nullable', 'string', 'max:255'],
            'visibilityConsultants' => ['nullable', 'boolean'],
            'visibilityResidents' => ['nullable', 'boolean'],
            'conditionalTurnOn' => ['nullable', 'boolean'],
            'urlData' => ['nullable', 'string', 'max:1024'],
            'headers' => ['nullable', 'string', 'max:1024'],
            'techComment' => ['nullable', 'string'],
            'archiveDate' => ['nullable', 'date'],
        ];
    }
}
