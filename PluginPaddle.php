<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';

/**
 * @package Plugins
 */
class PluginPaddle extends GatewayPlugin
{
    function getVariables()
    {
        return [
            lang("Plugin Name") => [
                "type"          => "hidden",
                "value"         => lang("Paddle")
            ],
            lang('API Key') => [
                'type'          => 'password',
                'description'   => lang('Paddle API Key (Secret Key starting with sec_ or sub_)'),
                'value'         => ''
            ],
            lang('Client-Side Token') => [
                'type'          => 'password',
                'description'   => lang('Paddle Client-Side Token (starting with test_ or live_)'),
                'value'         => ''
            ],
            lang('Webhook Secret Key') => [
                'type'          => 'password',
                'description'   => lang('Paddle Webhook Secret (Found in Developer > Notifications)'),
                'value'         => ''
            ],
            lang('Product ID') => [
                'type'          => 'text',
                'description'   => lang('Required: Create a generic Product in Paddle and enter its ID here (starts with pro_)'),
                'value'         => ''
            ],
            lang('Test Mode') => [
                'type'          => 'yesno',
                'description'   => lang('Select Yes to use Paddle Sandbox environment'),
                'value'         => '1'
            ],
            lang("Signup Name") => [
                "type"          => "text",
                "description"   => lang("Name displayed during checkout"),
                "value"         => "Credit Card / PayPal (Paddle)"
            ]
        ];
    }

    function singlepayment($params)
    {
        $invoiceId     = $params['invoiceNumber'];
        $amount        = round($params['invoiceTotal'], 2);
        $currency      = strtoupper($params['userCurrency']);
        $isTest        = $this->getVariable('Test Mode');
        $apiKey        = $this->getVariable('API Key');
        $clientToken   = $this->getVariable('Client-Side Token');
        $productId     = $this->getVariable('Product ID');
        
        if (empty($productId)) {
            return "Paddle Configuration Error: Product ID is missing.";
        }

        $apiUrl = ($isTest == 1) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
        $envScript = ($isTest == 1) ? "Paddle.Environment.set('sandbox');" : "";

        // 1. Build the Paddle v2 Transaction Payload
        $payload = [
            'items' => [
                [
                    'quantity' => 1,
                    'price' => [
                        'description' => "Invoice #$invoiceId",
                        'name'        => "Invoice #$invoiceId",
                        'product_id'  => $productId,
                        'unit_price'  => [
                            'amount' => (string)round($amount * 100), // Paddle requires cents as a string
                            'currency_code' => $currency
                        ]
                    ]
                ]
            ],
            'custom_data' => [
                'invoice_id' => (string)$invoiceId
            ]
        ];

        // 2. Execute cURL to Create Transaction
        $ch = curl_init("$apiUrl/transactions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result   = json_decode($response, true);
        curl_close($ch);

        // 3. Handle Response & Load Checkout
        if ($httpCode >= 200 && $httpCode < 300 && isset($result['data']['id'])) {
            $transactionId = $result['data']['id'];
            
            $cPlugin = new Plugin($invoiceId, 'paddle', $this->user);
            $cPlugin->PaymentPending("Initiated Paddle Transaction: $transactionId", $transactionId);

            echo "
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Checkout</title>
                    <script src='https://cdn.paddle.com/paddle/v2/paddle.js'></script>
                    <script type='text/javascript'>
                        window.onload = function() {
                            {$envScript}
                            Paddle.Setup({ token: '{$clientToken}' });
                            Paddle.Checkout.open({
                                transactionId: '{$transactionId}',
                                settings: {
                                    displayMode: 'overlay',
                                    theme: 'light',
                                    successUrl: '{$params['invoiceviewURLSuccess']}'
                                }
                            });
                        };
                    </script>
                </head>
                <body style='background:#f4f4f4; text-align:center; padding-top:100px; font-family:sans-serif;'>
                    <h2>Connecting to secure checkout...</h2>
                </body>
                </html>";
            exit;
        }

        // 4. Handle Errors
        $errorMessage = $result['error']['detail'] ?? 'Connection failed.';
        return "Paddle API Error: " . $errorMessage;
    }

        function credit($params)
    {
        $transactionId = $params['invoiceRefundTransactionId'] ?? '';
        $reason        = $params['invoiceRefundReason'] ?: 'Requested by Admin';
        $invoiceId     = (int)($params['invoiceNumber'] ?? 0);

        // Support refund from the invoices list view
        if ($invoiceId === 0 && !empty($params['items'][0])) {
            $invoiceId = (int)$params['items'][0];
        }

        if (empty($transactionId) || $invoiceId === 0) {
            throw new Exception("Paddle Refund Error: Missing transaction ID or invoice ID.");
        }

        CE_Lib::log(4, "Paddle Refund Attempt → Invoice #$invoiceId | TXN: $transactionId | Reason: $reason");

        $payload = [
            'action'         => 'refund',
            'transaction_id' => $transactionId,
            'reason'         => $reason,
            'type'           => 'full'
        ];

        $isTest = $this->getVariable('Test Mode');
        $apiKey = $this->getVariable('API Key');
        $apiUrl = ($isTest == 1) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';

        $ch = curl_init("$apiUrl/adjustments");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            CE_Lib::log(1, "Paddle Refund cURL error: $curlErr");
            throw new Exception("Paddle connection error: $curlErr");
        }

        $result = json_decode($response, true);

        CE_Lib::log(4, "Paddle Refund Raw Response (HTTP $httpCode): " . json_encode($result, JSON_UNESCAPED_SLASHES));

        if ($httpCode >= 200 && $httpCode < 300 && isset($result['data']['id'])) {
            $adjId  = $result['data']['id'];
            $status = $result['data']['status'] ?? 'unknown';

            // Get the exact refunded amount from Paddle response (in cents → dollars)
            $refundAmount = round(($result['data']['totals']['total'] ?? 0) / 100, 2);

            if ($refundAmount <= 0) {
                $refundAmount = 0.00; // fallback, should never happen
            }

            CE_Lib::log(4, "Paddle Refund Accepted → Adjustment: $adjId | Status: $status | Amount: $refundAmount");

            // Mark invoice as refunded
            $cPlugin = new Plugin($invoiceId, 'paddle', $this->user);
            $cPlugin->m_TransactionID = $transactionId;
            $cPlugin->m_Action        = 'refund';

            $cPlugin->PaymentRefunded(
                $refundAmount,
                "Paddle Refund (Adj ID: $adjId - $status)",
                $adjId
            );

            CE_Lib::log(4, "✅ Invoice #$invoiceId marked REFUNDED ($refundAmount)");

                        return '';   // empty return = clean success in Clientexec
        }

        $errorMsg = $result['error']['detail'] 
                 ?? $result['error']['message'] 
                 ?? $response 
                 ?? 'Unknown error';

        CE_Lib::log(1, "Paddle Refund FAILED: $errorMsg");
        throw new Exception("Paddle Refund Error: $errorMsg");
    }
}
