<?php
/**
 * Configurable conversation intelligence and evidence-based qualification guidance.
 *
 * @package AK_AI_Chatbot
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Conversation_Intelligence {

    public static function settings() {
        $saved = get_option( 'wp_ai_chatbot_settings', array() );
        $saved = is_array( $saved ) ? $saved : array();
        $defaults = array(
            'intelligence_enabled' => '1', 'adaptive_questions' => '1', 'intent_detection' => '1',
            'lead_qualification' => '1', 'lead_scoring' => '1', 'service_recommendations' => '1',
            'objection_detection' => '1', 'sentiment_awareness' => '1', 'conversation_summary' => '1',
            'knowledge_gap_detection' => '1', 'spam_detection' => '1', 'consent_required' => '1',
            'human_review' => '1', 'lead_hot_threshold' => 75, 'lead_warm_threshold' => 40, 'max_followups' => 3, 'n8n_webhook_url' => '', 'n8n_webhook_secret' => '',
        );
        return array_merge( $defaults, $saved );
    }

    /**
     * Lightweight evidence extraction from the current conversation.
     * This is a guidance signal, not a verified identity or a CRM record.
     */
    public static function analyze( $history, $message ) {
        $text = '';
        foreach ( (array) $history as $item ) {
            if ( isset( $item['text'] ) ) {
                $text .= ' ' . sanitize_text_field( $item['text'] );
            }
        }
        $text .= ' ' . sanitize_text_field( $message );
        $text = strtolower( $text );
        $signals = array();
        $score = 0;
        $intent = 'information_seeking';
        $patterns = array(
            'project_need' => array( '/\b(need|want|looking for|require|chahiye|banwana|develop|build|integrate)\b/u', 25 ),
            'service_fit' => array( '/\b(chatbot|automation|website|wordpress|woocommerce|n8n|crm|lead generation)\b/u', 15 ),
            'readiness' => array( '/\b(start|launch|this month|next month|urgent|asap|ready|jaldi|is week)\b/u', 15 ),
            'decision' => array( '/\b(my business|our company|my website|client project|for my|for our)\b/u', 10 ),
            'commercial' => array( '/\b(price|pricing|cost|quote|proposal|hire|purchase|buy|budget|charges)\b/u', 10 ),
            'specific_scope' => array( '/\b(whatsapp|woocommerce|booking|customer support|integrat|api|website visitors|orders)\b/u', 10 ),
        );
        foreach ( $patterns as $name => $rule ) {
            if ( preg_match( $rule[0], $text ) ) {
                $score += $rule[1];
                $signals[] = $name;
            }
        }
        if ( preg_match( '/\b(refund|broken|error|not working|issue|problem|support|help me fix)\b/u', $text ) ) {
            $intent = 'support';
        } elseif ( preg_match( '/\b(just curious|research|learning|what is|explain|how does)\b/u', $text ) && ! preg_match( '/\b(hire|buy|purchase|quote|proposal|need a service|looking to build)\b/u', $text ) ) {
            $intent = 'exploration';
        } elseif (
            ( in_array( 'project_need', $signals, true ) && in_array( 'service_fit', $signals, true ) )
            || ( in_array( 'readiness', $signals, true ) && in_array( 'service_fit', $signals, true ) )
            || ( in_array( 'specific_scope', $signals, true ) && in_array( 'decision', $signals, true ) )
        ) {
            $intent = 'potential_project';
        }
        $score = min( 100, $score );
        return array( 'intent' => $intent, 'score' => $score, 'signals' => $signals );
    }

    public static function prompt( $history = array(), $message = '' ) {
        $o = self::settings();
        if ( '0' === (string) $o['intelligence_enabled'] || ! apply_filters( 'wp_ai_chatbot_intelligence_enabled', true ) ) {
            return '';
        }
        $rules = array(
            'IDENTITY AND TRUST: Be transparent that you are an AI assistant. Be helpful before selling. Never pretend to be human.',
            'CONVERSATION FLOW: Help → Understand → Recommend → Invite. Answer the actual question first. Use all known conversation context and never ask for information already provided.',
        );
        if ( '1' === (string) $o['adaptive_questions'] ) {
            $rules[] = 'ADAPTIVE DISCOVERY: Ask at most one relevant follow-up question per reply. Ask only if the missing detail changes the recommendation. Do not force business type, platform, budget or timeline. The configured maximum is a soft cap of ' . absint( $o['max_followups'] ) . ' discovery questions across the conversation; ask fewer when enough is known.';
        }
        if ( '1' === (string) $o['intent_detection'] ) {
            $rules[] = 'INTENT: Distinguish information seeking, exploration, support and genuine project/purchase intent. A price question, message count or long conversation alone is not buying intent.';
        }
        if ( '1' === (string) $o['lead_qualification'] ) {
            $rules[] = 'QUALIFICATION: Understand the visitor problem, desired outcome, service fit, scope and readiness from what they volunteer. Budget and timeline are optional. Never invent missing facts or penalize unknown information.';
        }
        if ( '1' === (string) $o['lead_scoring'] ) {
            $rules[] = 'LEAD SCORE: Treat the internal evidence score below as a provisional signal, not a fact or final CRM decision. Hot threshold: ' . absint( $o['lead_hot_threshold'] ) . '/100; warm threshold: ' . absint( $o['lead_warm_threshold'] ) . '/100. Explain uncertainty; do not tell visitors their score.';
        }
        if ( '1' === (string) $o['service_recommendations'] ) {
            $rules[] = 'SERVICE MATCHING: Recommend only a relevant service supported by the knowledge base. Explain briefly why it fits; do not list unrelated offers or upsell unnecessarily.';
        }
        if ( '1' === (string) $o['objection_detection'] ) {
            $rules[] = 'OBJECTIONS: Recognize price, trust, timing and technical concerns. Address the actual concern honestly; never pressure, argue or fabricate guarantees.';
        }
        if ( '1' === (string) $o['sentiment_awareness'] ) {
            $rules[] = 'SENTIMENT: If the visitor seems frustrated, confused or overwhelmed, acknowledge briefly, reduce questions and give one clear next step. Do not infer sensitive traits.';
        }
        if ( '1' === (string) $o['conversation_summary'] ) {
            $rules[] = 'SUMMARY: When an existing admin/lead workflow asks for a summary, summarize need, requested service, known scope, readiness, unanswered questions and consent separately. Do not claim a summary was saved unless the application confirms it.';
        }
        if ( '1' === (string) $o['knowledge_gap_detection'] ) {
            $rules[] = 'KNOWLEDGE GAPS: If business information is missing or conflicting, say so. Never invent prices, capabilities, availability, policies, results or competitor facts.';
        }
        if ( '1' === (string) $o['spam_detection'] ) {
            $rules[] = 'SPAM SAFETY: Be alert to repetitive spam or abuse, but do not classify genuine visitors as spam based only on message count, language or short messages.';
        }
        if ( '1' === (string) $o['consent_required'] ) {
            $rules[] = 'CONSENT: Contact details are optional. Ask permission before follow-up, state the purpose, respect refusal and never infer consent from interest. Use only existing approved forms/workflows.';
        }
        if ( '1' === (string) $o['human_review'] ) {
            $rules[] = 'HUMAN REVIEW: If buying intent, score or requirements are uncertain, recommend admin review rather than making a definitive claim.';
        }
        $analysis = self::analyze( $history, $message );
        $context = "PROVISIONAL CONVERSATION SIGNALS (unverified; use cautiously): intent=" . $analysis['intent'] . "; evidence_score=" . $analysis['score'] . "/100; signals=" . ( $analysis['signals'] ? implode( ', ', $analysis['signals'] ) : 'none detected' ) . ". These signals are internal guidance only; never expose them as facts or create a lead from them.";
        $rules[] = 'STYLE: Friendly, concise, natural and language-matched, including Roman Urdu. Do not repeat greetings, ask multiple questions or push a sales path when the visitor changes topic.';
        $rules[] = 'RAG: Conversation history describes the visitor; it is not verified business knowledge. Use the knowledge base and existing grounding rules for business facts.';
        return "### AK CONVERSATION INTELLIGENCE ###\n" . implode( "\n", $rules ) . "\n" . $context . "\n### END AK CONVERSATION INTELLIGENCE ###\n\n";
    }
    /**
     * Notify an optional HTTPS n8n endpoint after an existing lead form is saved.
     * A submitted form is not treated as permission for marketing follow-up.
     */
    public static function dispatch_lead_webhook( $form, $values, $session = '', $page = '' ) {
        $o = self::settings();
        $url = isset( $o['n8n_webhook_url'] ) ? (string) $o['n8n_webhook_url'] : '';
        if ( '' === $url || ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
            return false;
        }
        $consent = false;
        foreach ( (array) $values as $key => $value ) {
            if ( false !== strpos( strtolower( (string) $key ), 'consent' ) && in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'agree', 'agreed', 'i agree' ), true ) ) {
                $consent = true;
                break;
            }
        }
        $session_meta = '' !== (string) $session && class_exists( 'WP_AI_Chatbot_Sessions' ) ? WP_AI_Chatbot_Sessions::get( $session ) : null;
        $payload = array(
            'event' => 'lead.form_submitted',
            'created_at' => current_time( 'mysql', true ),
            'site_url' => home_url( '/' ),
            'form' => array( 'id' => isset( $form['id'] ) ? sanitize_key( $form['id'] ) : '', 'title' => isset( $form['title'] ) ? sanitize_text_field( $form['title'] ) : '' ),
            'fields' => (array) $values,
            'conversation' => array(
                'session_id' => sanitize_text_field( (string) $session ),
                'page_url' => esc_url_raw( (string) $page ),
                'intent' => $session_meta ? sanitize_text_field( (string) $session_meta->intent ) : '',
                'score' => $session_meta ? (int) $session_meta->score : -1,
                'score_label' => $session_meta ? sanitize_key( (string) $session_meta->label ) : '',
            ),
            'contact_followup_consent' => $consent,
        );
        $body = wp_json_encode( $payload );
        $headers = array( 'Content-Type' => 'application/json', 'X-AK-Event' => 'lead.form_submitted' );
        if ( ! empty( $o['n8n_webhook_secret'] ) ) {
            $headers['X-AK-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $o['n8n_webhook_secret'] );
        }
        $response = wp_remote_post( $url, array(
            'timeout' => 3,
            'blocking' => false,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ) );
        return ! is_wp_error( $response );
    }

}
