<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreGuestPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_name'  => ['required', 'string', 'max:120'],
            'guest_email' => ['required', 'email:rfc', 'max:180'],
            'caption'     => ['nullable', 'string', 'max:300'],
            'cottage_id'  => ['nullable', 'integer'],
            'stayed_on'   => ['nullable', 'date', 'before_or_equal:today'],

            /*
             * Validate on the file's real content, not its extension.
             * File::image() inspects the actual MIME type, so a renamed .php
             * fails here rather than reaching disk.
             */
            'photos'   => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp', 'heic'])
                    ->max(12 * 1024),          // 12 MB — phone photos are large
                'dimensions:min_width=600,min_height=400',
            ],

            // Not a checkbox we can quietly default: publishing someone's photo
            // needs a recorded yes.
            'consent_given' => ['accepted'],

            'website_url' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required'   => 'Please choose at least one photo.',
            'photos.max'        => 'Ten photos at a time, please — you can always send more after.',
            'photos.*.image'    => 'That file doesn\'t look like a photo.',
            'photos.*.max'      => 'Each photo needs to be under 12 MB.',
            'photos.*.dimensions' => 'That photo is too small to display well — 600×400 or larger, please.',
            'consent_given.accepted' => 'We need your permission before we can publish your photos.',
            'website_url.prohibited' => 'Something went wrong. Please try again.',
        ];
    }
}
