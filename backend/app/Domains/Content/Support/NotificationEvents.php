<?php

namespace App\Domains\Content\Support;

/**
 * The notification events that have editable templates (ADR 10), their
 * variables and the built-in texts used when no template is saved.
 */
class NotificationEvents
{
    public const CHANNELS = ['mail', 'database', 'fcm', 'sms', 'whatsapp'];

    public const LOCALES = ['en', 'ar'];

    /**
     * @return array<string, array{label: string, variables: array<int, string>, defaults: array<string, array{subject: string|null, body: string}>}>
     */
    public static function all(): array
    {
        return [
            'delivery.out_for_delivery' => [
                'label' => 'Out for delivery (with the delivery code)',
                'variables' => ['customer_name', 'order_number', 'shipment_number', 'delivery_code', 'agent_name', 'cod_amount'],
                'defaults' => [
                    'en' => ['subject' => 'Your order {{ order_number }} is on its way', 'body' => 'Hi {{ customer_name }}, {{ agent_name }} is delivering {{ shipment_number }} today. Your delivery code is {{ delivery_code }}: read it to the agent when they arrive.{{ cod_amount }}'],
                    'ar' => ['subject' => 'طلبك {{ order_number }} في الطريق', 'body' => 'مرحباً {{ customer_name }}، يقوم {{ agent_name }} بتوصيل الشحنة {{ shipment_number }} اليوم. رمز التسليم هو {{ delivery_code }}: اقرأه للمندوب عند وصوله.{{ cod_amount }}'],
                ],
            ],
            'delivery.delivered' => [
                'label' => 'Delivered',
                'variables' => ['customer_name', 'order_number', 'shipment_number'],
                'defaults' => [
                    'en' => ['subject' => 'Delivered: {{ shipment_number }}', 'body' => 'Hi {{ customer_name }}, your parcel {{ shipment_number }} of order {{ order_number }} was delivered. Thank you for shopping with Nawy.'],
                    'ar' => ['subject' => 'تم التسليم: {{ shipment_number }}', 'body' => 'مرحباً {{ customer_name }}، تم تسليم الشحنة {{ shipment_number }} من الطلب {{ order_number }}. شكراً لتسوقك مع ناوي.'],
                ],
            ],
            'delivery.failed' => [
                'label' => 'Delivery attempt failed',
                'variables' => ['customer_name', 'order_number', 'shipment_number', 'reason'],
                'defaults' => [
                    'en' => ['subject' => 'We could not deliver {{ shipment_number }}', 'body' => 'Hi {{ customer_name }}, we could not deliver {{ shipment_number }} today ({{ reason }}). We will contact you to arrange a new delivery day.'],
                    'ar' => ['subject' => 'تعذر تسليم {{ shipment_number }}', 'body' => 'مرحباً {{ customer_name }}، تعذر تسليم الشحنة {{ shipment_number }} اليوم ({{ reason }}). سنتواصل معك لتحديد يوم توصيل جديد.'],
                ],
            ],
            'delivery.rescheduled' => [
                'label' => 'Delivery rescheduled',
                'variables' => ['customer_name', 'order_number', 'shipment_number', 'date'],
                'defaults' => [
                    'en' => ['subject' => 'New delivery day for {{ shipment_number }}', 'body' => 'Hi {{ customer_name }}, {{ shipment_number }} will be delivered on {{ date }}.'],
                    'ar' => ['subject' => 'موعد توصيل جديد للشحنة {{ shipment_number }}', 'body' => 'مرحباً {{ customer_name }}، سيتم توصيل الشحنة {{ shipment_number }} يوم {{ date }}.'],
                ],
            ],
            'order.placed' => [
                'label' => 'Order placed',
                'variables' => ['customer_name', 'order_number', 'total'],
                'defaults' => [
                    'en' => ['subject' => 'Order {{ order_number }} received', 'body' => 'Hi {{ customer_name }}, we received your order {{ order_number }} ({{ total }}).'],
                    'ar' => ['subject' => 'تم استلام الطلب {{ order_number }}', 'body' => 'مرحباً {{ customer_name }}، استلمنا طلبك {{ order_number }} ({{ total }}).'],
                ],
            ],
            'live.stream_live' => [
                'label' => 'Live stream started',
                'variables' => ['stream_title', 'stream_url'],
                'defaults' => [
                    'en' => ['subject' => '{{ stream_title }} is live now', 'body' => 'Join {{ stream_title }} now: {{ stream_url }}'],
                    'ar' => ['subject' => '{{ stream_title }} مباشر الآن', 'body' => 'انضم إلى {{ stream_title }} الآن: {{ stream_url }}'],
                ],
            ],
        ];
    }

    /**
     * @return array{label: string, variables: array<int, string>, defaults: array<string, array{subject: string|null, body: string}>}|null
     */
    public static function find(string $event): ?array
    {
        return self::all()[$event] ?? null;
    }
}
