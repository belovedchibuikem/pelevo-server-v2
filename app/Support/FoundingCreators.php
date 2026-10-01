<?php

namespace App\Support;

final class FoundingCreators
{
    /** @var array<string, string> */
    public const FREQUENCIES = [
        'weekly' => 'Weekly',
        'biweekly' => 'Every couple weeks',
        'monthly' => 'Monthly',
        'irregular' => 'Irregular / on and off',
        'paused' => "Haven't published in a while",
    ];

    /** @var array<string, string> */
    public const STATES = [
        'new' => 'New',
        'reviewing' => 'Reviewing',
        'approved' => 'Approved',
        'invited' => 'Claim link sent',
        'declined' => 'Declined',
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $map): array
    {
        return collect($map)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }
}
