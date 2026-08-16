<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:120'],
            'email'   => ['required', 'email:rfc', 'max:180'],
            'phone'   => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],

            // Honeypot — invisible to people, filled by bots.
            'website_url' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'Please tell us your name.',
            'email.required'   => 'We need an email address to reply to.',
            'message.required' => 'Please write your message.',
            'message.min'      => 'Could you give us a little more detail?',
            'website_url.prohibited' => 'Something went wrong. Please try again.',
        ];
    }
}