<?php

namespace App\Support;

use App\Http\Controllers\AnalyticsController;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

// A person's settings, stored as JSON on users.preferences. Whatever isn't stored comes from
// DEFAULTS, and keys that aren't listed here are dropped, so an old or a tampered app can't
// fill the column with junk.
class Preferences
{
    public const THEMES = ['light', 'dark', 'contrast', 'system'];
    public const PALETTES = ['farm', 'colorblind'];   // see the client's src/chartPalette.js
    public const RANGES = ['7', '30', '90', '365', 'custom'];
    public const MAX_SAVED_CHARTS = 30;

    public const DEFAULTS = [
        'theme' => 'light',          // outdoor use: light reads best in sunlight
        'accent' => null,            // #rrggbb, or null = the workspace's brand colour, else MyCow green
        'chart_palette' => 'farm',
        'dashboards' => [],          // chart card id => what it shows (ChartCard.vue)
    ];

    public static function for(User $user): array
    {
        $stored = is_array($user->preferences) ? $user->preferences : [];

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /**
     * Applies a partial update and returns the full settings. Each chart card in `dashboards`
     * is replaced as a whole; sending a card as null forgets it (back to the card's default).
     */
    public static function update(User $user, array $input): array
    {
        $data = Validator::make($input, self::rules(), [
            'accent.regex' => 'Pick a colour like #1b5e37.',
            'dashboards.max' => 'Too many saved charts.',
        ])->validate();
        // Laravel 9's validated() leaves out an `array` field that has nested rules, even when it
        // is null, so a reset card (`'farm-milk' => null`) never reaches us. Every card has passed
        // validation by now, so take `dashboards` as sent; the loop below keeps only known fields.
        if (array_key_exists('dashboards', $input)) {
            $data['dashboards'] = $input['dashboards'];
        }
        $data = array_intersect_key($data, self::DEFAULTS);

        $prefs = self::for($user);
        foreach ($data as $key => $value) {
            if ($key !== 'dashboards') {
                $prefs[$key] = $value;
                continue;
            }
            foreach ($value as $card => $choice) {
                if ($choice === null) {
                    unset($prefs['dashboards'][$card]);
                } else {
                    $prefs['dashboards'][$card] = array_intersect_key($choice, array_flip(['metric', 'group', 'range', 'from', 'to', 'granularity', 'type', 'compare']));
                }
            }
        }
        if (count($prefs['dashboards']) > self::MAX_SAVED_CHARTS) {
            $prefs['dashboards'] = array_slice($prefs['dashboards'], -self::MAX_SAVED_CHARTS, null, true);
        }

        $user->preferences = $prefs;
        $user->save();

        return $prefs;
    }

    private static function rules(): array
    {
        return [
            'theme' => ['sometimes', Rule::in(self::THEMES)],
            'accent' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/', function ($attr, $value, $fail) {
                if (Contrast::isHex($value) && !Contrast::okForWhiteText($value)) {
                    $fail('This colour is too light: white text on it would be hard to read outdoors. Pick a darker one.');
                }
            }],
            'chart_palette' => ['sometimes', Rule::in(self::PALETTES)],
            'dashboards' => ['sometimes', 'array', 'max:' . self::MAX_SAVED_CHARTS, function ($attr, $value, $fail) {
                foreach (array_keys($value) as $key) {
                    if (!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9.\-]{0,39}$/', $key)) {
                        $fail('A saved chart has an invalid name.');
                    }
                }
            }],
            'dashboards.*' => ['nullable', 'array'],
            'dashboards.*.metric' => ['required_with:dashboards.*', Rule::in(array_keys(AnalyticsController::METRICS))],
            'dashboards.*.group' => ['required_with:dashboards.*', 'string', 'max:20'],
            'dashboards.*.range' => ['nullable', Rule::in(self::RANGES)],
            'dashboards.*.from' => ['nullable', 'date_format:Y-m-d'],
            'dashboards.*.to' => ['nullable', 'date_format:Y-m-d'],
            'dashboards.*.granularity' => ['nullable', Rule::in(AnalyticsController::GRANULARITIES)],
            'dashboards.*.type' => ['nullable', Rule::in(AnalyticsController::CHART_TYPES)],
            'dashboards.*.compare' => ['nullable', 'boolean'],
        ];
    }
}
