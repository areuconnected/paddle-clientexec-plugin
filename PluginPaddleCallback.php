<?php
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
        $invoiceId = (int)($innerData['custom_data']['invoice_id'] ?? 0);
        $transactionId = $innerData['id'] ?? '';
        
        // Paddle totals are in cents
        $amountInCents = $innerData['details']['totals']['total'] ?? 0;
        $amount = number_format($amountInCents / 100, 2, '.', ''); 

        if ($invoiceId === 0) return;

        if ($eventType === 'transaction.completed' || $eventType === 'transaction.paid') {
            CE_Lib::log(4, "Paddle Processing: Applying $amount to Invoice $invoiceId");
            
            try {
                // Load the Invoice and User to give Clientexec the context it needs 
                // to send emails and trigger the server provisioning automatically.
                $invoice = new Invoice($invoiceId);
                $user = new User($invoice->getUserID());
                
                $cPlugin = new Plugin($invoiceId, 'paddle', $user);
                $cPlugin->m_TransactionID = $transactionId;
                $cPlugin->m_Action = 'charge';
                
                $cPlugin->PaymentAccepted($amount, "Paddle TXN: $transactionId", $transactionId);
                
                CE_Lib::log(4, "Paddle Processing: Payment successfully applied.");
            } catch (Throwable $e) {
                // If anything crashes, catch it and log the exact error!
                CE_Lib::log(1, "Paddle Callback Crash: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            }
        }
                elseif ($eventType === 'adjustment.updated') {
            $adj = $innerData;
            $status        = $adj['status'] ?? '';
            $transactionId = $adj['transaction_id'] ?? '';

            if ($status === 'approved' && $transactionId) {
                CE_Lib::log(4, "Paddle Adjustment APPROVED → TXN $transactionId");

                // Find invoice that used this transaction
                $invoiceId = CE_Lib::dbQuery(
                    "SELECT id FROM invoices WHERE transaction_id = ? LIMIT 1",
                    [$transactionId],
                    'scalar'
                );

                if ($invoiceId) {
                    $invoice = new Invoice($invoiceId);
                    $user    = new User($invoice->getUserID());

                    $cPlugin = new Plugin($invoiceId, 'paddle', $user);
                    $cPlugin->m_TransactionID = $transactionId;
                    $cPlugin->m_Action        = 'refund';

                    // Use the full amount from the original invoice
                    $cPlugin->PaymentRefunded($invoice->getTotal(), "Paddle Refund Approved (Adjustment)", $transactionId);

                    CE_Lib::log(4, "Invoice #$invoiceId automatically marked REFUNDED via webhook.");
                }
            }
        }

        header("HTTP/1.1 200 OK");
    }
}
