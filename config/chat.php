<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mask Contact Information
    |--------------------------------------------------------------------------
    |
    | When enabled, email addresses, phone numbers and external links found in
    | chat message bodies are replaced with a placeholder before the message is
    | stored and delivered. This keeps conversations on-platform.
    |
    */

    'mask_contact_info' => env('CHAT_MASK_CONTACT_INFO', true),

    'mask_placeholder' => env('CHAT_MASK_PLACEHOLDER', '[hidden]'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    // Max attachment size in kilobytes (default 10 MB).
    'attachment_max_kb' => env('CHAT_ATTACHMENT_MAX_KB', 10240),

    // Allowed attachment mime types.
    'attachment_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],

];
