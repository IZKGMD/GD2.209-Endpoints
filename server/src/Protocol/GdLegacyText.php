<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdLegacyText
{
    /**
     * Decode client-supplied wire text before persisting it.
     *
     * Geometry Dash sends level descriptions/comments as Base64 on the
     * database protocol. Requests may use URL-safe Base64 without padding.
     */
    public static function encodeDescriptionForStorage(
        string $description,
        int $gameVersion
    ): string {
        /*
         * GD 1.9 uploads the raw level description and the legacy
         * implementation persists it as Base64.
         * GD 2.0+ uploads URL-safe Base64 and the shared storage layer
         * keeps the decoded text.
         */
        if ($gameVersion < 20) {
            return self::base64UrlEncode($description);
        }

        return self::decodeWireText($description);
    }

    /**
     * Encode a stored/plain description for a server response.
     */
    public static function encodeDescriptionForResponse(
        string $description,
        int $gameVersion
    ): string {
        /*
         * 1.9 persists the already encoded representation, while the
         * shared 2.x storage keeps decoded/plain text. Do not guess from
         * the string contents: ordinary text may itself look like Base64.
         */
        if ($gameVersion < 20) {
            return strtr($description, '+/', '-_');
        }

        return self::base64UrlEncode($description);
    }

    /**
     * Decode a client-supplied level description from protocol wire format.
     * Used by updateGJDesc-style requests where the client already sends
     * the encoded representation.
     */
    public static function decodeDescriptionForStorage(
        string $description
    ): string {
        return self::decodeWireText($description);
    }

    public static function encodeDescriptionUpdateForStorage(
        string $description,
        int $gameVersion
    ): string {
        /*
         * updateGJDesc receives the already encoded wire representation.
         * 1.9 keeps that encoded value in storage; 2.0+ normalizes it to
         * the shared plain-text storage format.
         */
        if ($gameVersion < 20) {
            return self::normalizeBase64Url($description);
        }

        return self::decodeWireText($description);
    }

    /**
     * Backward-compatible method name retained for existing callers.
     */
    public static function decodeDescriptionForResponse(
        string $description,
        int $gameVersion
    ): string {
        if ($gameVersion < 20) {
            return self::decodeWireText($description);
        }

        return self::encodeDescriptionForResponse(
            $description,
            $gameVersion
        );
    }

    /**
     * Decode a client-supplied comment.
     */
    public static function decodeComment(
        string $comment,
        int $gameVersion
    ): string {
        /*
         * Geometry Dash 1.9 sends comments as plain protocol text.
         * Cvolton only Base64-encodes level comments when persisting them
         * to the database, then decodes them again for the response.
         *
         * MuchoCore stores comments as plain UTF-8 text, so the wire value
         * is already ready for persistence for every supported client family.
         */
        return $comment;
    }

    /**
     * Encode a stored/plain comment for a server response.
     */
    public static function encodeCommentForResponse(
        string $comment,
        int $gameVersion
    ): string {
        /*
         * MuchoCore normally stores comments as plain UTF-8 text. Older
         * migrated databases may still contain the historical Base64
         * representation used by Cvolton for pre-2.0 comments. Decode that
         * representation on the way out when it is unambiguously valid.
         */
        if ($gameVersion < 20) {
            $decoded = self::decodeWireText($comment);

            if ($decoded !== $comment) {
                return $decoded;
            }
        }

        return $comment;
    }

    private static function normalizeBase64Url(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = self::decodeWireText($value);

        /*
         * If it was valid wire text, re-encode canonically. Otherwise leave
         * the input untouched so malformed legacy payloads are not rewritten.
         */
        if ($decoded !== $value) {
            return self::base64UrlEncode($decoded);
        }

        return strtr($value, '+/', '-_');
    }

    private static function base64UrlEncode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strtr(
            base64_encode($value),
            '+/',
            '-_'
        );
    }

    private static function encodeWireText(
        string $value
    ): string {
        if ($value === '') {
            return '';
        }

        /*
         * Existing installations may contain already encoded values.
         * Keep those intact when they can be decoded to valid UTF-8 text;
         * newly written plain text is encoded exactly once.
         */
        if (self::isWireEncoded($value)) {
            return $value;
        }

        return base64_encode($value);
    }

    private static function decodeWireText(
        string $value
    ): string {
        if ($value === '') {
            return '';
        }

        $normalized = strtr(
            $value,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return $value;
        }

        /*
         * Do not turn arbitrary binary/base64-looking text into stored data.
         * Geometry Dash comments/descriptions are UTF-8 text.
         */
        if (preg_match('//u', $decoded) !== 1) {
            return $value;
        }

        if (
            preg_match(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
                $decoded
            ) === 1
        ) {
            return $value;
        }

        return $decoded;
    }

    private static function isWireEncoded(
        string $value
    ): bool {
        if (
            $value === '' ||
            !preg_match(
                '/^[A-Za-z0-9+\/_-]+={0,2}$/D',
                $value
            )
        ) {
            return false;
        }

        /*
         * Plain UTF-8 text is normally not Base64-aligned. For aligned
         * values, only preserve them as encoded when the decoded result is
         * valid UTF-8 printable text and the input is canonical enough to
         * be recognized as a protocol value.
         */
        $normalized = strtr(
            $value,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return false;
        }

        if (preg_match('//u', $decoded) !== 1) {
            return false;
        }

        return preg_match(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            $decoded
        ) !== 1;
    }
}
