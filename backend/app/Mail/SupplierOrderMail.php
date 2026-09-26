<?php

namespace App\Mail;

use App\Models\Shop;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A purchase order sent to a supplier on the merchant's behalf. Replies go to the merchant. */
class SupplierOrderMail extends QueuedMailable
{
    /** @param array<int, array{variant_id: int, sku: ?string, name: string, quantity: int}> $items */
    public function __construct(
        public Shop $shop,
        public string $supplierName, // a name, not the model: the email still goes out if the supplier is deleted meanwhile
        public array $items,
        public ?string $note,
        public ?string $replyToAddress,
    ) {}

    public function envelope(): Envelope
    {
        $store = $this->shop->name ?? $this->shop->domain;

        return new Envelope(
            from: new Address(config('mail.from.address'), "{$store} via ".config('shopify.app_name')),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $store)] : [],
            subject: "Purchase order from {$store} · ".now($this->shop->timezone)->toDateString(),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.supplier-order', with: [
            'store' => $this->shop->name ?? $this->shop->domain,
            'totalUnits' => array_sum(array_column($this->items, 'quantity')),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $rows = [['SKU', 'Product', 'Quantity']];
        foreach ($this->items as $item) {
            $rows[] = [$item['sku'] ?? '', $item['name'], $item['quantity']];
        }
        $csv = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }
        rewind($csv);
        $data = stream_get_contents($csv);
        fclose($csv);

        return [
            Attachment::fromData(fn () => $data, 'purchase-order-'.now($this->shop->timezone)->toDateString().'.csv')->withMime('text/csv'),
        ];
    }
}
