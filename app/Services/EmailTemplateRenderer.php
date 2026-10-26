<?php

namespace App\Services;

class EmailTemplateRenderer
{
    /**
     * Detailed metadata for placeholders including tag syntax, description, and sample usage.
     *
     * @var array<string, array{tag: string, variable: string, label: string, description: string, example: string}>
     */
    protected static array $placeholderDefinitions = [
        'UserName' => [
            'variable' => 'UserName',
            'tag' => '{{UserName}}',
            'label' => 'User Full Name',
            'description' => 'The full name of the user receiving the email.',
            'example' => 'John Doe',
        ],
        'CompanyName' => [
            'variable' => 'CompanyName',
            'tag' => '{{CompanyName}}',
            'label' => 'Company / Application Name',
            'description' => 'The configured organization or application brand name.',
            'example' => 'WhatsApp CRM',
        ],
        'SupportEmail' => [
            'variable' => 'SupportEmail',
            'tag' => '{{SupportEmail}}',
            'label' => 'Support Email',
            'description' => 'The official support contact email address.',
            'example' => 'support@example.com',
        ],
        'Otp' => [
            'variable' => 'Otp',
            'tag' => '{{Otp}}',
            'label' => 'One-Time Password (OTP)',
            'description' => 'The security verification code.',
            'example' => '123456',
        ],
        'ExpiryMinutes' => [
            'variable' => 'ExpiryMinutes',
            'tag' => '{{ExpiryMinutes}}',
            'label' => 'Expiry Time (Minutes)',
            'description' => 'Validity period of the code or link in minutes.',
            'example' => '10',
        ],
        'ResetUrl' => [
            'variable' => 'ResetUrl',
            'tag' => '{{ResetUrl}}',
            'label' => 'Password Reset URL',
            'description' => 'Direct link to the password reset page.',
            'example' => 'https://crm.example.com/reset-password?token=example_token',
        ],
        'AdminName' => [
            'variable' => 'AdminName',
            'tag' => '{{AdminName}}',
            'label' => 'Administrator Name',
            'description' => 'The name of the administrator receiving the email.',
            'example' => 'Super Admin',
        ],
        'Purpose' => [
            'variable' => 'Purpose',
            'tag' => '{{Purpose}}',
            'label' => 'OTP Purpose',
            'description' => 'The purpose of the OTP verification.',
            'example' => 'Password Reset',
        ],
        'name' => [
            'variable' => 'name',
            'tag' => '{{name}}',
            'label' => 'Recipient Name',
            'description' => 'The name of the recipient in campaign context.',
            'example' => 'John Doe',
        ],
        'email' => [
            'variable' => 'email',
            'tag' => '{{email}}',
            'label' => 'Recipient Email',
            'description' => 'The email address of the recipient.',
            'example' => 'user@example.com',
        ],
        'phone' => [
            'variable' => 'phone',
            'tag' => '{{phone}}',
            'label' => 'Recipient Phone',
            'description' => 'The phone number of the recipient.',
            'example' => '+1234567890',
        ],
    ];

    /**
     * Map of allowed placeholders per template key.
     *
     * @var array<string, list<string>>
     */
    protected static array $allowedPlaceholders = [
        'ADMIN_PASSWORD_RESET_OTP' => [
            'AdminName',
            'UserName',
            'Otp',
            'ExpiryMinutes',
            'CompanyName',
            'SupportEmail',
        ],
        'FORGOT_PASSWORD' => [
            'UserName',
            'Otp',
            'ResetUrl',
            'ExpiryMinutes',
            'CompanyName',
            'SupportEmail',
            'name',
            'email',
        ],
        'RESET_PASSWORD' => [
            'UserName',
            'CompanyName',
            'SupportEmail',
            'name',
            'email',
        ],
        'OTP_VERIFICATION' => [
            'UserName',
            'Otp',
            'Purpose',
            'ExpiryMinutes',
            'CompanyName',
            'SupportEmail',
            'name',
            'email',
        ],
    ];

    /**
     * Global placeholders available to all templates.
     *
     * @var list<string>
     */
    protected static array $globalPlaceholders = [
        'CompanyName',
        'SupportEmail',
        'UserName',
        'AdminName',
        'name',
        'email',
        'phone',
    ];

    /**
     * Render the given text by safely replacing allowed placeholders.
     *
     * @param  array<string, string>  $replacements
     */
    public function render(string $text, array $replacements, ?string $templateKey = null): string
    {
        $allowed = $this->getAllowedPlaceholders($templateKey);
        $globalValues = $this->getGlobalReplacements();
        $mergedReplacements = array_merge($globalValues, $replacements);

        $search = [];
        $replace = [];

        foreach ($allowed as $placeholder) {
            $search[] = '{{' . $placeholder . '}}';
            $replace[] = (string) ($mergedReplacements[$placeholder] ?? '');
        }

        return str_replace($search, $replace, $text);
    }

    /**
     * Get preview render using safe demo values.
     *
     * @return array{subject: string, body: string}
     */
    public function preview(string $templateKey, string $subject, string $body): array
    {
        $demoData = $this->getPreviewDemoData($templateKey);

        return [
            'subject' => $this->render($subject, $demoData, $templateKey),
            'body' => $this->render($body, $demoData, $templateKey),
        ];
    }

    /**
     * Get allowed placeholders for a specific template key (or all known placeholders if null).
     *
     * @return list<string>
     */
    public function getAllowedPlaceholders(?string $templateKey = null): array
    {
        if ($templateKey && isset(self::$allowedPlaceholders[$templateKey])) {
            return self::$allowedPlaceholders[$templateKey];
        }

        if ($templateKey === null || trim($templateKey) === '') {
            return array_keys(self::$placeholderDefinitions);
        }

        return self::$globalPlaceholders;
    }

    /**
     * Get allowed placeholders with full descriptions, tags, and usage examples.
     *
     * @return list<array{variable: string, tag: string, label: string, description: string, example: string}>
     */
    public function getPlaceholdersWithMetadata(?string $templateKey = null): array
    {
        $allowed = $this->getAllowedPlaceholders($templateKey);
        $result = [];

        foreach ($allowed as $key) {
            if (isset(self::$placeholderDefinitions[$key])) {
                $result[] = self::$placeholderDefinitions[$key];
            } else {
                $result[] = [
                    'variable' => $key,
                    'tag' => '{{' . $key . '}}',
                    'label' => $key,
                    'description' => "Dynamic value for {$key}",
                    'example' => '',
                ];
            }
        }

        return $result;
    }

    /**
     * Get global values configured for the application.
     *
     * @return array<string, string>
     */
    protected function getGlobalReplacements(): array
    {
        return [
            'CompanyName' => (string) config('app.name', 'WhatsApp CRM'),
            'SupportEmail' => (string) (config('mail.from.address') ?: 'support@example.com'),
        ];
    }

    /**
     * Get safe demo replacement values for previewing.
     *
     * @return array<string, string>
     */
    protected function getPreviewDemoData(string $templateKey): array
    {
        $data = $this->getGlobalReplacements();

        $data['UserName'] = 'Demo User';
        $data['name'] = 'Demo User';
        $data['email'] = 'user@example.com';
        $data['phone'] = '+1234567890';
        $data['Otp'] = '123456';
        $data['ExpiryMinutes'] = '10';
        $data['AdminName'] = 'Demo Admin';
        $data['Purpose'] = 'Password Reset';
        $data['ResetUrl'] = 'https://crm.example.com/reset-password?token=demo_token';

        return $data;
    }
}
