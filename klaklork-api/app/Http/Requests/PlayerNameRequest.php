<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The display name.
 *
 * This is the only free text the application accepts, and the only thing one
 * player can put in front of another player's eyes — so it is also the only
 * input worth a dedicated rule. Guest entry and rename both use it, which is
 * the point: the rule lived in two controllers before and had already been
 * copied once, so the two could drift apart.
 */
class PlayerNameRequest extends FormRequest
{
    /**
     * An allowlist, not a blocklist: letters, numbers, and four pieces of
     * punctuation. Angle brackets, quotes, backslashes, control and format
     * characters have no representation here at all, so there is nothing for a
     * consumer downstream to have to escape.
     *
     * \p{M} has to be allowed because Khmer builds syllables out of combining
     * marks — coeng and vowel signs — so "ម្ចាស់ការ" is letters plus marks
     * rather than letters alone. The first character must still be a letter or
     * a digit, so a name can never be a bare stack of marks.
     */
    public const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{M}\p{N} _.\-]*$/u';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:20', 'regex:'.self::NAME_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name can only contain letters, numbers, spaces, dots, dashes and underscores.',
        ];
    }

    /**
     * Collapse internal runs of whitespace.
     *
     * The framework trims the ends before validation runs, so this only has to
     * deal with the middle: it stops a name being padded out into something
     * that reads as another player's, or that stretches a roster row. Runs are
     * collapsed before the length rules apply, so padding cannot buy length
     * either.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->name)) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', $this->name)]);
        }
    }

    /** The validated, collapsed name — ready to store as-is. */
    public function displayName(): string
    {
        return $this->validated()['name'];
    }
}
