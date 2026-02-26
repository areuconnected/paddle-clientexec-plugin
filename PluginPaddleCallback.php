<?php
// Load Clientexec Framework
chdir('../../../');
require_once dirname(__FILE__).'/../../../library/front.php';

// Load Required Models
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice.php';
require_once 'modules/clients/models/User.php';
require_once 'modules/billing/models/BillingCycleGateway.php'; 

// Include the main plugin file for signature verification
require_once dirname(__FILE__) . '/PluginPaddle.php';

class PluginPaddleCallback extends PluginCallback
{
    public function processCallback()
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data || !isset($data['event_type'])) {
            return;
        }

        // ====================== SIGNATURE VERIFICATION ======================
        try {
            $paddlePlugin = new PluginPaddle($this->user);
            $secretKey = $paddlePlugin->getVariable('Webhook Secret Key');

            if (!empty($secretKey)) {
                $signatureHeader = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';
                
                if (empty($signatureHeader)) {
                    CE_Lib::log(1, "Paddle Webhook Security: Missing Paddle-Signature header.");
                    header("HTTP/1.1 401 Unauthorized");
                    return;
                }

                $parts = explode(';', $signatureHeader);
                $ts = ''; $h1 = '';
                foreach ($parts as $part) {
                    if (strpos($part, 'ts=') === 0) $ts = substr($part, 3);
                    if (strpos($part, 'h1=') === 0) $h1 = substr($part, 3);
                }

                $signedPayload = $ts . ':' . $json;
                $computedSignature = hash_hmac('sha256', $signedPayload, $secretKey);

                if (!hash_equals($h1, $computedSignature)) {
                    CE_Lib::log(1, "Paddle Webhook Security: Signature mismatch.");
                    header("HTTP/1.1 401 Unauthorized");
                    return;
                }
                CE_Lib::log(4, "Paddle Webhook Security: Signature verified.");
            }
        } catch (Throwable $e) {
            CE_Lib::log(1, "Paddle Webhook Security Error: " . $e->getMessage());
            header("HTTP/1.1 500 Internal Server Error");
            return;
        }

        // ====================== PROCESS EVENTS ======================
        $eventType = $data['event_type'];
        $innerData = $data['data'] ?? [];

        // Handle Payments
        if ($eventType === 'transaction.completed' || $eventType === 'transaction.paid') {
            $invoiceId     = (int)($innerData['custom_data']['invoice_id'] ?? 0);
            $transactionId = $innerData['id'] ?? '';
            $amount        = number_format(($innerData['details']['totals']['total'] ?? 0) / 100, 2, '.', '');

            if ($invoiceId === 0) return;

            try {
                $invoice = new Invoice($invoiceId);
                $user    = new User($invoice->getUserID());

                $cPlugin = new Plugin($invoiceId, 'paddle', $user);
                $cPlugin->m_TransactionID = $transactionId;
                $cPlugin->m_Action        = 'charge';

                $cPlugin->PaymentAccepted($amount, "Paddle TXN: $transactionId", $transactionId);
                CE_Lib::log(4, "Paddle Processing: Applied $amount to Invoice $invoiceId");
            } catch (Throwable $e) {
                CE_Lib::log(1, "Paddle Callback Error: " . $e->getMessage());
            }
        }
        
        // Handle Refunds/Adjustments
        elseif (in_array($eventType, ['adjustment.created', 'adjustment.updated'])) {
            $status        = $innerData['status'] ?? '';
            $adjId         = $innerData['id'] ?? ''; // The Adjustment ID (adj_...)
            $transactionId = $innerData['transaction_id'] ?? '';
            $refundAmount  = round(($innerData['totals']['total'] ?? 0) / 100, 2);

            if (in_array($status, ['approved', 'completed']) && $transactionId) {
                $cPlugin = new Plugin();
                if ($cPlugin->retrieveInvoiceForTransaction($transactionId)) {
                    $invoiceId = $cPlugin->m_ID;
                    $invoice   = new Invoice($invoiceId);
                    $user      = new User($invoice->getUserID());

                    $refundPlugin = new Plugin($invoiceId, 'paddle', $user);
                    $refundPlugin->m_TransactionID = $transactionId;
                    $refundPlugin->m_Action        = 'refund';
                    
                    // FIXED: Re-added Adj ID to the description string
                    $refundPlugin->PaymentRefunded(
                        $refundAmount, 
                        "Paddle Refund (Adj ID: $adjId - $status)", 
                        $adjId
                    );
                }
            }
        }

        header("HTTP/1.1 200 OK");
    }
}
