<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBusinessStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // public enquiry form
    }

    public function rules(): array
    {
        return [
            // company
            'company_name' => ['required', 'string', 'max:160'],
            'industry'     => ['nullable', 'string', 'max:120'],
            'website'      => ['nullable', 'string', 'max:200'],
            'tax_number'   => ['nullable', 'string', 'max:60'],

            // contact
            'contact_name' => ['required', 'string', 'max:120'],
            'job_title'    => ['nullable', 'string', 'max:120'],
            'email'        => ['required', 'email:rfc', 'max:180'],
            'phone'        => ['nullable', 'string', 'max:40'],

            // stay
            'check_in'       => ['nullable', 'date', 'after_or_equal:today'],
            'check_out'      => ['nullable', 'date', 'after:check_in'],
            'dates_flexible' => ['sometimes', 'boolean'],
            'flexible_note'  => ['nullable', 'string', 'max:180'],

            'guests_count'   => ['required', 'integer', 'min:1', 'max:200'],
            'cottages_count' => ['required', 'integer', 'min:1', 'max:20'],

            'purpose'          => ['nullable', 'string', 'max:120'],
            'budget_per_night' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'currency'         => ['nullable', 'string', 'size:3'],

            'needs_invoice'       => ['sometimes', 'boolean'],
            'needs_meeting_space' => ['sometimes', 'boolean'],
            'pets'                => ['sometimes', 'boolean'],

            'message' => ['nullable', 'string', 'max:2000'],

            // Honeypot: a field real people never see, bots fill in.
            'company_website_url' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required'   => 'Please tell us which company this is for.',
            'contact_name.required'   => 'Please give us a name to reply to.',
            'email.required'          => 'We need an email address to send you a quote.',
            'guests_count.required'   => 'How many people are travelling?',
            'cottages_count.required' => 'Roughly how many cottages do you need?',
            'check_out.after'         => 'Check-out has to be after check-in.',
            'company_website_url.prohibited' => 'Something went wrong. Please try again.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /*
                 * Either fixed dates or an explicit "we're flexible" — an
                 * enquiry with neither leaves us nothing to quote against.
                 */
                if (!$this->filled('check_in') && !$this->boolean('dates_flexible')) {
                    $validator->errors()->add(
                        'check_in',
                        'Add a check-in date, or tick "our dates are flexible".'
                    );
                }

                /*
                 * More cottages than guests is almost always a slip. Six
                 * cottages sleep ~36, so this is a gentle sanity check rather
                 * than a hard capacity rule.
                 */
                if ($this->integer('cottages_count') > $this->integer('guests_count')) {
                    $validator->errors()->add(
                        'cottages_count',
                        'That is more cottages than guests — please double-check.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency'            => strtoupper((string) ($this->input('currency') ?: 'CAD')),
            'dates_flexible'      => $this->boolean('dates_flexible'),
            'needs_invoice'       => $this->boolean('needs_invoice'),
            'needs_meeting_space' => $this->boolean('needs_meeting_space'),
            'pets'                => $this->boolean('pets'),
        ]);
    }
}