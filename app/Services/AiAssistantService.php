<?php

namespace App\Services;

use App\Models\AiReplyDraft;
use App\Models\TenantKnowledge;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiAssistantService
{
    public function findApprovedFaq(
        int $tenantId,
        string $question
    ): ?TenantKnowledge {
        $input = $this->normalize($question);

        if ($input === '') {
            return null;
        }

        $items = TenantKnowledge::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->get();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($items as $item) {
            $candidates = array_merge(
                [$item->question],
                $item->keywords ?? []
            );

            foreach ($candidates as $candidate) {
                $candidate = $this->normalize((string) $candidate);

                if ($candidate === '') {
                    continue;
                }

                $score = 0;

                if ($input === $candidate) {
                    $score = 100;
                } elseif (Str::contains($input, $candidate)) {
                    $score = 80;
                } elseif (Str::contains($candidate, $input)) {
                    $score = 60;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $item;
                }
            }
        }

        return $bestScore >= 60 ? $bestMatch : null;
    }

    /**
     * Find active products belonging to this tenant.
     * Never use product information from another tenant.
     */
    public function findMatchingProducts(
        int $tenantId,
        string $question,
        int $limit = 5
    ) {
        $input = $this->normalize($question);

        if ($input === '') {
            return collect();
        }

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $matches = $products->map(function (Product $product) use ($input) {
            $score = 0;

            $name = $this->normalize((string) $product->name);
            $category = $this->normalize((string) $product->category);
            $description = $this->normalize(
                (string) $product->description
            );

            // Exact product name is the strongest match.
            if ($name !== '' && $input === $name) {
                $score = 100;
            } elseif ($name !== '' && Str::contains($input, $name)) {
                $score = 90;
            } elseif ($name !== '' && Str::contains($name, $input)) {
                $score = 75;
            }

            // Match product category.
            if (
                $category !== ''
                && Str::contains($input, $category)
            ) {
                $score = max($score, 65);
            }

            // Match meaningful words from the description.
            if ($description !== '') {
                $words = preg_split(
                    '/[^\p{L}\p{N}]+/u',
                    $description,
                    -1,
                    PREG_SPLIT_NO_EMPTY
                ) ?: [];

                foreach ($words as $word) {
                    if (
                        mb_strlen($word) >= 4
                        && Str::contains($input, $word)
                    ) {
                        $score += 2;
                    }
                }
            }

            return [
                'product' => $product,
                'score' => $score,
            ];
        });

        return $matches
            ->filter(fn (array $item) => $item['score'] >= 60)
            ->sortByDesc('score')
            ->take(max(1, min($limit, 10)))
            ->pluck('product')
            ->values();
    }

    /**
     * Build a reply from current database product details.
     */
    private function buildProductReply(
        int $tenantId,
        string $question
    ): ?string {
        $products = $this->findMatchingProducts(
            $tenantId,
            $question
        );

        if ($products->isEmpty()) {
            return null;
        }

        $lines = [
            'Thank you for your interest! Here are the matching products:',
            '',
        ];

        foreach ($products as $product) {
            $lines[] = 'Product: ' . $product->name;

            if ($product->description) {
                $lines[] = 'Details: ' . $product->description;
            }

            $price = is_numeric($product->price)
                ? number_format((float) $product->price, 2, '.', ',')
                : null;

            if ($price !== null) {
                $lines[] = 'Price: ' . $product->currency . ' ' . $price;
            }

            $stock = (int) $product->stock_quantity;

            $lines[] = $stock > 0
                ? 'Availability: In stock'
                : 'Availability: Currently out of stock';

            if ($product->image_url) {
                $lines[] = 'Product image: ' . $product->image_url;
            }

            $lines[] = '';
        }

        $lines[] = 'Please confirm your required quantity so our team can assist you.';

        return implode("\n", $lines);
    }

    public function createReplyDraft(
        Conversation $conversation,
        WhatsAppMessage $message
    ): array {
        if (
            (int) $message->conversation_id !== (int) $conversation->id
        ) {
            throw new InvalidArgumentException(
                'Message does not belong to this conversation.'
            );
        }

        if (
            (int) $message->conversation?->tenant_id
            !== (int) $conversation->tenant_id
        ) {
            throw new InvalidArgumentException(
                'Message does not belong to this tenant.'
            );
        }

        if ($message->direction !== 'inbound') {
            throw new InvalidArgumentException(
                'Only inbound messages can generate reply drafts.'
            );
        }

        $existingDraft = AiReplyDraft::query()
            ->where('inbound_message_id', $message->id)
            ->first();

        if ($existingDraft) {
            return [
                'draft' => $existingDraft,
                'message' => 'Reply draft already exists.',
            ];
        }

        $body = trim((string) $message->body);

        if ($body === '') {
            return [
                'draft' => null,
                'message' => 'Message has no text. Manual review is required.',
            ];
        }

        $tenantId = (int) $conversation->tenant_id;

        // Check subscription limits before generating reply draft
        try {
            app(SubscriptionUsageService::class)->assertCanUse(
                $tenantId,
                'ai_replies',
                1
            );
        } catch (\RuntimeException $exception) {
            return [
                'draft' => null,
                'message' => $exception->getMessage(),
            ];
        }

        $intent = $this->detectIntent($body);

        $handoffReason = $this->handoffReason(
            $conversation,
            $body,
            $intent
        );

        $faq = $this->findApprovedFaq($tenantId, $body);

        $knowledgeId = $faq?->id;

        // Approved FAQ has precedence over the product fallback.
        if ($faq) {
            $reply = $faq->answer;
            $resultMessage = 'Approved FAQ matched. Agent approval is required.';
        } elseif ($intent === 'product_inquiry') {
            $reply = $this->buildProductReply($tenantId, $body);

            if ($reply !== null) {
                $resultMessage = 'Product details retrieved from the database. Agent approval is required.';
            } else {
                $reply = 'Thank you for your interest. We could not confidently match your message to a product. Please share the product name or category so our team can assist you.';
                $resultMessage = 'No matching product found. Agent review is required.';
            }
        } else {
            $reply = 'Thank you for contacting us. Our team will review your query and assist you.';
            $resultMessage = 'No approved FAQ matched. Fallback reply awaits agent review.';
        }

        $draft = DB::transaction(function () use (
            $conversation,
            $message,
            $knowledgeId,
            $intent,
            $handoffReason,
            $reply
        ) {
            $existing = AiReplyDraft::query()
                ->where('inbound_message_id', $message->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            return AiReplyDraft::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'inbound_message_id' => $message->id,
                'knowledge_id' => $knowledgeId,
                'suggested_reply' => $reply,
                'intent' => $intent,
                'handoff_required' => $handoffReason !== null,
                'handoff_reason' => $handoffReason,
                'status' => 'pending',
            ]);
        });

        if ($handoffReason !== null) {
            $conversation->update([
                'is_human_takeover' => true,
                'status' => 'open',
            ]);
        }

        // Record successful AI reply generation atomically
        app(SubscriptionUsageService::class)->recordUsage(
            $tenantId,
            'ai_replies',
            1
        );

        return [
            'draft' => $draft,
            'message' => $resultMessage,
        ];
    }

    private function detectIntent(string $text): string
    {
        $text = $this->normalize($text);

        if ($this->containsAny($text, [
            'complaint', 'fraud', 'scam', 'refund',
            'angry', 'not working', 'bad service',
            'shikayat', 'शिकायत', 'धोखा', 'रिफंड',
        ])) {
            return 'complaint';
        }

        if ($this->containsAny($text, [
            'price', 'cost', 'buy', 'purchase', 'order',
            'product', 'available', 'availability', 'stock',
            'price list', 'rate', 'costing',
            'कीमत', 'दाम', 'खरीदना', 'उपलब्ध',
            'कितने का', 'रेट',
        ])) {
            return 'product_inquiry';
        }

        if ($this->containsAny($text, [
            'quotation', 'quote', 'demo', 'bulk order',
            'bulk purchase', 'sales', 'interested',
            'business proposal', 'wholesale',
        ])) {
            return 'sales';
        }

        if ($this->containsAny($text, [
            'my name is', 'my phone', 'my email',
            'contact me', 'call me',
            'mera naam', 'मेरा नाम', 'मेरा नंबर',
        ])) {
            return 'customer_details';
        }

        return 'general';
    }

    private function handoffReason(
        Conversation $conversation,
        string $text,
        string $intent
    ): ?string {
        if ($intent === 'complaint') {
            return 'customer_complaint';
        }

        if (Str::length($text) > 1500) {
            return 'complex_query_review';
        }

        $contact = $conversation->contact;

        if ($contact) {
            $highPriorityLead = Lead::query()
                ->where('contact_id', $contact->id)
                ->whereIn('priority', ['high', 'urgent'])
                ->exists();

            if ($highPriorityLead) {
                return 'high_priority_lead';
            }
        }

        return null;
    }

    private function containsAny(
        string $text,
        array $keywords
    ): bool {
        foreach ($keywords as $keyword) {
            if (Str::contains($text, $this->normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return trim(
            preg_replace('/\s+/u', ' ', Str::lower($text)) ?? ''
        );
    }
}
