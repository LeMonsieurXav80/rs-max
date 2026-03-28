<?php

use App\Models\LanguageGroup;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $groups = [
            [
                'name' => 'EcoFlow',
                'languages' => [
                    'ja', 'ko', 'zh-CN', 'zh-TW',
                    'de', 'es', 'it', 'nl', 'pt-PT', 'en-GB',
                    'da', 'sv', 'no', 'fi', 'is',
                    'pl', 'cs', 'sk', 'hu', 'ro', 'bg', 'uk', 'ru',
                    'el', 'hr', 'sl', 'sr', 'mk', 'sq', 'bs', 'mt',
                    'et', 'lv', 'lt',
                    'tr', 'ka',
                    'pt-BR',
                    'ar',
                    'hi', 'ne', 'sw',
                ],
            ],
            [
                'name' => 'Aferiy',
                'languages' => [
                    'en-GB', 'en-US',
                    'de', 'fr', 'it', 'es', 'pl', 'uk', 'nl', 'ro', 'el', 'cs', 'pt-PT', 'hu', 'sv', 'bg', 'da', 'fi', 'sk', 'no', 'hr', 'lt', 'sl', 'lv', 'et',
                ],
            ],
            [
                'name' => 'Reolink',
                'languages' => [
                    'en-US', 'es-MX', 'fr-CA',
                    'zh-CN', 'zh-TW', 'ja', 'ko', 'ms', 'th', 'vi', 'id', 'tl',
                    'ar', 'he',
                    'en-GB', 'fr', 'de', 'es', 'it', 'nl', 'pt-PT',
                    'da', 'sv', 'no', 'fi',
                    'pl', 'cs', 'sk', 'hu', 'ro', 'bg',
                    'el', 'hr', 'sl', 'sr', 'mt',
                    'et', 'lv', 'lt',
                ],
            ],
            [
                'name' => 'Vivaia',
                'languages' => [
                    'en-US', 'fr-CA', 'es-MX',
                    'es', 'pt-BR',
                    'en-GB', 'de', 'fr', 'it', 'nl', 'pt-PT',
                    'da', 'sv', 'no', 'fi',
                    'pl', 'cs', 'sk', 'hu', 'ro', 'bg',
                    'el', 'hr', 'sl', 'mt',
                    'et', 'lv', 'lt',
                    'zh-CN', 'zh-TW', 'ja', 'ko',
                    'th', 'vi', 'ms', 'id', 'tl', 'km',
                    'ar', 'he', 'tr',
                    'af', 'kk',
                ],
            ],
            [
                'name' => 'ALLPOWERS',
                'languages' => [
                    'en-US', 'en-GB',
                    'de', 'es', 'it', 'nl', 'pt-PT',
                    'da', 'sv', 'no', 'fi',
                    'pl', 'cs', 'sk', 'hu', 'ro', 'bg',
                    'el', 'hr', 'sl',
                    'et', 'lv', 'lt',
                ],
            ],
            [
                'name' => 'TOP 20',
                'languages' => [
                    'zh-CN', 'es', 'hi', 'ar', 'pt-BR', 'ru', 'ja', 'de', 'ko', 'vi',
                    'fr', 'it', 'pl', 'nl', 'tr',
                    'id', 'th', 'en-GB', 'sv', 'el',
                ],
            ],
        ];

        foreach ($groups as $group) {
            LanguageGroup::create($group);
        }
    }

    public function down(): void
    {
        LanguageGroup::whereIn('name', ['EcoFlow', 'Aferiy', 'Reolink', 'Vivaia', 'ALLPOWERS', 'TOP 20'])->delete();
    }
};
