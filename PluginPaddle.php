<?php
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
// Added to manually fetch missing data on direct links
require_once 'modules/billing/models/Invoice.php';
require_once 'modules/clients/models/User.php';

class PluginPaddle extends GatewayPlugin
{
    function getVariables()
    {
        return [
            lang("Plugin Name") => ["type" => "hidden", "value" => lang("Paddle")],
            lang('API Key') => [
                'type' => 'password',
                'description' => lang('Paddle API Key (Secret Key starting with sec_ or sub_)'),
                'value' => ''
            ],
            lang('Client-Side Token') => [
                'type' => 'password',
                'description' => lang('Paddle Client-Side Token (starting with test_ or live_)'),
                'value' => ''
            ],
            lang('Webhook Secret Key') => [
                'type' => 'password',
                'description' => lang('Paddle Webhook Secret'),
                'value' => ''
            ],
            lang('Product ID') => [
                'type' => 'text',
                'description' => lang('Generic Product ID (starts with pro_)'),
                'value' => ''
            ],
            lang('Excluded Countries') => [
                'type' => 'text',
                'description' => lang('Comma-separated ISO codes (e.g., BD)'),
                'value' => 'BD'
            ],
            lang('Test Mode') => [
                'type' => 'yesno',
                'description' => lang('Use Paddle Sandbox'),
                'value' => '1'
            ],
            lang("Signup Name") => [
                "type" => "text",
                "description" => lang("Checkout display name"),
                "value" => "Credit Card / PayPal (Paddle)"
            ]
        ];
    }

    function singlepayment($params)
    {
        $invoiceId = $params['invoiceNumber'];

        // ==========================================
        // SAFE DATA EXTRACTION (Fix for Direct Links)
        // ==========================================
        // Direct email links often drop the $params array. We manually load the invoice and user to be safe.
        $ceInvoice  = new Invoice($invoiceId);
        $ceCustomer = new User($ceInvoice->getUserID());

        // If invoice is already paid, abort immediately to prevent Paddle errors
        if ($ceInvoice->status == 1) {
            return "<div style='padding: 15px; margin-top: 15px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; text-align: center;'>" . lang("This invoice has already been paid.") . "</div>";
        }

        // Safely extract required data, falling back to database models if $params is empty
        $clientCountry = !empty($params['userCountry']) ? strtoupper(trim($params['userCountry'])) : strtoupper(trim($ceCustomer->getCountry()));
        $amount        = !empty($params['invoiceTotal']) ? round($params['invoiceTotal'], 2) : round($ceInvoice->getPrice(), 2);
        $currency      = !empty($params['userCurrency']) ? strtoupper($params['userCurrency']) : strtoupper($ceCustomer->getCurrency());
        $email         = !empty($params['userEmail']) ? $params['userEmail'] : $ceCustomer->getEmail();

        // ==========================================

        $excludedStr   = $this->getVariable('Excluded Countries');
        
        if (!empty($excludedStr) && !empty($clientCountry)) {
            $excludedCountries = array_map('trim', explode(',', strtoupper($excludedStr)));
            if (in_array($clientCountry, $excludedCountries)) {
                return "<div style='padding: 15px; margin-top: 15px; background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px; text-align: center;'>" . lang("This payment method is not available in your region.") . "</div>";
            }
        }

        $isTest        = $this->getVariable('Test Mode');
        $apiKey        = $this->getVariable('API Key');
        $clientToken   = $this->getVariable('Client-Side Token');
        $productId     = trim($this->getVariable('Product ID'));

        $apiUrl = ($isTest == 1) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
        $envScript = ($isTest == 1) ? "Paddle.Environment.set('sandbox');" : "";

        // Fallback for invoice URLs if $params drops them
        $successUrl = !empty($params['invoiceviewURLSuccess']) ? $params['invoiceviewURLSuccess'] : $this->settings->get('Clientexec URL') . "/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=" . $invoiceId;

        $payload = [
            'items' => [[
                'quantity' => 1,
                'price' => [
                    'description' => "Invoice #$invoiceId",
                    'name'        => "Invoice #$invoiceId",
                    'product_id'  => $productId,
                    'unit_price'  => [
                        'amount' => (string)round($amount * 100),
                        'currency_code' => $currency
                    ]
                ]
            ]],
            'customer_details' => [
                'email' => $email,
                'address' => ['country_code' => $clientCountry]
            ],
            'custom_data' => ['invoice_id' => (string)$invoiceId]
        ];

        $ch = curl_init("$apiUrl/transactions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result   = json_decode($response, true);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && isset($result['data']['id'])) {
            $transactionId = $result['data']['id'];
            $invoiceUrl = "index.php?fuse=billing&controller=invoice&view=invoice&id=" . $invoiceId;

            echo "
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Secure Checkout</title>
                    <script src='https://cdn.paddle.com/paddle/v2/paddle.js'></script>
                    <style>
                        body { font-family: -apple-system, sans-serif; background: #f8f9fa; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 20px; }
                        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                        h3 { color: #333; }
                    </style>
                    <script type='text/javascript'>
                        window.onload = function() {
                            {$envScript}
                            Paddle.Setup({ 
                                token: '{$clientToken}',
                                eventCallback: function(data) {
                                    if (data.name === 'checkout.closed') window.location.href = '{$invoiceUrl}';
                                }
                            });
                            Paddle.Checkout.open({
                                transactionId: '{$transactionId}',
                                settings: { 
                                    displayMode: 'overlay', 
                                    theme: 'light', 
                                    locale: 'en',
                                    successUrl: '{$successUrl}' 
                                },
                                customer: {
                                    email: '{$email}'
                                }
                            });
                        };
                    </script>
                </head>
                <body>
                    <div class='loader'></div>
                    <h3>Initializing secure payment...</h3>
                    <p>Please do not close this window.</p>
                </body>
                </html>";
            exit;
        }
        return "Paddle Error: " . ($result['error']['detail'] ?? 'Connection failed.');
    }

    function credit($params)
    {
        $transactionId   = $params['invoiceRefundTransactionId'] ?? '';
        $reason          = $params['invoiceRefundReason'] ?: 'Requested by Admin';
        $invoiceId       = (int)($params['invoiceNumber'] ?? ($params['items'][0] ?? 0));
        $requestedAmount = round($params['invoiceRefundAmount'] ?? $params['amount'] ?? 0, 2);

        if (empty($transactionId) || $invoiceId === 0) {
            throw new \Exception("Paddle Refund Error: Missing transaction ID or invoice ID.");
        }

        $isTest = $this->getVariable('Test Mode');
        $apiKey = $this->getVariable('API Key');
        $apiUrl = ($isTest == 1) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';

        $ch = curl_init("$apiUrl/transactions/$transactionId");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Accept: application/json"]);
        $txResponse = curl_exec($ch);
        curl_close($ch);

        $txData = json_decode($txResponse, true);
        $lineItemId = $txData['data']['details']['line_items'][0]['id'] ?? '';
        $originalTotal = round(($txData['data']['details']['totals']['total'] ?? 0) / 100, 2);
        $isPartial = ($requestedAmount > 0 && $requestedAmount < $originalTotal);

        $payload = [
            'action'         => 'refund',
            'transaction_id' => $transactionId,
            'reason'         => $reason,
            'type'           => $isPartial ? 'partial' : 'full'
        ];

        if ($isPartial) {
            $payload['items'] = [['item_id' => $lineItemId, 'amount' => (string)round($requestedAmount * 100)]];
        }

        $ch = curl_init("$apiUrl/adjustments");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $apiKey", "Content-Type: application/json"]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $cPlugin = new Plugin($invoiceId, 'paddle', $this->user);
            $cPlugin->PaymentRefunded($requestedAmount, "Paddle Refund", $result['data']['id']);
            return '';
        }
        throw new \Exception("Refund failed: " . ($result['error']['detail'] ?? 'Unknown error'));
    }
}
