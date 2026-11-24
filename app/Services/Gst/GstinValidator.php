<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\Database;

/**
 * Centralised GSTIN validation (server side; the form's live check calls the
 * same rules through an endpoint).
 *
 * Structure of a GSTIN (15 characters):
 *   1–2   state code (must exist in the GST state master)
 *   3–12  PAN of the holder (TAN for tax-deductor registrations; other formats for
 *         non-resident / OIDAR / UIN registrations — see gst_registration_types.id_basis)
 *   13    entity number for the same PAN in the state: 1–9 then A–Z (never 0)
 *   14    'Z' by default (configurable per registration type)
 *   15    check character: mod-36 checksum over the first 14 characters
 *
 * This validates the FORMAT only. It does not and cannot confirm that a GSTIN is
 * registered or active on the GST portal — no portal integration exists.
 */
final class GstinValidator
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    /** Valid 4th PAN characters (holder type): P person, C company, H HUF, F firm/LLP, A AOP, T trust, B BOI, L local authority, J artificial juridical person, G government. */
    public const PAN_HOLDER_TYPES = 'PCHFATBLJG';

    public function __construct(private readonly Database $db)
    {
    }

    /** Uppercase and remove all whitespace (including non-breaking spaces copied from PDFs). */
    public static function normalize(string $gstin): string
    {
        return strtoupper((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $gstin));
    }

    /** Check character for the first 14 characters (official GSTIN mod-36 algorithm). */
    public static function checksum(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $value = strpos(self::ALPHABET, $first14[$i]);
            if ($value === false) {
                return '?';
            }
            $product = $value * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + ($product % 36);
        }
        return self::ALPHABET[(36 - ($sum % 36)) % 36];
    }

    public static function isPan(string $pan): bool
    {
        return preg_match('/^[A-Z]{3}[' . self::PAN_HOLDER_TYPES . '][A-Z][0-9]{4}[A-Z]$/', $pan) === 1;
    }

    public static function isTan(string $tan): bool
    {
        return preg_match('/^[A-Z]{4}[0-9]{5}[A-Z]$/', $tan) === 1;
    }

    /**
     * Validate a GSTIN for a registration type, optionally against a PAN typed separately.
     *
     * @param array<string, mixed>|null $type row of gst_registration_types (null = treat as PAN-based, 14th char Z)
     * @return array{valid: bool, gstin: string, errors: list<string>, state: ?array<string, mixed>, pan: ?string, entity_number: ?string}
     */
    public function validate(string $raw, ?array $type = null, ?string $pan = null, bool $allowInactiveState = false): array
    {
        $gstin = self::normalize($raw);
        $errors = [];
        $state = null;
        $panPart = null;

        if ($gstin === '') {
            return ['valid' => false, 'gstin' => '', 'errors' => ['Enter the GSTIN.'], 'state' => null, 'pan' => null, 'entity_number' => null];
        }
        if (strlen($gstin) !== 15) {
            $errors[] = 'A GSTIN has exactly 15 characters (you entered ' . strlen($gstin) . ').';
        }
        if (preg_match('/^[0-9A-Z]+$/', $gstin) !== 1) {
            $errors[] = 'A GSTIN may contain only letters A–Z and digits 0–9.';
        }
        if ($errors !== []) {
            return ['valid' => false, 'gstin' => $gstin, 'errors' => $errors, 'state' => null, 'pan' => null, 'entity_number' => null];
        }

        $code = substr($gstin, 0, 2);
        $state = $this->db->first('SELECT * FROM gst_states WHERE code = ?', [$code]);
        if ($state === null || !(int) $state['allows_registration']) {
            $errors[] = "Characters 1–2 ({$code}) are not a valid GST state code.";
            $state = null;
        } elseif (!(int) $state['is_active'] && !$allowInactiveState) {
            $errors[] = "State code {$code} ({$state['name']}) is no longer used for new registrations" . (!empty($state['notes']) ? ': ' . $state['notes'] : '.');
        }

        $basis = (string) ($type['id_basis'] ?? 'pan');
        $panPart = substr($gstin, 2, 10);
        if ($basis === 'pan' && !self::isPan($panPart)) {
            $errors[] = "Characters 3–12 ({$panPart}) must be a valid PAN: 5 letters (the 4th shows the holder type), 4 digits and a letter.";
        } elseif ($basis === 'tan' && !self::isTan($panPart)) {
            $errors[] = "Characters 3–12 ({$panPart}) must be a valid TAN for a tax-deductor registration: 4 letters, 5 digits and a letter.";
        }

        $entity = $gstin[12];
        if ($basis !== 'other' && ($entity === '0' || strpos(self::ALPHABET, $entity) === false)) {
            $errors[] = 'Character 13 (entity number) must be 1–9 or A–Z.';
        }
        $fourteenth = (string) ($type['fourteenth_char'] ?? ($type === null ? 'Z' : ''));
        if ($fourteenth !== '' && $gstin[13] !== $fourteenth) {
            $errors[] = "Character 14 must be \"{$fourteenth}\" for " . ($type['name'] ?? 'this registration type') . " (found \"{$gstin[13]}\").";
        }

        $expected = self::checksum(substr($gstin, 0, 14));
        if ($gstin[14] !== $expected) {
            $errors[] = 'The check character (15th) does not match — the GSTIN has probably been mistyped. Please re-check every character.';
        }

        if ($pan !== null && trim($pan) !== '' && $basis === 'pan') {
            $pan = strtoupper(trim($pan));
            if ($pan !== $panPart) {
                $errors[] = "The PAN entered ({$pan}) does not match characters 3–12 of the GSTIN ({$panPart}).";
            }
        }

        return [
            'valid' => $errors === [],
            'gstin' => $gstin,
            'errors' => $errors,
            'state' => $state,
            'pan' => $basis === 'pan' ? $panPart : null,
            'entity_number' => $entity,
        ];
    }
}
