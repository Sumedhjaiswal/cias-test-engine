<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared Claude API utilities
 * Single source of truth for all AI calls in CIAS plugin
 */
class CIAS_AI_Utils {

    /** Get API key — prefers wp-config constant over DB */
    public static function get_api_key(): string {
        if (defined('CIAS_ANTHROPIC_KEY') && CIAS_ANTHROPIC_KEY) return CIAS_ANTHROPIC_KEY;
        return (string) get_option('cias_anthropic_key', '');
    }

    /** Call Claude and return text, or '' on error */
    public static function call(string $prompt, string $model = 'claude-haiku-4-5-20251001', int $max_tokens = 500): string {
        $key = self::get_api_key();
        if (empty($key)) return '';

        // Simple daily call counter (lightweight rate guard)
        $today  = current_time('Y-m-d');
        $count_key = 'cias_ai_calls_' . $today;
        $calls  = (int) get_transient($count_key);
        $limit  = (int) get_option('cias_ai_daily_call_limit', 500);
        if ($limit > 0 && $calls >= $limit) return '';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return '';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = trim($body['content'][0]['text'] ?? '');

        // Log usage
        $used_in  = intval($body['usage']['input_tokens'] ?? 0);
        $used_out = intval($body['usage']['output_tokens'] ?? 0);
        self::log_usage($model, $used_in, $used_out, 'system');

        // Increment daily counter
        set_transient($count_key, $calls + 1, DAY_IN_SECONDS);

        return $text;
    }

    /** Shared parent report AI note — replaces duplicate in email & WA classes */
    public static function generate_ai_note(string $name, array $data, string $type): string {
        if (get_option('cias_wa_ai_note', '0') !== '1') return '';

        $subject_text = implode(', ', array_map(
            function($k, $v) { return "$k: {$v}%"; },
            array_keys($data['subject_scores'] ?? []),
            array_values($data['subject_scores'] ?? [])
        ));
        $weak_text = implode(', ', array_map(
            function($w){ return is_object($w) ? $w->topic_name : $w; },
            $data['weak_topics'] ?? []
        ));

        $prompt = "Write exactly 2 short sentences as a personalised teacher's note for a parent about {$name}.\n"
            . "One sentence Hindi, one English. Encouraging, specific, actionable.\n"
            . "Facts: tests={$data['tests_taken']}, avg={$data['avg_score']}%, streak={$data['streak']} days"
            . ($subject_text ? ", subjects={$subject_text}" : '')
            . ($weak_text    ? ", weak={$weak_text}" : '')
            . ".\nNo greetings. No emojis. Just 2 sentences.";

        return self::call($prompt, 'claude-haiku-4-5-20251001', 120);
    }

    /** Log API usage to DB */
    public static function log_usage(string $model, int $in_tokens, int $out_tokens, string $context, int $user_id = 0): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_usage_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return;

        $cost = ($in_tokens / 1000000 * 0.80) + ($out_tokens / 1000000 * 4.00); // Haiku pricing
        if (str_contains($model, 'sonnet')) {
            $cost = ($in_tokens / 1000000 * 3.00) + ($out_tokens / 1000000 * 15.00);
        }

        $wpdb->insert($table, [
            'user_id'      => intval($user_id) ?: get_current_user_id(),
            'model'        => $model,
            'context'      => $context,
            'input_tokens' => $in_tokens,
            'output_tokens'=> $out_tokens,
            'cost_usd'     => round($cost, 6),
            'created_at'   => current_time('mysql'),
        ]);
    }
}
