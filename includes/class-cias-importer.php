<?php
if (!defined('ABSPATH')) exit;

class CIAS_Importer {

    /* ══════════════════════════════════
       MAIN ENTRY — parse raw text into questions
    ══════════════════════════════════ */
    public static function parse_text($raw_text) {
        $results  = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'questions' => []];
        $raw_text = str_replace(["\r\n", "\r"], "\n", $raw_text);

        // Split on === or --- separators (3+ chars)
        $blocks = preg_split('/\n={3,}\n|\n-{3,}\n/', $raw_text);

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            $parsed = self::parse_block($block);
            if (is_string($parsed)) {
                $results['errors'][]  = $parsed;
                $results['skipped']++;
            } else {
                $results['questions'][] = $parsed;
            }
        }

        return $results;
    }

    /* ══════════════════════════════════
       PARSE A SINGLE QUESTION BLOCK
    ══════════════════════════════════ */
    private static function parse_block($block) {
        $lines = array_map('trim', explode("\n", $block));
        $lines = array_filter($lines, function($l) { return $l !== ''; });
        $lines = array_values($lines);

        if (count($lines) < 6) return 'Block too short — needs at least header, question, options A-D, and ANSWER line.';

        // ── Line 1: header ──
        // Format: SUBJECT | TOPIC | DIFFICULTY | TAGS | YEAR
        // All parts after SUBJECT are optional
        $header   = $lines[0];
        $parts    = array_map('trim', explode('|', $header));
        $subject_name = $parts[0] ?? '';
        $topic_name   = $parts[1] ?? '';
        $difficulty   = strtolower($parts[2] ?? 'medium');
        $tags_str     = $parts[3] ?? '';
        $year         = intval($parts[4] ?? 0);

        if (empty($subject_name)) return 'Missing subject in header line.';
        if (!in_array($difficulty, ['easy','medium','hard'])) $difficulty = 'medium';

        // Look up subject ID
        global $wpdb;
        $subject_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_subjects WHERE name LIKE %s LIMIT 1",
            '%' . $subject_name . '%'
        ));
        if (!$subject_id) return "Subject not found: \"$subject_name\". Add it in CIAS Tests → Subjects first.";

        // Optional: look up topic
        $topic_id = 0;
        if ($topic_name) {
            $topic_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cias_topics WHERE name LIKE %s AND subject_id=%d LIMIT 1",
                '%' . $topic_name . '%', $subject_id
            ));
        }

        // ── Remaining lines: body ──
        $body_lines = array_slice($lines, 1);

        // Extract ANSWER line
        $answer_line  = '';
        $answer_idx   = -1;
        foreach ($body_lines as $i => $l) {
            if (preg_match('/^ANSWER\s*:\s*([ABCD])/i', $l, $m)) {
                $answer_line = strtolower($m[1]);
                $answer_idx  = $i;
                break;
            }
        }
        if ($answer_idx === -1) return 'Missing ANSWER: line. Add "ANSWER: A" (or B/C/D) after options.';

        $correct_option = $answer_line;

        // Extract EXPLANATION (optional, after ANSWER line)
        $explanation = '';
        if (isset($body_lines[$answer_idx + 1]) && preg_match('/^EXPLANATION\s*:\s*(.+)/is', implode(' ', array_slice($body_lines, $answer_idx + 1)), $m)) {
            $explanation = trim($m[1]);
        }

        // Lines before ANSWER
        $before_answer = array_slice($body_lines, 0, $answer_idx);

        // Extract options A. B. C. D.
        $options = [];
        $option_indices = [];
        foreach ($before_answer as $i => $l) {
            if (preg_match('/^[A-D][.\)]\s+(.+)/i', $l, $m)) {
                $letter = strtolower($l[0]);
                $options[$letter] = trim($m[1]);
                $option_indices[] = $i;
            }
        }
        if (count($options) !== 4) return 'Need exactly 4 options (A. B. C. D.). Found: ' . count($options);

        $first_option_idx = min($option_indices);

        // Lines before first option = question body
        $question_lines = array_slice($before_answer, 0, $first_option_idx);

        // Detect statements: numbered lines like "1. text" or "1) text"
        $statements   = [];
        $stem_lines   = [];
        $q_type       = 'standard';

        foreach ($question_lines as $l) {
            if (preg_match('/^(\d+)[.)]\s+(.+)/', $l, $m)) {
                $statements[] = trim($m[2]);
            } else {
                $stem_lines[] = $l;
            }
        }

        if (!empty($statements)) $q_type = 'statement';

        $question_text = implode(' ', $stem_lines);
        $question_text = trim($question_text);

        if (empty($question_text)) return 'Missing question stem (the opening question text before statements/options).';

        return [
            'question_type'  => $q_type,
            'subject_id'     => intval($subject_id),
            'topic_id'       => $topic_id,
            'subtopic_id'    => 0,
            'question_text'  => $question_text,
            'statements'     => !empty($statements) ? wp_json_encode(array_values($statements)) : null,
            'question_tags'  => $tags_str,
            'year_asked'     => $year ?: null,
            'option_a'       => $options['a'] ?? '',
            'option_b'       => $options['b'] ?? '',
            'option_c'       => $options['c'] ?? '',
            'option_d'       => $options['d'] ?? '',
            'correct_option' => $correct_option,
            'explanation'    => $explanation,
            'difficulty'     => $difficulty,
            'source'         => 'import',
            'status'         => 'draft',
            'created_by'     => get_current_user_id(),
        ];
    }

    /* ══════════════════════════════════
       FETCH FROM GOOGLE DOC URL
    ══════════════════════════════════ */
    public static function fetch_google_doc($url) {
        // Convert Google Doc URL to plain text export URL
        $text_url = self::get_gdoc_export_url($url);
        if (!$text_url) return ['error' => 'Invalid Google Doc URL. Please share the doc and use the shareable link.'];

        $response = wp_remote_get($text_url, [
            'timeout'     => 30,
            'redirection' => 5,
            'headers'     => ['User-Agent' => 'CIAS-Importer/1.0'],
        ]);

        if (is_wp_error($response)) return ['error' => 'Could not fetch Google Doc: ' . $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return ['error' => "Google Doc returned HTTP $code. Make sure the doc is set to \"Anyone with the link can view\"."];

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) return ['error' => 'Google Doc returned empty content.'];

        // Google exports as plain text — clean up
        $text = self::clean_google_text($body);
        return ['text' => $text, 'source' => 'google_doc'];
    }

    private static function get_gdoc_export_url($url) {
        // Standard Google Docs URL patterns
        // https://docs.google.com/document/d/DOCID/edit
        // https://docs.google.com/document/d/DOCID/view
        if (preg_match('/docs\.google\.com\/document\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return 'https://docs.google.com/document/d/' . $m[1] . '/export?format=txt';
        }
        // Already an export URL
        if (strpos($url, 'export?format=txt') !== false) return $url;
        return null;
    }

    private static function clean_google_text($text) {
        // Remove BOM
        $text = ltrim($text, "\xEF\xBB\xBF");
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Remove excessive blank lines (keep max 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /* ══════════════════════════════════
       PARSE DOCX FILE
    ══════════════════════════════════ */
    public static function parse_docx($file_path) {
        // DOCX is a ZIP archive — extract word/document.xml
        if (!class_exists('ZipArchive')) {
            return ['error' => 'PHP ZipArchive extension not available on this server.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return ['error' => 'Could not open .docx file. Make sure it is a valid Word document.'];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) return ['error' => 'Could not read document content from .docx file.'];

        // Parse XML to plain text preserving paragraph breaks
        $text = self::docx_xml_to_text($xml);
        return ['text' => $text, 'source' => 'docx'];
    }

    private static function docx_xml_to_text($xml) {
        // Remove XML namespaces for easier parsing
        $xml   = preg_replace('/\s+xmlns[^=]*="[^"]*"/', '', $xml);
        $dom   = new DOMDocument();
        @$dom->loadXML($xml);
        $xpath = new DOMXPath($dom);

        $lines = [];
        // Each <w:p> is a paragraph
        $paras = $xpath->query('//w:p');
        foreach ($paras as $para) {
            $line = '';
            // Each <w:r> is a run (text node)
            $runs = $xpath->query('.//w:t', $para);
            foreach ($runs as $run) {
                $line .= $run->nodeValue;
            }
            $lines[] = trim($line);
        }

        // Join paragraphs, collapse multiple blanks
        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /* ══════════════════════════════════
       SAVE PARSED QUESTIONS TO DB
    ══════════════════════════════════ */
    public static function save_questions($questions) {
        global $wpdb;
        $saved  = 0;
        $errors = [];

        foreach ($questions as $i => $q) {
            if (is_string($q)) { $errors[] = "Q" . ($i+1) . ": $q"; continue; }

            $result = $wpdb->insert($wpdb->prefix . 'cias_questions', $q);
            if ($result === false) {
                $errors[] = "Q" . ($i+1) . ": Database error — " . $wpdb->last_error;
            } else {
                $saved++;
            }
        }

        wp_cache_flush();
        return ['saved' => $saved, 'errors' => $errors];
    }
}
