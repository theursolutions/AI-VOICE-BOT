<?php

/**
 * Prebuilt "action tool" library.
 *
 * Each entry is a TEMPLATE for a webhook tool. From the Skills page a user
 * picks one, supplies only the parts that are specific to their own system
 * (the endpoint URL + auth), and it's created as a webhook DataSource and
 * linked to the skill in one step — see SkillWebController::addActionFromTemplate.
 *
 * Templates pre-fill: name, description (when_to_use), HTTP method, and the
 * args schema the LLM extracts. They intentionally do NOT hardcode a URL or
 * credentials, because a tool calls the customer's own backend.
 *
 * Adding a new template = add an array here. No code change required.
 *
 * Fields:
 *   key          unique slug
 *   name         default tool name (user can rename)
 *   category     grouping label for the gallery
 *   icon         lucide icon name
 *   when_to_use  intent description the LLM matches against
 *   method       GET | POST
 *   args         { argName: "what it is" } — extracted by the LLM per turn
 *   auth_type    suggested default: none|bearer|basic|api_key|header
 *   url_hint     placeholder shown in the URL field
 *   note         short helper text shown under the template
 */

return [
    [
        'key'         => 'customer_lookup',
        'name'        => 'Customer lookup',
        'category'    => 'CRM',
        'icon'        => 'user-search',
        'when_to_use' => 'Look up a customer record by email, phone or id to personalise the conversation.',
        'method'      => 'GET',
        'args'        => ['email' => 'customer email', 'phone' => 'customer phone', 'id' => 'customer id'],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-crm.example.com/api/customers',
        'note'        => 'Should return the customer as JSON. The bot reads fields from the response.',
    ],
    [
        'key'         => 'order_status',
        'name'        => 'Order status',
        'category'    => 'E-commerce',
        'icon'        => 'package-search',
        'when_to_use' => 'Check the status / tracking of an order when the caller asks where their order is.',
        'method'      => 'GET',
        'args'        => ['order_id' => 'the order number', 'email' => 'email on the order'],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-shop.example.com/api/orders/status',
        'note'        => 'Returns order state + tracking. Works with Shopify/Woo/custom if it speaks JSON.',
    ],
    [
        'key'         => 'create_order',
        'name'        => 'Create order',
        'category'    => 'E-commerce',
        'icon'        => 'shopping-cart',
        'when_to_use' => 'Place an order when the customer confirms the item(s) and quantity they want to buy.',
        'method'      => 'POST',
        'args'        => [
            'customer_name' => 'customer full name',
            'phone'         => 'customer phone',
            'items'         => 'items to order, each with name + quantity',
            'address'       => 'delivery address (if physical goods)',
            'notes'         => 'any special instructions',
        ],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-shop.example.com/api/orders',
        'note'        => 'POSTs the order to your system. Return an order id/confirmation the bot reads back. Works great as a WhatsApp action.',
    ],
    [
        'key'         => 'book_appointment',
        'name'        => 'Book appointment',
        'category'    => 'Scheduling',
        'icon'        => 'calendar-plus',
        'when_to_use' => 'Book or schedule an appointment / callback for the customer at a requested time.',
        'method'      => 'POST',
        'args'        => [
            'name'     => 'customer name',
            'phone'    => 'customer phone',
            'datetime' => 'requested date & time (ISO 8601)',
            'reason'   => 'reason for the appointment',
        ],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-scheduler.example.com/api/appointments',
        'note'        => 'POSTs the booking. Return a confirmation id the bot can read back.',
    ],
    [
        'key'         => 'check_availability',
        'name'        => 'Check availability',
        'category'    => 'Scheduling',
        'icon'        => 'calendar-check',
        'when_to_use' => 'Check open slots / availability before offering the customer a time.',
        'method'      => 'GET',
        'args'        => ['date' => 'date to check (YYYY-MM-DD)', 'service' => 'service or resource'],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-scheduler.example.com/api/availability',
        'note'        => 'Return a list of available times as JSON.',
    ],
    [
        'key'         => 'create_ticket',
        'name'        => 'Create support ticket',
        'category'    => 'Support',
        'icon'        => 'ticket',
        'when_to_use' => 'Log a support ticket / complaint when the customer reports an issue.',
        'method'      => 'POST',
        'args'        => [
            'subject'  => 'short summary of the issue',
            'body'     => 'full description',
            'email'    => 'customer email',
            'priority' => 'low | normal | high',
        ],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-helpdesk.example.com/api/tickets',
        'note'        => 'Works with Zendesk/Freshdesk/custom. Return the ticket id.',
    ],
    [
        'key'         => 'upsert_contact',
        'name'        => 'Create / update CRM contact',
        'category'    => 'CRM',
        'icon'        => 'user-plus',
        'when_to_use' => 'Save the caller as a lead/contact, or update their details, in the CRM.',
        'method'      => 'POST',
        'args'        => [
            'name'  => 'full name',
            'email' => 'email',
            'phone' => 'phone',
            'notes' => 'anything useful captured in the conversation',
        ],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-crm.example.com/api/contacts',
        'note'        => 'POSTs the contact. Compatible with HubSpot/Salesforce/custom endpoints.',
    ],
    [
        'key'         => 'send_sms',
        'name'        => 'Send SMS / notification',
        'category'    => 'Messaging',
        'icon'        => 'message-square',
        'when_to_use' => 'Send the customer an SMS or notification (confirmation, link, code).',
        'method'      => 'POST',
        'args'        => ['to' => 'destination phone', 'message' => 'text to send'],
        'auth_type'   => 'bearer',
        'url_hint'    => 'https://your-gateway.example.com/api/sms/send',
        'note'        => 'Wrap your SMS provider (Twilio/etc.) behind this endpoint.',
    ],
    [
        'key'         => 'generic_get',
        'name'        => 'Generic GET request',
        'category'    => 'Generic',
        'icon'        => 'plug',
        'when_to_use' => 'Describe when the bot should call this endpoint.',
        'method'      => 'GET',
        'args'        => ['query' => 'a value to send'],
        'auth_type'   => 'none',
        'url_hint'    => 'https://your-api.example.com/endpoint',
        'note'        => 'A blank starting point — edit the name, intent and args after creating.',
    ],
    [
        'key'         => 'generic_post',
        'name'        => 'Generic POST request',
        'category'    => 'Generic',
        'icon'        => 'plug-2',
        'when_to_use' => 'Describe when the bot should call this endpoint.',
        'method'      => 'POST',
        'args'        => ['field' => 'a value to send'],
        'auth_type'   => 'none',
        'url_hint'    => 'https://your-api.example.com/endpoint',
        'note'        => 'A blank starting point — edit the name, intent and args after creating.',
    ],
];
