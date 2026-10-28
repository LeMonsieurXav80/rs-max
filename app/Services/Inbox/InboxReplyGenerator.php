<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\Setting;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InboxReplyGenerator
{
    public function generate(InboxItem $item, SocialAccount $account, ?bool $usePersona = null): ?string
    {
        $apiKey = Setting::getEncrypted('openai_api_key');

        if (! $apiKey) {
            return null;
        }

        $persona = $account->persona;
        $usePersona ??= (bool) Setting::get('inbox_use_persona', true);

        // If persona is required (enabled) but missing, we can't generate
        if ($usePersona && ! $persona) {
            return null;
        }

        $typeLabel = match ($item->type) {
            'dm' => 'message privé',
            'comment' => 'commentaire',
            'reply' => 'réponse',
            default => 'message',
        };

        // Build conversation context: find other items in the same thread
        $threadItems = collect();
        if ($item->type === 'dm' && $item->external_post_id) {
            $threadItems = InboxItem::where('social_account_id', $item->social_account_id)
                ->where('external_post_id', $item->external_post_id)
                ->where('type', 'dm')
                ->where('id', '!=', $item->id)
                ->orderBy('posted_at', 'asc')
                ->limit(10)
                ->get();
        } elseif ($item->parent_id || $item->external_id) {
            $rootId = $item->parent_id ?: $item->external_id;
            $threadItems = InboxItem::where('social_account_id', $item->social_account_id)
                ->where(function ($q) use ($rootId) {
                    $q->where('external_id', $rootId)      // the root comment
                        ->orWhere('parent_id', $rootId);      // other replies to root
                })
                ->where('id', '!=', $item->id)
                ->orderBy('posted_at', 'asc')
                ->limit(10)
                ->get();
        }

        $prompt = "N'entoure JAMAIS ta réponse de guillemets.\n"
            ."Adapte le style de ta réponse au message reçu : si le message ne contient que des emojis, réponds uniquement avec des emojis. Si le message mélange texte et emojis, réponds avec du texte et des emojis dans des proportions similaires. Si le message est uniquement du texte, réponds avec du texte (tu peux ajouter un emoji en fin de message).\n\n";

        if ($threadItems->isNotEmpty()) {
            $prompt .= "Contexte de la conversation :\n";
            foreach ($threadItems as $ti) {
                $author = $ti->author_name ?? $ti->author_username ?? 'Inconnu';
                $prompt .= "- {$author} : \"{$ti->content}\"\n";
                if ($ti->reply_content) {
                    $prompt .= "- [Notre réponse] : \"{$ti->reply_content}\"\n";
                }
            }
            $prompt .= "\n";
        }

        $prompt .= "{$typeLabel} de {$item->author_name} :\n\"{$item->content}\"\n\n"
            .'IMPORTANT: You MUST reply in the same language as the message above. If the message is in English, reply in English. If in Italian, reply in Italian. Match the language exactly.';

        // Build system prompt: persona (if enabled) + inbox reply wrapper
        $replyWrapper = Setting::get('inbox_reply_prompt', '');
        $systemPrompt = ($usePersona && $persona) ? $persona->system_prompt : '';
        if ($replyWrapper) {
            $systemPrompt .= ($systemPrompt ? "\n\n" : '').$replyWrapper;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('ai_model_inbox', Setting::get('ai_model_text', 'gpt-4o-mini')),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content', '')) ?: null;
            }
        } catch (\Throwable $e) {
            Log::error('InboxReplyGenerator: AI reply generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
