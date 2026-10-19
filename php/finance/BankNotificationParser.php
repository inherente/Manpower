<?php
// finance/BankNotificationParser.php

class BankNotificationParser {

    /**
     * Main entry point to parse email header & body into structured transaction data.
     */
    public static function parse(string $from, string $subject, string $body): ?array {
        // Strip HTML tags and normalize whitespace for clean regex matching
        $cleanBody = self::normalizeText($body);
        
        $bankName = self::detectBank($from, $subject, $cleanBody);
        $transactionType = self::detectTransactionType($subject, $cleanBody);
        $amount = self::extractAmount($cleanBody);
        $currency = self::extractCurrency($cleanBody);
        $accountLastFour = self::extractAccountLastFour($cleanBody);
        $merchant = self::extractMerchantOrPayee($cleanBody);
        $timestamp = self::extractTimestamp($cleanBody);

        // New fields
        $operationDesc = self::extractOperation($cleanBody);
        $channel = self::extractChannel($cleanBody);
        $authCode = self::extractAuthorizationCode($cleanBody);

        // Fail-safe: Require at least an amount and a bank name
        if ($amount === null || $bankName === 'Unknown') {
            return null;
        }

        return [
            'bank_name'             => $bankName,
            'transaction_type'      => $transactionType,
            'amount'                => $amount,
            'currency'              => $currency,
            'merchant_or_payee'     => $merchant ?? $operationDesc,
            'account_last_four'     => $accountLastFour,
            'transaction_timestamp' => $timestamp,
            'operation_desc'        => $operationDesc,
            'channel'               => $channel,
            'authorization_code'    => $authCode
        ];
    }

    /**
     * Normalizes HTML/Plaintext into clean, single-spaced plain text.
     */
    private static function normalizeText(string $text): string {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Extracts Operation Description (e.g., "COMPRA EN MIRAI TEC")
     */
    public static function extractOperation(string $body): ?string {
        if (preg_match('/Operaci[oó]n\s*:\s*([^:\n\r]+?)(?=\s*(?:Fecha|Importe|Cuenta|Canal|Movimiento|\$|$))/i', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extracts Channel of Operation (e.g., "TPV(COMPRA COMERCIO)")
     */
    public static function extractChannel(string $body): ?string {
        if (preg_match('/Canal\s+de\s+operaci[oó]n\s*:\s*([^:\n\r]+?)(?=\s*(?:Fecha|Importe|Cuenta|Operaci[oó]n|Movimiento|\$|$))/i', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extracts Authorization Code (e.g., "000916")
     */
    public static function extractAuthorizationCode(string $body): ?string {
        if (preg_match('/(?:autorizaci[oó]n|movimiento\s+en\s+su\s+cuenta\/autorizaci[oó]n)\s*:\s*(\d+)/i', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Identifies financial institution.
     */
    private static function detectBank(string $from, string $subject, string $body): string {
        $search = strtolower($from . ' ' . $subject . ' ' . $body);

        if (strpos($search, 'bbva') !== false) return 'BBVA';
        if (strpos($search, 'banorte') !== false) return 'Banorte';
        if (strpos($search, 'santander') !== false) return 'Santander';
        if (strpos($search, 'citibanamex') !== false || strpos($search, 'banamex') !== false) return 'Citibanamex';
        if (strpos($search, 'hsbc') !== false) return 'HSBC';
        if (strpos($search, 'nu.com.mx') !== false || strpos($search, 'nu bank') !== false || strpos($search, 'nubank') !== false) return 'Nu';

        return 'Unknown';
    }

    /**
     * Categorizes action (Compra, Transferencia, Retiro, Abono, Cargo).
     */
    private static function detectTransactionType(string $subject, string $body): string {
        $text = strtolower($subject . ' ' . $body);

        if (preg_match('/(compra|pago en|establecimiento)/i', $text)) return 'Compra';
        if (preg_match('/(transferencia|spei|envio)/i', $text)) return 'Transferencia';
        if (preg_match('/(retiro|cajero)/i', $text)) return 'Retiro';
        if (preg_match('/(deposito|abono|recibiste)/i', $text)) return 'Abono';
        if (preg_match('/(cargo|comision)/i', $text)) return 'Cargo';

        return 'Otro';
    }

    /**
     * Extracts numerical monetary value.
     */
    private static function extractAmount(string $body): ?float {
        if (preg_match('/Importe\s*:\s*\$\s*([\d,]+(?:\.\d{2})?)/i', $body, $matches)) {
            return (float)str_replace(',', '', $matches[1]);
        }

        if (preg_match('/\$\s*([\d,]+(?:\.\d{2})?)/i', $body, $matches)) {
            return (float)str_replace(',', '', $matches[1]);
        }

        return null;
    }

    /**
     * Detects currency indicator.
     */
    private static function extractCurrency(string $body): string {
        if (preg_match('/USD|Dólares/i', $body)) return 'USD';
        if (preg_match('/EUR|Euros/i', $body)) return 'EUR';
        return 'MXN';
    }

    /**
     * Extracts account or card last 4 digits.
     */
    private static function extractAccountLastFour(string $body): ?string {
        if (preg_match('/(?:cuenta|tarjeta|\*+)\s*\*+(\d{4})/i', $body, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(?:terminaci[oó]n|cuenta|caja|\*{2,4})\s*[:*]?\s*(\d{4})/i', $body, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extracts merchant, payee, or concept name.
     */
    private static function extractMerchantOrPayee(string $body): ?string {
        if (preg_match('/COMPRA\s+EN\s+([^:\n\r]+?)(?=\s*(?:Fecha|Importe|Cuenta|Canal|Movimiento|\$|$))/i', $body, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/(?:en|comercio|establecimiento|beneficiario|concepto):\s*([A-Za-z0-9\s\.\*]{3,30}?)(?=\s*(?:el|fecha|monto|hora|\.|\$|$))/i', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extracts transaction date/time string.
     */
    private static function extractTimestamp(string $body): string {
        // Matches "09/Oct 19:40:08" or "09/Oct/2026"
        if (preg_match('/(\d{2}\/[A-Za-z]{3}(?:\/\d{4})?\s+\d{2}:\d{2}:\d{2})/', $body, $matches)) {
            $rawDate = $matches[1];
            // If year is omitted, append current year
            if (!preg_match('/\d{4}/', $rawDate)) {
                $rawDate .= '/' . date('Y');
            }
            $time = strtotime(str_replace('/', ' ', $rawDate));
            if ($time !== false) {
                return date('Y-m-d H:i:s', $time);
            }
        }

        if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4}\s*(?:\d{2}:\d{2}(?::\d{2})?)?)/', $body, $matches)) {
            $rawDate = str_replace('/', '-', $matches[1]);
            $time = strtotime($rawDate);
            if ($time !== false) {
                return date('Y-m-d H:i:s', $time);
            }
        }
        
        return date('Y-m-d H:i:s');
    }
}
?> 