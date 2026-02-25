<?php
// EXACT SAME LOADING AS OFFICIAL PAYPAL GATEWAY
chdir('../../../');
require_once dirname(__FILE__).'/../../../library/front.php';

require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice.php';
require_once 'modules/clients/models/User.php';

class PluginPaddleCallback extends PluginCallback
{
    public function processCallback()
    {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data || !isset($data['event_type'])) {
            return;
        }

        $eventType = $data['event_type'];
        $innerData = $data['data'] ?? [];

        // ====================== TRANSACTION PAID ======================
        if ($eventType === 'transaction.completed' || $eventType === 'transaction.paid') {
            $invoiceId     = (int)($innerData['custom_data']['invoice_id'] ?? 0);
            $transactionId = $innerData['id'] ?? '';
            $amountInCents = $innerData['details']['totals']['total'] ?? 0;
            $amount        = number_format($amountInCents / 100, 2, '.', '');

            if ($invoiceId === 0) return;

            CE_Lib::log(4, "Paddle Processing: Applying $amount to Invoice $invoiceId");

            try {
                $invoice = new Invoice($invoiceId);
                $user    = new User($invoice->getUserID());

                $cPlugin = new Plugin($invoiceId, 'paddle', $user);
                $cPlugin->m_TransactionID = $transactionId;
                $cPlugin->m_Action        = 'charge';

                $cPlugin->PaymentAccepted($amount, "Paddle TXN: $transactionId", $transactionId);
                CE_Lib::log(4, "Paddle Processing: Payment successfully applied.");
            } catch (Throwable $e) {
                CE_Lib::log(1, "Paddle Callback Crash: " . $e->getMessage());
            }
        }

        // ====================== PADDLE REFUND (FULL OR PARTIAL) ======================
        elseif (in_array($eventType, ['adjustment.created', 'adjustment.updated'])) {
            $adj           = $innerData;
            $status        = $adj['status'] ?? '';
            $adjId         = $adj['id'] ?? '';
            $transactionId = $adj['transaction_id'] ?? '';
            $refundCents   = $adj['totals']['total'] ?? 0;
            $refundAmount  = round($refundCents / 100, 2);

            if (in_array($status, ['approved', 'completed']) && $transactionId && $refundAmount > 0) {
                CE_Lib::log(4, "Paddle Adjustment $status → TXN $transactionId | Adj: $adjId | Amount: $refundAmount");

                // OFFICIAL CLIENTEXEC WAY (same as PayPal gateway)
                $cPlugin = new Plugin();
                $found   = $cPlugin->retrieveInvoiceForTransaction($transactionId);

                if ($found) {
                    $invoiceId = $cPlugin->m_ID;

                    $invoice = new Invoice($invoiceId);
                    $user    = new User($invoice->getUserID());

                    $cPlugin = new Plugin($invoiceId, 'paddle', $user);
                    $cPlugin->m_TransactionID = $transactionId;
                    $cPlugin->m_Action        = 'refund';

                    $cPlugin->PaymentRefunded(
                        $refundAmount,
                        "Paddle Refund (Adj ID: $adjId - $status)",
                        $adjId
                    );

                    CE_Lib::log(4, "✅ Invoice #$invoiceId automatically marked REFUNDED ($refundAmount) via webhook.");
                } else {
                    CE_Lib::log(4, "Paddle Refund webhook: No matching invoice found for TXN $transactionId");
                }
            }
        }
        // ============================================================================

        header("HTTP/1.1 200 OK");
    }
}
