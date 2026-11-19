<?php
/**
 * UddoktaPay (paymently.io) gateway client.
 *
 * Discovered contract for this merchant (verified against the live API):
 *   create : POST {base}/checkout          body: full_name,email,amount,metadata,
 *                                           redirect_url,return_url,cancel_url
 *                                           -> {"status":true,"message":"Payment Initiated","payment_url":"..."}
 *   verify : POST {base}/verify-payment     body: {"invoice_id":"..."}
 *                                           -> bad id: {"status":false,"message":"Invalid Payment ID"}
 *                                              good id: {"status":true, ... payment data ...}
 *
 * The base URL already ends in /api, so endpoints are appended directly. The API
 * key is sent in the RT-UDDOKTAPAY-API-KEY header.
 *
 * NOTE ON VERIFY: the exact success payload could not be observed without a real
 * completed bank payment, so udtVerifyPayment() parses defensively (amount /
 * transaction id may sit at the top level or under data). The raw response is
 * always stored on the payment row so the first live transaction can be inspected
 * and this parsing tightened if the shape differs.
 */

if (!defined('UDT_BASE_URL')) {
    define('UDT_BASE_URL', 'https://smartercollection.paymently.io/api');
}
if (!defined('UDT_API_KEY')) {
    define('UDT_API_KEY', '6zojGDqFSBos1yzMAJ54PGPbe9M30Cxea7HE0Tr0');
}

if (!function_exists('udtPost')) {
    /**
     * POST JSON to a gateway endpoint.
     * @return array [httpCode:int, decoded:array|null, raw:string]
     */
    function udtPost($path, array $body)
    {
        $url = rtrim(UDT_BASE_URL, '/') . '/' . ltrim($path, '/');
        $json = json_encode($body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'RT-UDDOKTAPAY-API-KEY: ' . UDT_API_KEY,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [0, null, 'cURL error: ' . $err];
        }
        $decoded = json_decode($raw, true);
        return [$code, is_array($decoded) ? $decoded : null, $raw];
    }

    /** Truthy test that accepts both boolean true and "true"/"1"/"success". */
    function udtFlagTrue($v)
    {
        if ($v === true) { return true; }
        if (is_string($v)) {
            $l = strtolower(trim($v));
            return in_array($l, ['true', '1', 'yes', 'success', 'completed', 'paid'], true);
        }
        return false;
    }

    /**
     * Create a payment session.
     * @return array ['ok'=>bool,'payment_url'=>?string,'raw'=>array,'error'=>?string]
     */
    function udtCreatePayment($fullName, $email, $amount, $metadata, $redirectUrl, $returnUrl, $cancelUrl)
    {
        $body = [
            'full_name'    => (string)$fullName,
            'email'        => (string)$email,
            'amount'       => (string)$amount,
            'metadata'     => is_array($metadata) ? $metadata : (object)[],
            'redirect_url' => (string)$redirectUrl,
            'return_url'   => (string)$returnUrl,
            'cancel_url'   => (string)$cancelUrl,
        ];

        list($code, $decoded, $raw) = udtPost('checkout', $body);
        if (!$decoded) {
            return ['ok' => false, 'payment_url' => null, 'raw' => $raw, 'error' => 'Gateway did not return JSON (HTTP ' . $code . ')'];
        }
        $statusOk = udtFlagTrue($decoded['status'] ?? false);
        // payment_url has been seen at the top level and under data on different builds.
        $paymentUrl = $decoded['payment_url']
            ?? ($decoded['data']['payment_url'] ?? null);

        if ($statusOk && $paymentUrl) {
            return ['ok' => true, 'payment_url' => $paymentUrl, 'raw' => $decoded, 'error' => null];
        }
        return [
            'ok' => false,
            'payment_url' => null,
            'raw' => $decoded,
            'error' => $decoded['message'] ?? ('Gateway error (HTTP ' . $code . ')'),
        ];
    }

    /**
     * Verify an invoice id and decide whether it was really paid.
     * @return array ['ok'=>bool,'paid'=>bool,'amount'=>?float,'txn'=>?string,'raw'=>array]
     */
    function udtVerifyPayment($invoiceId)
    {
        list($code, $decoded, $raw) = udtPost('verify-payment', ['invoice_id' => (string)$invoiceId]);
        if (!$decoded) {
            return ['ok' => false, 'paid' => false, 'amount' => null, 'txn' => null, 'raw' => $raw];
        }

        $statusOk = udtFlagTrue($decoded['status'] ?? false);
        $data     = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];

        // Paid if the gateway says status:true AND either an explicit state field
        // says completed/paid, or there is no state field to contradict it.
        $state = $data['status'] ?? ($decoded['payment_status'] ?? null);
        $paid  = false;
        if ($statusOk) {
            if ($state === null) {
                $paid = true;                       // no contradicting state -> trust status:true
            } else {
                $paid = udtFlagTrue($state);
            }
        }

        $amountRaw = $data['amount'] ?? ($decoded['amount'] ?? ($data['payable_amount'] ?? null));
        $amount    = ($amountRaw === null || $amountRaw === '') ? null : (float)$amountRaw;
        $txn       = $data['transaction_id'] ?? ($data['trx_id'] ?? ($decoded['transaction_id'] ?? $invoiceId));

        return ['ok' => $statusOk, 'paid' => $paid, 'amount' => $amount, 'txn' => $txn, 'raw' => $decoded];
    }
}
