<?php
declare(strict_types=1);

namespace Mohan\PaypalRest\Model;

class TransactionService
{
    // REST status -> normalised label consumed by callers
    private const STATUS_MAP = [
        'COMPLETED'          => 'Completed',
        'CAPTURED'           => 'Completed',
        'PENDING'            => 'Pending',
        'CREATED'            => 'Pending',
        'REFUNDED'           => 'Refunded',
        'PARTIALLY_REFUNDED' => 'Refunded',
        'DECLINED'           => 'Denied',
        'DENIED'             => 'Denied',
        'VOIDED'             => 'Voided',
        'EXPIRED'            => 'Expired',
    ];

    public function __construct(
        private readonly Client $client
    ) {}

    /**
     * Resolve a PayPal transaction ID (capture / authorization / refund) to a
     * normalised status record.
     *
     * @return array{status:string, transaction_id:string, amount:string}
     * @throws \RuntimeException when the ID cannot be resolved
     */
    public function fetchByTransactionId(string $transactionId): array
    {
        $token = $this->client->getAccessToken();

        $endpoints = [
            '/v2/payments/captures/'       . $transactionId,
            '/v2/payments/authorizations/' . $transactionId,
            '/v2/payments/refunds/'        . $transactionId,
        ];

        foreach ($endpoints as $path) {
            [, $data] = $this->client->get($path, $token);
            if ($data !== null) {
                return $this->normalise($data, $transactionId);
            }
        }

        throw new \RuntimeException(sprintf(
            'Transaction %s not found in PayPal captures, authorizations, or refunds.',
            $transactionId
        ));
    }

    /** @param array<string,mixed> $data */
    private function normalise(array $data, string $fallbackId): array
    {
        $restStatus = strtoupper((string)($data['status'] ?? ''));

        $amount = '';
        if (isset($data['amount']['currency_code'], $data['amount']['value'])) {
            $amount = $data['amount']['currency_code'] . ' ' . $data['amount']['value'];
        }

        return [
            'status'         => self::STATUS_MAP[$restStatus] ?? ($data['status'] ?? 'N/A'),
            'transaction_id' => $data['id'] ?? $fallbackId,
            'amount'         => $amount,
        ];
    }
}
