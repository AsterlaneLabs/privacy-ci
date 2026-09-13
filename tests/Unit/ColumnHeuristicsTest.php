<?php

declare(strict_types=1);

namespace PrivacyCI\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivacyCI\Discovery\Heuristics\ColumnHeuristics;

/**
 * The false-positive classes that make a heuristic report unusable at scale:
 * surrogate keys and flags that merely contain a personal-sounding word, and
 * words that are only personal depending on which table they sit on.
 */
final class ColumnHeuristicsTest extends TestCase
{
    private ColumnHeuristics $heuristics;

    protected function setUp(): void
    {
        $this->heuristics = new ColumnHeuristics;
    }

    /** @return array<string, array{string}> */
    public static function surrogateKeys(): array
    {
        return [
            'email_digest_id' => ['email_digest_id'],
            'email_preference_id' => ['email_preference_id'],
            'mobile_alert_id' => ['mobile_alert_id'],
            'sender_id' => ['sender_id'],
            'phone_verification_id' => ['phone_verification_id'],
            'nested_email_template_id' => ['nested_email_template_id'],
        ];
    }

    #[Test]
    #[DataProvider('surrogateKeys')]
    public function a_surrogate_key_is_not_contact_data(string $column): void
    {
        // Real links to the subject arrive as foreign keys, not name matches,
        // so excluding these costs no recall.
        $this->assertNull($this->heuristics->score($column), $column);
    }

    /** @return array<string, array{string}> */
    public static function booleansAndTimestamps(): array
    {
        return [
            'email_sent' => ['email_sent'],
            'email_verified' => ['email_verified'],
            'allowed_to_email' => ['allowed_to_email'],
            'mobile_alert_sent_at' => ['mobile_alert_sent_at'],
        ];
    }

    #[Test]
    #[DataProvider('booleansAndTimestamps')]
    public function a_flag_or_timestamp_is_not_contact_data(string $column): void
    {
        $this->assertNull($this->heuristics->score($column), $column);
    }

    /** @return array<string, array{string}> */
    public static function realIdentifiers(): array
    {
        return [
            'device_id' => ['device_id'],
            'national_id' => ['national_id'],
            'session_id' => ['session_id'],
            'tax_id' => ['tax_id'],
        ];
    }

    #[Test]
    #[DataProvider('realIdentifiers')]
    public function a_genuine_identifier_survives_the_id_suffix_rule(string $column): void
    {
        // The exclusion is applied after matching, so a specific pattern wins.
        $score = $this->heuristics->score($column);

        $this->assertNotNull($score, $column);
        $this->assertSame(ColumnHeuristics::SPECIFIC, $score['tier']);
    }

    /** @return array<string, array{string}> */
    public static function contextualWords(): array
    {
        return [
            'name' => ['name'],
            'description' => ['description'],
            'reason' => ['reason'],
            'message' => ['message'],
            'payload' => ['payload'],
            'city' => ['city'],
        ];
    }

    #[Test]
    #[DataProvider('contextualWords')]
    public function a_word_that_is_only_personal_in_context_is_generic(string $column): void
    {
        // On a lookup table of countries, `name` is "Germany"; on the subject
        // table it is a person. The column alone cannot tell you which, so the
        // table has to decide.
        $this->assertSame(ColumnHeuristics::GENERIC, $this->heuristics->score($column)['tier']);
    }

    /** @return array<string, array{string}> */
    public static function unambiguous(): array
    {
        return [
            'email' => ['email'],
            'iban' => ['iban'],
            'ip_address' => ['ip_address'],
            'first_name' => ['first_name'],
            'street' => ['street'],
            'gender' => ['gender'],
        ];
    }

    #[Test]
    #[DataProvider('unambiguous')]
    public function an_unambiguous_identifier_is_specific_wherever_it_sits(string $column): void
    {
        $score = $this->heuristics->score($column);

        $this->assertSame(ColumnHeuristics::SPECIFIC, $score['tier'], $column);
        $this->assertGreaterThanOrEqual(0.85, $score['confidence'], $column);
    }

    #[Test]
    public function an_email_variant_still_counts_as_contact(): void
    {
        $score = $this->heuristics->score('secondary_email');

        $this->assertSame(ColumnHeuristics::CONTACT, $score['tier']);
    }
}
