<?php
/**
 * CIAS Phase A – A6: Message Auto-Classifier
 *
 * Classifies every user message into one of the following types using
 * a fast rule-based primary pass, with an optional AI-assisted fallback
 * when the rule engine is not confident.
 *
 * Types
 * ──────────────────────────────────────────────────────────────────────
 *  question          – Asks for information / help
 *  concept_request   – Asks Claude to explain a topic or concept
 *  problem_solving   – Maths / logic / code problem
 *  essay_writing     – Requests a written piece
 *  feedback_request  – Asks for feedback / review
 *  creative          – Story, poem, roleplay, or creative task
 *  image_query       – Message accompanies an image upload
 *  greeting          – Hello / thanks / small-talk
 *  other             – None of the above
 *
 * Usage
 * ──────────────────────────────────────────────────────────────────────
 *   $type = CIAS_Message_Classifier::classify( $text, $has_image );
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */

defined( 'ABSPATH' ) || exit;

class CIAS_Message_Classifier {

    // Minimum confidence score to accept a rule-based result
    const RULE_CONFIDENCE_THRESHOLD = 0.55;

    // Cache TTL for AI-classified labels (seconds)
    const AI_CACHE_TTL = WEEK_IN_SECONDS;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Classify a user message.
     *
     * @param string $text       Raw message body.
     * @param bool   $has_image  True when the message includes an image attachment.
     *
     * @return string  One of the type slugs listed above.
     */
    public static function classify( string $text, bool $has_image = false ): string {
        if ( $has_image ) {
            return 'image_query';
        }

        $text_lower = mb_strtolower( trim( $text ) );

        if ( empty( $text_lower ) ) {
            return 'other';
        }

        // ── 1. Rule-based pass ────────────────────────────────────────────────
        [ $rule_type, $rule_score ] = self::rule_pass( $text_lower );

        if ( $rule_score >= self::RULE_CONFIDENCE_THRESHOLD ) {
            return $rule_type;
        }

        // ── 2. AI-assisted fallback (only if API key is configured) ───────────
        if ( apply_filters( 'cias_classifier_use_ai_fallback', false ) ) {
            $ai_type = self::ai_classify( $text );
            if ( $ai_type ) {
                return $ai_type;
            }
        }

        // ── 3. Return best rule-based guess ───────────────────────────────────
        return $rule_type ?: 'other';
    }

    // ── Rule-based engine ─────────────────────────────────────────────────────

    /**
     * @return array{string, float}  [type, confidence_score]
     */
    private static function rule_pass( string $text ): array {
        $scores = array_fill_keys( array_keys( self::type_labels() ), 0.0 );

        foreach ( self::rules() as $type => $rule_set ) {
            $score = 0.0;
            $total = 0;

            // Keyword patterns (each worth 1 point)
            foreach ( $rule_set['patterns'] as $pattern ) {
                $total += 1;
                if ( preg_match( $pattern, $text ) ) {
                    $score += 1;
                }
            }

            // Sentence-start patterns (worth 1.5 points – stronger signal)
            foreach ( $rule_set['starts'] ?? [] as $start_pattern ) {
                $total += 1.5;
                if ( preg_match( $start_pattern, $text ) ) {
                    $score += 1.5;
                }
            }

            $scores[ $type ] = $total > 0 ? ( $score / $total ) : 0.0;
        }

        arsort( $scores );
        $best_type  = array_key_first( $scores );
        $best_score = $scores[ $best_type ];

        return [ $best_type, $best_score ];
    }

    // ── Rule definitions ──────────────────────────────────────────────────────

    private static function rules(): array {
        return [
            'question' => [
                'patterns' => [
                    '/\b(what|who|where|when|why|how|which|whose|whom)\b/',
                    '/\?/',
                    '/\b(explain|tell me|describe|define|meaning of)\b/',
                    '/\b(can you|could you|would you|please)\b.{0,30}\b(tell|explain|show|describe)\b/',
                ],
                'starts' => [
                    '/^(what|who|where|when|why|how|which|is|are|does|do|did|was|were|can|could)\b/',
                ],
            ],
            'concept_request' => [
                'patterns' => [
                    '/\b(explain|understand|concept|theory|principle|idea|definition|meaning|overview)\b/',
                    '/\b(what is|what are|what does)\b/',
                    '/\b(how does|how do|how can)\b.{0,40}\bwork\b/',
                    '/\b(break down|simplify|in simple terms|for a beginner)\b/',
                ],
                'starts' => [
                    '/^(explain|describe|tell me about|what is|what are)\b/',
                ],
            ],
            'problem_solving' => [
                'patterns' => [
                    '/\b(solve|calculate|compute|evaluate|find|derive|prove|determine)\b/',
                    '/\b(equation|formula|algorithm|function|code|program|script)\b/',
                    '/\b(bug|error|exception|debug|fix|issue|problem)\b/',
                    '/\b(answer|result|output|value)\b.{0,20}\b(is|should|would|must)\b/',
                    '/[\d]+[\s]*[\+\-\*\/\^][\s]*[\d]+/',  // arithmetic expression
                ],
                'starts' => [
                    '/^(solve|calculate|compute|debug|find|derive|write (a |the )?(code|function|script|program))\b/',
                ],
            ],
            'essay_writing' => [
                'patterns' => [
                    '/\b(write|draft|compose|create|generate)\b.{0,40}\b(essay|article|paragraph|report|summary|letter|email|blog|post|content)\b/',
                    '/\b(words?|paragraph|introduction|conclusion|thesis)\b/',
                    '/\b(argumentative|persuasive|expository|narrative|descriptive) (essay|writing)\b/',
                ],
                'starts' => [
                    '/^(write|draft|compose|create).{0,40}(essay|article|paragraph|report|summary|letter)\b/',
                ],
            ],
            'feedback_request' => [
                'patterns' => [
                    '/\b(feedback|review|check|proofread|improve|critique|evaluate|assess)\b/',
                    '/\b(is this (correct|right|good|okay)|does this (look|sound|seem) (right|good|okay))\b/',
                    '/\b(my (answer|work|essay|solution|code|writing))\b/',
                    '/\b(what do you think|your opinion|any suggestions|any improvements)\b/',
                ],
                'starts' => [
                    '/^(can you (review|check|proofread|give feedback)|please (review|check|give feedback))\b/',
                ],
            ],
            'creative' => [
                'patterns' => [
                    '/\b(story|poem|song|rap|haiku|fiction|creative|roleplay|role-play|imagine|scenario)\b/',
                    '/\b(write (a|an) (story|poem|short story|tale|fiction))\b/',
                    '/\b(act as|pretend|you are|play the role)\b/',
                    '/\b(once upon|in a land|in a world)\b/',
                ],
                'starts' => [
                    '/^(write (a|an) (story|poem|haiku|rap|song)|tell me a story|once upon)\b/',
                ],
            ],
            'greeting' => [
                'patterns' => [
                    '/^(hi|hello|hey|howdy|greetings|good (morning|afternoon|evening|day))[,!\s]*$/',
                    '/^(thanks?|thank you|thx|ty)[,!\s.]*$/',
                    '/\b(how are you|what\'s up|sup|nice to meet)\b/',
                ],
                'starts' => [
                    '/^(hi|hello|hey|thanks?|thank you)\b/',
                ],
            ],
        ];
    }

    // ── AI fallback ───────────────────────────────────────────────────────────

    /**
     * Use the Anthropic API (via CIAS core helpers) to classify the message.
     * Result is cached in the WP object cache.
     *
     * @param string $text
     * @return string|null  Classified type or null on failure.
     */
    private static function ai_classify( string $text ): ?string {
        $cache_key = 'cias_cls_' . md5( $text );
        $cached    = wp_cache_get( $cache_key, 'cias_classifier' );
        if ( $cached ) {
            return $cached;
        }

        $type_list = implode( ', ', array_keys( self::type_labels() ) );
        $prompt    = "Classify the following student message into exactly one of these types: {$type_list}.\n"
                   . "Reply with ONLY the type slug, nothing else.\n\n"
                   . "Message: " . mb_substr( $text, 0, 500 );

        // Delegate to CIAS core API helper if available
        if ( function_exists( 'cias_api_complete' ) ) {
            $result = cias_api_complete( $prompt, [ 'max_tokens' => 20 ] );
            $type   = trim( strtolower( $result ?? '' ) );
            if ( array_key_exists( $type, self::type_labels() ) ) {
                wp_cache_set( $cache_key, $type, 'cias_classifier', self::AI_CACHE_TTL );
                return $type;
            }
        }

        return null;
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    /**
     * Human-readable label for each type slug.
     */
    public static function type_labels(): array {
        return [
            'question'        => __( 'Question',         'cias' ),
            'concept_request' => __( 'Concept Request',  'cias' ),
            'problem_solving' => __( 'Problem Solving',  'cias' ),
            'essay_writing'   => __( 'Essay / Writing',  'cias' ),
            'feedback_request'=> __( 'Feedback Request', 'cias' ),
            'creative'        => __( 'Creative',         'cias' ),
            'image_query'     => __( 'Image Query',      'cias' ),
            'greeting'        => __( 'Greeting',         'cias' ),
            'other'           => __( 'Other',            'cias' ),
        ];
    }

    /**
     * CSS colour for each type (used in dashboards / profile cards).
     */
    public static function type_colors(): array {
        return [
            'question'         => [ 'bg' => '#DBEAFE', 'fg' => '#1D4ED8' ],
            'concept_request'  => [ 'bg' => '#EDE9FE', 'fg' => '#6D28D9' ],
            'problem_solving'  => [ 'bg' => '#D1FAE5', 'fg' => '#065F46' ],
            'essay_writing'    => [ 'bg' => '#FEF3C7', 'fg' => '#92400E' ],
            'feedback_request' => [ 'bg' => '#FFE4E6', 'fg' => '#9F1239' ],
            'creative'         => [ 'bg' => '#FEE2E2', 'fg' => '#991B1B' ],
            'image_query'      => [ 'bg' => '#E0F2FE', 'fg' => '#0369A1' ],
            'greeting'         => [ 'bg' => '#F0FDF4', 'fg' => '#166534' ],
            'other'            => [ 'bg' => '#F3F4F6', 'fg' => '#374151' ],
        ];
    }
}
