<?php

declare(strict_types=1);

namespace PrivacyCI\Discovery\Heuristics;

/**
 * Scores a column name for the likelihood that it holds personal data.
 *
 * These are probabilistic by construction, which is why every result carries a
 * confidence and why heuristic findings may only ever warn, never fail a build
 * The hard case is deliberate: personal data with no foreign key, a
 * subscriber's email, a free-text notes column, is where the "I forgot that
 * existed" moment actually lives.
 */
final class ColumnHeuristics
{
    /** An unambiguous identifier. Personal wherever it sits. */
    public const SPECIFIC = 'specific';

    /** A contact-shaped variant. Usually personal, but structurally ambiguous. */
    public const CONTACT = 'contact';

    /**
     * A word that is personal only in context. On a lookup table of countries,
     * `name` is "Germany"; on the subject table it is a person. The column
     * alone cannot tell you which.
     */
    public const GENERIC = 'generic';

    /**
     * Pattern => [confidence, label, tier]. Ordered most specific first; the
     * first match wins, so `email_verified_at` must be excluded before `email`.
     *
     * @var array<string, array{float, string, string}>
     */
    private const PATTERNS = [
        // Direct identifiers, unambiguous.
        '/^(email|e_mail|email_address)$/' => [0.98, 'email address', self::SPECIFIC],
        '/^(phone|telephone|mobile|phone_number|mobile_number|msisdn)$/' => [0.97, 'phone number', self::SPECIFIC],
        '/^(ssn|social_security(_number)?|national_id|nin)$/' => [0.99, 'national identifier', self::SPECIFIC],
        '/^(passport(_number)?|drivers_licen[cs]e(_number)?)$/' => [0.98, 'government document', self::SPECIFIC],
        '/^(iban|bic|swift|bank_account(_number)?|sort_code|routing_number)$/' => [0.97, 'bank detail', self::SPECIFIC],
        '/^(credit_card|card_number|pan|cc_number|cvv)$/' => [0.99, 'payment card', self::SPECIFIC],
        '/^(tax_id|vat_number|tin)$/' => [0.9, 'tax identifier', self::SPECIFIC],

        // Names.
        '/^(first_name|last_name|surname|given_name|family_name|middle_name|maiden_name)$/' => [0.95, 'personal name', self::SPECIFIC],
        '/^(full_name|display_name|legal_name)$/' => [0.9, 'personal name', self::GENERIC],
        '/^(username|user_name|nickname|handle)$/' => [0.75, 'account handle', self::SPECIFIC],
        '/^name$/' => [0.55, 'possibly a personal name', self::GENERIC],

        // Location and contact.
        '/^(address|street(_address)?|address_line_?\d?|addr\d?)$/' => [0.92, 'postal address', self::SPECIFIC],
        '/^(post(al)?_?code|zip(_?code)?)$/' => [0.7, 'postal code', self::SPECIFIC],
        '/^(latitude|longitude|lat|lng|lon|geo_?point)$/' => [0.8, 'precise location', self::SPECIFIC],
        '/^(city|town|region|county|state)$/' => [0.35, 'coarse location', self::GENERIC],

        // Device and network, personal data under GDPR even when pseudonymous.
        '/^(ip|ip_address|client_ip|remote_addr|author_ip)$/' => [0.9, 'IP address', self::SPECIFIC],
        '/^(device_id|device_identifier|idfa|advertising_id|gaid)$/' => [0.88, 'device identifier', self::SPECIFIC],
        '/^(user_agent|ua_string)$/' => [0.6, 'user agent', self::SPECIFIC],
        '/^(session_id|cookie_id|fingerprint)$/' => [0.7, 'tracking identifier', self::SPECIFIC],

        // Demographics and special categories.
        '/^(dob|date_of_birth|birth_?date|birthday)$/' => [0.95, 'date of birth', self::SPECIFIC],
        '/^(gender|sex|nationality|ethnicity|race|religion)$/' => [0.9, 'special category', self::SPECIFIC],
        '/^(health|medical|diagnosis|blood_type)/' => [0.9, 'health data', self::SPECIFIC],

        // Credentials.
        '/^(password|password_hash|remember_token|api_token|secret|private_key)$/' => [0.85, 'credential', self::SPECIFIC],

        // Prefixed and suffixed variants.
        '/(^|_)(email|phone|mobile)(_|$)/' => [0.85, 'contact detail', self::CONTACT],
        '/^(billing|shipping|delivery|contact|recipient|customer|sender)_/' => [0.7, 'contact detail', self::CONTACT],
        '/(_|^)(first|last|full)_name(_|$)/' => [0.9, 'personal name', self::SPECIFIC],

        // Free text that commonly embeds personal data.
        '/^(notes?|comments?|body|message|description|bio|about|feedback|reason)$/' => [0.3, 'free text may embed personal data', self::GENERIC],
        '/^(payload|metadata|meta|data|attributes|properties|extra)$/' => [0.28, 'unstructured blob may embed personal data', self::GENERIC],
    ];

    /** Never personal data, whatever the name suggests. */
    private const EXCLUDE = [
        '/^email_verified_at$/',
        '/^(created|updated|deleted)_at$/',
        '/_count$/',
        '/^(id|uuid|ulid)$/',
        '/_type$/',
    ];

    /**
     * Structural shapes that disqualify a CONTACT or GENERIC match.
     *
     * Applied after matching rather than before, so `device_id` and `national_id`
     * survive on their own specific patterns while `email_digest_id`, a
     * surrogate key that merely contains the word "email", does not.
     *
     * Real links to the subject arrive as foreign keys, not as name matches, so
     * excluding *_id here costs no recall.
     */
    private const STRUCTURAL = [
        '/_id$/',                                        // surrogate keys
        '/_at$/',                                        // timestamps
        '/_(sent|authenticated|verified|enabled|confirmed|allowed)$/',
        '/^(is|has|can|should|allowed|allow)_/',
    ];

    /**
     * @return array{confidence: float, label: string, tier: string}|null
     */
    public function score(string $column): ?array
    {
        $name = strtolower($column);

        foreach (self::EXCLUDE as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return null;
            }
        }

        foreach (self::PATTERNS as $pattern => [$confidence, $label, $tier]) {
            if (preg_match($pattern, $name) !== 1) {
                continue;
            }

            if ($tier !== self::SPECIFIC && $this->isStructural($name)) {
                return null;
            }

            return ['confidence' => $confidence, 'label' => $label, 'tier' => $tier];
        }

        return null;
    }

    private function isStructural(string $name): bool
    {
        foreach (self::STRUCTURAL as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
