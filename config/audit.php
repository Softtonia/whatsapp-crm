<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention Policy
    |--------------------------------------------------------------------------
    |
    | Defines the default number of days to retain administrative and security
    | audit logs in the activity_logs table. For legal compliance and security
    | auditing, the minimum allowed period is 30 days.
    |
    | Sensitive investigations (category: security_investigation) and records
    | tagged under legal hold (category: legal_hold) are excluded from automated
    | deletion to prevent tampering or premature evidence destruction.
    |
    */
    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Protected Categories
    |--------------------------------------------------------------------------
    |
    | Categories that are protected against automated pruning.
    |
    */
    'protected_categories' => [
        'security_investigation',
        'legal_hold',
    ],
];
